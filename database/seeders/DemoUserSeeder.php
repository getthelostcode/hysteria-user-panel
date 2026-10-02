<?php

namespace Database\Seeders;

use App\Actions\PurchasePointsAction;
use App\Models\PointsPackage;
use App\Models\Provider;
use App\Models\ProviderNode;
use App\Models\ProviderSwitchLog;
use App\Models\TrafficUsageHourly;
use App\Models\User;
use App\Models\UserProviderBinding;
use App\Services\UsageBillingService;
use App\Support\Decimal;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * 演示用户数据。
 *
 * 造出一个「真实可用」的账号，覆盖用户后台的每条链路：
 *   1. 注册用户（走模型钩子：自动补 uuid / username / 钱包）
 *   2. 用真实 Action 买两次 Points（走事务 + 账本）
 *   3. 造版本化绑定：30 天前绑服务商 A，10 天前切到服务商 B（区间不重叠）
 *   4. 按小时造流量桶（前后两段分别落在两个绑定区间内）
 *   5. 用 UsageBillingService 真实跑一次计费，生成账单与双方账本
 *
 * 演示账号：demo@example.com / demo2@example.com，密码都是 password
 */
class DemoUserSeeder extends Seeder
{
    public function run(): void
    {
        $purchase = app(PurchasePointsAction::class);

        // ---------------------------------------------------------------
        // 演示用户
        // ---------------------------------------------------------------
        $demo = User::firstOrCreate(
            ['email' => 'demo@example.com'],
            [
                'uuid' => (string) Str::uuid(),
                'username' => 'demo',
                'password' => 'password',
                'status' => 'active',
                'locale' => 'zh-CN',
                'registered_at' => now()->subDays(35),
                'email_verified_at' => now()->subDays(35),
            ]
        );

        User::firstOrCreate(
            ['email' => 'demo2@example.com'],
            [
                'uuid' => (string) Str::uuid(),
                'username' => 'demo2',
                'password' => 'password',
                'status' => 'active',
                'registered_at' => now()->subDays(5),
                'email_verified_at' => now()->subDays(5),
            ]
        );

        // ---------------------------------------------------------------
        // 买 Points（真实 Action：下单 → 模拟支付 → 加钱包 → 写账本）
        // ---------------------------------------------------------------
        if ($demo->pointsOrders()->count() === 0) {
            foreach (['starter', 'standard'] as $code) {
                $package = PointsPackage::where('code', $code)->first();

                if ($package) {
                    $purchase->execute($demo, $package, 'sandbox');
                }
            }

            $this->command?->info('已为 demo 购买 2 笔 Points，余额 '.$demo->fresh()->pointsBalance());
        }

        // ---------------------------------------------------------------
        // 版本化绑定：30 天前 → 服务商 A；10 天前 → 切换到服务商 B
        // 注意：区间必须严格不重叠（库层触发器也会校验）
        // ---------------------------------------------------------------
        $providerA = Provider::where('code', 'suyun')->firstOrFail();
        $providerB = Provider::where('code', 'xinglian')->firstOrFail();

        $switchAt = now()->subDays(10)->startOfHour();
        $startAt = now()->subDays(30)->startOfHour();

        $bindingA = UserProviderBinding::where('user_id', $demo->id)
            ->where('provider_id', $providerA->id)
            ->first();

        if (! $bindingA) {
            // 旧绑定：插入时就是已关闭状态（effective_to 不能为 NULL，否则会与后面的绑定重叠）
            $bindingA = new UserProviderBinding([
                'user_id' => $demo->id,
                'provider_id' => $providerA->id,
                'external_user_id' => 'demo_a1b2',
                'status' => UserProviderBinding::STATUS_CLOSED,
                'effective_from' => $startAt,
                'effective_to' => $switchAt,
            ]);
            $bindingA->setAuthSecret('demo-secret-for-suyun-'.Str::random(8));
            $bindingA->save();

            // 新绑定：切换后的服务商 B
            $bindingB = new UserProviderBinding([
                'user_id' => $demo->id,
                'provider_id' => $providerB->id,
                'external_user_id' => 'demo_c3d4',
                'status' => UserProviderBinding::STATUS_ACTIVE,
                'effective_from' => $switchAt,
                'effective_to' => null,
                'switch_from_binding_id' => $bindingA->id,
            ]);
            $bindingB->setAuthSecret('demo-secret-for-xinglian-'.Str::random(8));
            $bindingB->save();

            // 审计：切换记录
            ProviderSwitchLog::create([
                'user_id' => $demo->id,
                'from_binding_id' => $bindingA->id,
                'to_binding_id' => $bindingB->id,
                'from_provider_id' => $providerA->id,
                'to_provider_id' => $providerB->id,
                'effective_at' => $switchAt,
                'status' => 'success',
                'operator_type' => 'user',
                'operator_id' => $demo->id,
                'reason' => '演示数据：用户主动切换',
            ]);
        }

        // ---------------------------------------------------------------
        // 造流量桶（前 20 天走服务商 A，后 10 天走服务商 B）
        // ---------------------------------------------------------------
        if ($demo->usageHourly()->count() === 0) {
            mt_srand(20261002);   // 固定随机种子，保证可复现

            $nodeA = ProviderNode::where('provider_id', $providerA->id)->where('node_code', 'hk-01')->first();
            $nodeB = ProviderNode::where('provider_id', $providerB->id)->where('node_code', 'sg-01')->first();

            $bindingA = $bindingA->refresh();
            $bindingB = UserProviderBinding::where('user_id', $demo->id)->activeStatus()->first();

            $created = 0;

            // 服务商 A 区间：[startAt, switchAt)
            for ($day = 30; $day >= 11; $day--) {
                foreach ([9, 14, 20, 22] as $hour) {
                    $periodStart = now()->subDays($day)->setTime($hour, 0);

                    if ($periodStart->lt($startAt) || $periodStart->gte($switchAt)) {
                        continue;
                    }

                    $this->makeBucket(
                        $demo, $providerA, $nodeA, $bindingA, $periodStart,
                        mt_rand(200, 900) * 1048576,   // 上行 200~900 MB
                        mt_rand(600, 2500) * 1048576   // 下行 600~2500 MB
                    );
                    $created++;
                }
            }

            // 服务商 B 区间：[switchAt, now)
            for ($day = 10; $day >= 1; $day--) {
                foreach ([10, 15, 21] as $hour) {
                    $periodStart = now()->subDays($day)->setTime($hour, 0);

                    if ($periodStart->lt($switchAt)) {
                        continue;
                    }

                    $this->makeBucket(
                        $demo, $providerB, $nodeB, $bindingB, $periodStart,
                        mt_rand(300, 1200) * 1048576,
                        mt_rand(800, 3000) * 1048576
                    );
                    $created++;
                }
            }

            $this->command?->info('已生成 '.$created.' 个流量小时桶');
        }

        // ---------------------------------------------------------------
        // 真实跑一次计费（按流量发生时间回溯绑定与定价）
        // ---------------------------------------------------------------
        $billing = app(UsageBillingService::class);
        $result = $billing->billUser($demo->id);

        if ($result['billed'] > 0) {
            $this->command?->info(sprintf(
                '已计费 %d 条，失败 %d 条，扣减 %s Points',
                $result['billed'],
                $result['failed'],
                Decimal::group($result['points'], 8),
            ));
        }

        $wallet = $demo->fresh()->wallet()->first();
        $this->command?->info(sprintf(
            'demo 当前余额 %s Points（累计充值 %s / 累计消费 %s）',
            Decimal::group($wallet->balance, 2),
            Decimal::group($wallet->total_recharged, 2),
            Decimal::group($wallet->total_consumed, 2),
        ));
    }

    /** 造一个幂等的流量桶（唯一键 binding_id + node_id + period_start） */
    protected function makeBucket(
        User $user,
        Provider $provider,
        ?ProviderNode $node,
        UserProviderBinding $binding,
        \DateTimeInterface $periodStart,
        int $uploadBytes,
        int $downloadBytes,
    ): void {
        TrafficUsageHourly::updateOrCreate(
            [
                'binding_id' => $binding->id,
                'node_id' => $node->id,
                'period_start' => $periodStart,
            ],
            [
                'user_id' => $user->id,
                'provider_id' => $provider->id,
                'period_end' => (clone $periodStart)->modify('+1 hour'),
                'upload_bytes' => $uploadBytes,
                'download_bytes' => $downloadBytes,
                'raw_record_count' => mt_rand(1, 4),
                'billed_status' => TrafficUsageHourly::STATUS_PENDING,
            ]
        );
    }
}
