<?php

namespace App\Services;

use App\Models\ProviderPointsLedger;
use App\Models\ProviderPointsWallet;
use App\Models\TrafficUsageHourly;
use App\Models\UsageLedger;
use App\Models\UserPointsLedger;
use App\Models\UserPointsWallet;
use App\Support\Decimal;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * 用量计费服务（**展示 / 调试用**）。
 *
 * 正式环境中，流量上报与计费的触发者是服务商节点上报链路（hysteria-node-agent）
 * 与平台侧的计费任务；本服务让用户面板可以「自助跑一次计费」来演示与核对账单，
 * 也可以在本地开发时补数据。
 *
 * 关键性质（与生产链路完全一致）：
 *  1. 按流量发生时间回溯 binding_id 与 pricing_rule_id，不用当前值；
 *  2. usage_ledger.idempotency_key 唯一 → 同一条小时桶不可能被计费两次；
 *  3. 用户扣费、服务商记账、账单明细在同一个事务内完成；
 *  4. 账本不可变：修正只能红冲（revert()）。
 */
class UsageBillingService
{
    public function __construct(
        private readonly PricingResolver $resolver,
    ) {
    }

    /**
     * 给某个用户跑一批计费。
     *
     * @return array{billed:int, failed:int, skipped:int, points:string}
     */
    public function billUser(int $userId, ?DateTimeInterface $from = null, ?DateTimeInterface $to = null, int $limit = 500): array
    {
        $buckets = TrafficUsageHourly::query()
            ->ofUser($userId)
            ->pending()
            ->where('period_end', '<=', now()->subMinutes(5))   // 桶必须已封口，避免算到一半又来流量
            ->when($from, fn ($q) => $q->where('period_start', '>=', $from))
            ->when($to, fn ($q) => $q->where('period_start', '<', $to))
            ->orderBy('period_start')
            ->limit($limit)
            ->get();

        $result = ['billed' => 0, 'failed' => 0, 'skipped' => 0, 'points' => '0'];

        foreach ($buckets as $bucket) {
            $ledger = $this->billBucket($bucket);

            if ($ledger === null) {
                $result['failed']++;

                continue;
            }

            $result['billed']++;
            $result['points'] = Decimal::add($result['points'], $ledger->user_points_amount);
        }

        return $result;
    }

    /**
     * 对单个小时桶计费。
     *
     * @return UsageLedger|null 已计费返回账单；无法计费返回 null（并把桶标记为 failed）
     */
    public function billBucket(TrafficUsageHourly $bucket): ?UsageLedger
    {
        return DB::transaction(function () use ($bucket) {
            /** @var TrafficUsageHourly $locked */
            $locked = TrafficUsageHourly::query()->whereKey($bucket->getKey())->lockForUpdate()->first();

            if (! $locked || $locked->billed_status === TrafficUsageHourly::STATUS_BILLED) {
                return null;   // 已被其它 worker 计费，直接跳过（幂等）
            }

            // ① 按「桶起点」回溯绑定，必须与上报时落库的 binding_id 一致
            $binding = $this->resolver->bindingFor($locked->user_id, $locked->period_start);

            if (! $binding || $binding->id !== $locked->binding_id) {
                $locked->update([
                    'billed_status' => TrafficUsageHourly::STATUS_FAILED,
                    'billed_at' => now(),
                ]);

                return null;
            }

            // ② 按「桶起点」解析定价（节点价优先）
            $rule = $this->resolver->ruleFor($locked->provider_id, $locked->node_id, $locked->period_start);

            if (! $rule) {
                $locked->update([
                    'billed_status' => TrafficUsageHourly::STATUS_FAILED,
                    'billed_at' => now(),
                ]);

                return null;
            }

            // ③ 抽成取「桶起点」的结算条款快照
            $term = $this->resolver->termFor($locked->provider_id, $locked->period_start);

            $calc = $this->resolver->calculate(
                (int) $locked->upload_bytes,
                (int) $locked->download_bytes,
                $rule,
                $term,
            );

            // ④ 钱包扣减（行锁 + bcmath，绝不用 float）
            $userWallet = UserPointsWallet::query()->where('user_id', $locked->user_id)->lockForUpdate()->first();

            if (! $userWallet) {
                throw new RuntimeException("用户 {$locked->user_id} 缺少 Points 钱包记录");
            }

            $newUserBalance = Decimal::sub($userWallet->balance, $calc['user_points']);

            if (Decimal::isNegative($newUserBalance)) {
                // 余额不足：标记失败并保留现场，由平台侧走欠费/红冲流程，不做静默透支
                $locked->update([
                    'billed_status' => TrafficUsageHourly::STATUS_FAILED,
                    'billed_at' => now(),
                ]);

                return null;
            }

            // ⑤ 先落账单明细（此时账本 id 还没有），拿到 $ulId 作为业务引用键
            $ledgerNo = sprintf('UL%s-%d-%d', $locked->period_start->format('YmdH'), $locked->binding_id, $locked->node_id);

            $usageLedger = UsageLedger::create([
                'ledger_no' => $ledgerNo,
                'user_id' => $locked->user_id,
                'provider_id' => $locked->provider_id,
                'node_id' => $locked->node_id,
                'binding_id' => $locked->binding_id,
                'pricing_rule_id' => $rule->id,
                'period_start' => $locked->period_start,
                'period_end' => $locked->period_end,
                'upload_bytes' => $locked->upload_bytes,
                'download_bytes' => $locked->download_bytes,
                'billable_bytes' => $calc['billable_bytes'],
                'billable_gb' => $calc['billable_gb'],
                'points_per_gb' => $rule->points_per_gb,      // 费率快照
                'upload_ratio' => $rule->upload_ratio,        // 系数快照
                'download_ratio' => $rule->download_ratio,
                'raw_points_amount' => $calc['raw_points'],
                'user_points_amount' => $calc['user_points'],
                'platform_commission_rate' => $calc['commission_rate'],
                'platform_points_amount' => $calc['platform_points'],
                'provider_points_amount' => $calc['provider_points'],
                'status' => UsageLedger::STATUS_CHARGED,
                'idempotency_key' => hash(
                    'sha256',
                    implode('|', [$locked->binding_id, $locked->node_id, $locked->period_start, $locked->period_end])
                ),
                'billed_at' => now(),
            ]);

            // ⑥ 用户扣费分录（debit）
            $userLedger = UserPointsLedger::create([
                'user_id' => $locked->user_id,
                'biz_type' => UserPointsLedger::BIZ_USAGE,
                'direction' => UserPointsLedger::DIRECTION_DEBIT,
                'amount' => $calc['user_points'],
                'balance_after' => $newUserBalance,
                'biz_ref_type' => 'usage_ledger',
                'biz_ref_id' => $usageLedger->id,
                'idempotency_key' => 'usage:'.$usageLedger->id,
                'remark' => sprintf('流量计费 %s ~ %s', $locked->period_start, $locked->period_end),
            ]);

            $userWallet->update([
                'balance' => $newUserBalance,
                'total_consumed' => Decimal::add($userWallet->total_consumed, $calc['user_points']),
                'last_ledger_id' => $userLedger->id,
                'version' => $userWallet->version + 1,
            ]);

            // ⑦ 服务商应得分录（credit）
            $providerWallet = ProviderPointsWallet::firstOrCreate(['provider_id' => $locked->provider_id]);
            $providerWallet = ProviderPointsWallet::query()->where('provider_id', $locked->provider_id)->lockForUpdate()->first();
            $newProviderBalance = Decimal::add($providerWallet->balance, $calc['provider_points']);

            $providerLedger = ProviderPointsLedger::create([
                'provider_id' => $locked->provider_id,
                'biz_type' => 'usage_earning',
                'direction' => 'credit',
                'amount' => $calc['provider_points'],
                'balance_after' => $newProviderBalance,
                'biz_ref_type' => 'usage_ledger',
                'biz_ref_id' => $usageLedger->id,
                'idempotency_key' => 'earning:'.$usageLedger->id,
                'remark' => '流量计费应得',
            ]);

            $providerWallet->update([
                'balance' => $newProviderBalance,
                'total_earned' => Decimal::add($providerWallet->total_earned, $calc['provider_points']),
                'last_ledger_id' => $providerLedger->id,
                'version' => $providerWallet->version + 1,
            ]);

            // ⑧ 回填账本 id + 标记桶已计费
            $usageLedger->update([
                'user_ledger_id' => $userLedger->id,
                'provider_ledger_id' => $providerLedger->id,
            ]);

            $locked->update([
                'billed_status' => TrafficUsageHourly::STATUS_BILLED,
                'usage_ledger_id' => $usageLedger->id,
                'billed_at' => now(),
            ]);

            return $usageLedger;
        });
    }

