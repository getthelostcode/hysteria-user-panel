<?php

namespace App\Actions;

use App\Models\ProviderPointsLedger;
use App\Models\ProviderPointsWallet;
use App\Models\ProviderSettlement;
use App\Models\ProviderSettlementItem;
use App\Models\UsageLedger;
use App\Services\PricingResolver;
use App\Support\DbTime;
use App\Support\Decimal;
use DateTimeInterface;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * 服务商发起结算。
 *
 * 一次结算必须在**同一个事务**里完成 6 件事（少一件就会账实不符）：
 *   1. 汇总 provider 在该区间内「已计费、未红冲、未归属结算单、已过冻结期」的明细；
 *   2. 校验起结门槛 min_payout_points 与钱包可用余额（balance - frozen）；
 *   3. 按条款快照算平台抽成展示额、打款手续费、税费、净额、应付法币；
 *   4. 写 provider_settlements + provider_settlement_items（含 item_count 对账字段）；
 *   5. 回写 usage_ledger.settlement_id（配合 uk_psi_usage 保证「一条明细只结一次」）；
 *   6. 写 provider_points_ledger(debit, biz_type=settlement) 并扣减 provider_points_wallets。
 *
 * 关于抽成的口径（重要，容易算错）：
 *   计费时已经按「流量发生时刻」的条款把平台抽成扣掉了 ——
 *   usage_ledger.provider_points_amount = user_points_amount - platform_points_amount。
 *   所以结算时 **不能再按 commission_rate 抽一次**，否则等于抽两遍。
 *   本实现里：
 *     points_amount    = Σ provider_points_amount（服务商毛额，已扣平台抽成）
 *     commission_points= Σ platform_points_amount（展示用，平台侧已得，不参与扣减）
 *     net_points       = points_amount - 打款手续费 - 税费
 *     fiat_amount      = net_points × points_to_fiat_rate（条款快照汇率）
 */
class RequestSettlementAction
{
    public function __construct(
        private readonly PricingResolver $resolver,
    ) {
    }