    /**
     * 红冲一条账单（账本不可变，修正只能新增反向分录）。
     */
    public function revert(UsageLedger $usageLedger, string $reason = '管理员红冲'): void
    {
        DB::transaction(function () use ($usageLedger, $reason) {
            /** @var UsageLedger $locked */
            $locked = UsageLedger::query()->whereKey($usageLedger->getKey())->lockForUpdate()->first();

            if ($locked->status !== UsageLedger::STATUS_CHARGED) {
                return;
            }

            // 用户回补
            $userWallet = UserPointsWallet::query()->where('user_id', $locked->user_id)->lockForUpdate()->first();
            $balance = Decimal::add($userWallet->balance, $locked->user_points_amount);

            $userLedger = UserPointsLedger::create([
                'user_id' => $locked->user_id,
                'biz_type' => UserPointsLedger::BIZ_REVERSAL,
                'direction' => UserPointsLedger::DIRECTION_CREDIT,
                'amount' => $locked->user_points_amount,
                'balance_after' => $balance,
                'biz_ref_type' => 'usage_ledger',
                'biz_ref_id' => $locked->id,
                'reversal_of_id' => $locked->user_ledger_id,
                'idempotency_key' => 'revert:ul:'.$locked->id.':user',
                'remark' => $reason,
            ]);

            $userWallet->update([
                'balance' => $balance,
                'last_ledger_id' => $userLedger->id,
                'version' => $userWallet->version + 1,
            ]);

            // 服务商扣回
            $providerWallet = ProviderPointsWallet::query()->where('provider_id', $locked->provider_id)->lockForUpdate()->first();
            $providerBalance = Decimal::sub($providerWallet->balance, $locked->provider_points_amount);

            $providerLedger = ProviderPointsLedger::create([
                'provider_id' => $locked->provider_id,
                'biz_type' => 'reversal',
                'direction' => 'debit',
                'amount' => $locked->provider_points_amount,
                'balance_after' => $providerBalance,
                'biz_ref_type' => 'usage_ledger',
                'biz_ref_id' => $locked->id,
                'reversal_of_id' => $locked->provider_ledger_id,
                'idempotency_key' => 'revert:ul:'.$locked->id.':provider',
                'remark' => $reason,
            ]);

            $providerWallet->update([
                'balance' => $providerBalance,
                'version' => $providerWallet->version + 1,
            ]);

            $locked->update(['status' => UsageLedger::STATUS_REVERSED]);

            // 桶回到待计费，允许重算
            TrafficUsageHourly::query()
                ->where('usage_ledger_id', $locked->id)
                ->update(['billed_status' => TrafficUsageHourly::STATUS_PENDING, 'usage_ledger_id' => null]);
        });
    }
}