    public function execute(
        int $providerId,
        DateTimeInterface|string $periodStart,
        DateTimeInterface|string $periodEnd,
        ?int $operatorId = null,
    ): ProviderSettlement {
        $startSql = DbTime::sql($periodStart);
        $endSql = DbTime::sql($periodEnd);

        if (strtotime($endSql) <= strtotime($startSql)) {
            throw ValidationException::withMessages(['period_end' => '结算区间终点必须晚于起点。']);
        }

        return DB::transaction(function () use ($providerId, $periodStart, $periodEnd, $startSql, $endSql, $operatorId) {
            // ① 条款：取「区间终点时刻」生效的版本（与计费同一套时间点口径）
            $term = $this->resolver->termFor($providerId, $periodEnd);

            if (! $term) {
                throw ValidationException::withMessages(['term' => '该服务商没有生效中的结算条款，无法发起结算。']);
            }

            // 冻结期：流量发生（桶封口）到可结算之间必须已过 hold_days
            $eligibleUntil = DbTime::sql(now()->subDays((int) $term->hold_days));

            // ② 汇总可结算明细（行锁，防并发双结）
            $rows = UsageLedger::query()
                ->where('provider_id', $providerId)
                ->where('status', UsageLedger::STATUS_CHARGED)
                ->where('is_reversal', 0)
                ->whereNull('settlement_id')
                ->where('period_start', '>=', $startSql)
                ->where('period_start', '<', $endSql)
                ->where('period_end', '<=', $eligibleUntil)
                ->lockForUpdate()
                ->get();

            if ($rows->isEmpty()) {
                throw ValidationException::withMessages([
                    'period_start' => '该区间没有可结算的计费明细（可能已结算、未计费或仍在冻结期内）。',
                ]);
            }

            $grossPoints = '0';
            $platformPoints = '0';

            foreach ($rows as $row) {
                $grossPoints = Decimal::add($grossPoints, (string) $row->provider_points_amount);
                $platformPoints = Decimal::add($platformPoints, (string) $row->platform_points_amount);
            }

            // ③ 起结门槛
            if (Decimal::lt($grossPoints, (string) $term->min_payout_points)) {
                throw ValidationException::withMessages([
                    'period_start' => sprintf(
                        '可结算 %s Points 未达到起结门槛 %s Points。',
                        Decimal::group($grossPoints, 4),
                        Decimal::group((string) $term->min_payout_points, 4),
                    ),
                ]);
            }

            // ④ 钱包余额（balance - frozen 才是真正可用）
            $wallet = ProviderPointsWallet::query()->where('provider_id', $providerId)->lockForUpdate()->first();

            if (! $wallet) {
                throw ValidationException::withMessages(['wallet' => '服务商 Points 钱包不存在，请先联系平台初始化。']);
            }

            $available = Decimal::sub($wallet->balance, $wallet->frozen);

            if (Decimal::lt($available, $grossPoints)) {
                throw ValidationException::withMessages([
                    'wallet' => sprintf('可用余额 %s Points 不足以结算 %s Points。', Decimal::group($available, 4), Decimal::group($grossPoints, 4)),
                ]);
            }

            // ⑤ 手续费 / 税 / 净额 / 法币（全程 bcmath，绝不用 float）
            $payoutFee = Decimal::mul($grossPoints, (string) $term->payout_fee_rate);
            $tax = Decimal::mul($grossPoints, (string) $term->tax_rate);
            $netPoints = Decimal::sub(Decimal::sub($grossPoints, $payoutFee), $tax);
            $fiat = Decimal::mul($netPoints, (string) $term->points_to_fiat_rate);

            // ⑥ 结算单（uk_ps_provider_period 兜底：同一区间只可能有一张单）
            try {
                $settlement = ProviderSettlement::create([
                    'settlement_no' => $this->makeSettlementNo($periodStart),
                    'provider_id' => $providerId,
                    'term_id' => $term->id,
                    'period_start' => $periodStart,
                    'period_end' => $periodEnd,
                    'points_amount' => $grossPoints,
                    'commission_points' => $platformPoints,
                    'payout_fee_points' => $payoutFee,
                    'tax_points' => $tax,
                    'net_points' => $netPoints,
                    'exchange_rate' => $term->points_to_fiat_rate,
                    'fiat_amount' => $fiat,
                    'currency' => $term->currency,
                    'item_count' => $rows->count(),
                    'status' => ProviderSettlement::STATUS_PENDING,
                    'requested_at' => now(),
                    'metadata' => [
                        'requested_by_provider_user' => $operatorId,
                        'hold_days' => (int) $term->hold_days,
                    ],
                ]);
            } catch (QueryException $e) {
                if ((int) ($e->errorInfo[1] ?? 0) === 1062) {
                    throw ValidationException::withMessages([
                        'period_start' => '该时间区间已经发起过结算，请勿重复提交。',
                    ]);
                }

                throw $e;
            }

            // ⑦ 明细 + 归属回写（分批，避免超长 IN 与超大事务）
            foreach ($rows->chunk(200) as $chunk) {
                $items = [];
                $ids = [];

                foreach ($chunk as $row) {
                    $ids[] = $row->id;
                    $items[] = [
                        'settlement_id' => $settlement->id,
                        'usage_ledger_id' => $row->id,
                        'provider_id' => $providerId,
                        'user_id' => $row->user_id,
                        'node_id' => $row->node_id,
                        'period_start' => $row->period_start,
                        'period_end' => $row->period_end,
                        'billable_gb' => $row->billable_gb,
                        'user_points_amount' => $row->user_points_amount,
                        'platform_points_amount' => $row->platform_points_amount,
                        'provider_points_amount' => $row->provider_points_amount,
                        'created_at' => now(),
                    ];
                }

                ProviderSettlementItem::insert($items);

                UsageLedger::query()->whereIn('id', $ids)->update(['settlement_id' => $settlement->id]);
            }

            // ⑧ 账本转出 + 钱包扣减
            $newBalance = Decimal::sub($wallet->balance, $grossPoints);

            $ledger = ProviderPointsLedger::create([
                'provider_id' => $providerId,
                'biz_type' => 'settlement',
                'direction' => 'debit',
                'amount' => $grossPoints,
                'balance_after' => $newBalance,
                'biz_ref_type' => 'provider_settlements',
                'biz_ref_id' => $settlement->id,
                'idempotency_key' => 'settlement:'.$settlement->id,
                'remark' => sprintf('结算 %s（%s ~ %s）', $settlement->settlement_no, $startSql, $endSql),
            ]);

            $wallet->update([
                'balance' => $newBalance,
                'total_settled' => Decimal::add($wallet->total_settled, $grossPoints),
                'last_ledger_id' => $ledger->id,
                'version' => $wallet->version + 1,
            ]);

            return $settlement;
        });
    }

    /** 结算单号：PS + 区间起点日期 + 随机串（uk_ps_no 唯一） */
    protected function makeSettlementNo(DateTimeInterface|string $periodStart): string
    {
        $date = $periodStart instanceof DateTimeInterface
            ? $periodStart->format('Ymd')
            : date('Ymd', strtotime((string) $periodStart));

        return 'PS'.$date.'-'.Str::upper(Str::random(6));
    }
}
