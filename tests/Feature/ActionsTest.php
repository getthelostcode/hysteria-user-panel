<?php

namespace Tests\Feature;

use App\Actions\GenerateHysteriaConfigAction;
use App\Actions\PurchasePointsAction;
use App\Actions\SwitchProviderAction;
use App\Models\ProviderSwitchLog;
use App\Models\TrafficUsageHourly;
use App\Models\UsageLedger;
use App\Models\UserPointsLedger;
use App\Models\UserProviderBinding;
use App\Services\UsageBillingService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\Concerns\HysteriaFixtures;
use Tests\TestCase;

/**
 * 业务核心测试：三个 Action + 计费服务。
 * 全部跑在真实的 MySQL 8 结构与约束上（唯一索引 / CHECK / 外键都会真实生效）。
 */
class ActionsTest extends TestCase
{
    use DatabaseTransactions;
    use HysteriaFixtures;

    // -----------------------------------------------------------------
    // PurchasePointsAction
    // -----------------------------------------------------------------

    public function test_购买_points_会同时写订单_钱包与账本(): void
    {
        $user = $this->makeUser();
        $package = $this->makePackage();

        $order = app(PurchasePointsAction::class)->execute($user, $package);

        $this->assertSame('paid', $order->status);
        $this->assertNotNull($order->paid_at);
        $this->assertSame('1000.00000000', (string) $order->points_amount);

        // 钱包是缓存
        $this->assertSame('1000.00000000', (string) $user->wallet()->first()->balance);

        // 账本是权威：SUM(signed_amount) 必须等于余额
        $ledgerSum = (string) UserPointsLedger::where('user_id', $user->id)->sum('signed_amount');
        $this->assertSame('1000.00000000', $ledgerSum);

        $this->assertDatabaseHas('user_points_ledger', [
            'user_id' => $user->id,
            'biz_type' => UserPointsLedger::BIZ_RECHARGE,
            'direction' => UserPointsLedger::DIRECTION_CREDIT,
            'amount' => '1000.00000000',
            'balance_after' => '1000.00000000',
        ]);

        // 订单回填了账本分录
        $this->assertNotNull($order->ledger_id);
    }

    public function test_赠送_points_会计入到账总量并与_check_约束一致(): void
    {
        $user = $this->makeUser();
        $package = $this->makePackage('standard', '5000.00000000', '45.00000000', '500.00000000');

        $order = app(PurchasePointsAction::class)->execute($user, $package);

        // ck_po_sum: points_amount = base + bonus
        $this->assertSame('5500.00000000', (string) $order->points_amount);
        $this->assertSame('5500.00000000', (string) $user->wallet()->first()->balance);
    }

    public function test_下架套餐无法购买(): void
    {
        $user = $this->makeUser();
        $package = $this->makePackage();
        $package->update(['status' => 'inactive']);

        $this->expectException(\Illuminate\Validation\ValidationException::class);

        app(PurchasePointsAction::class)->execute($user, $package->refresh());
    }

    // -----------------------------------------------------------------
    // SwitchProviderAction
    // -----------------------------------------------------------------

    public function test_切换服务商会关闭旧绑定并保证只有一个_active(): void
    {
        $user = $this->makeUser();
        [$providerA] = $this->makeProvider('pa', 'Provider A');
        [$providerB] = $this->makeProvider('pb', 'Provider B');

        $action = app(SwitchProviderAction::class);

        $bindingA = $action->execute($user, $providerA);
        $this->assertSame('active', $bindingA->status);
        $this->assertNull($bindingA->effective_to);

        $bindingB = $action->execute($user, $providerB);

        // 旧绑定被关闭，且 effective_to 正好等于新绑定的 effective_from（区间无缝衔接）
        $bindingA->refresh();
        $this->assertSame('closed', $bindingA->status);
        $this->assertNotNull($bindingA->effective_to);
        $this->assertEquals($bindingA->effective_to->format('Y-m-d H:i:s'), $bindingB->effective_from->format('Y-m-d H:i:s'));
        $this->assertSame($bindingA->id, $bindingB->switch_from_binding_id);

        // 库层不变式：任何时刻只有一个 active
        $activeCount = UserProviderBinding::where('user_id', $user->id)->where('status', 'active')->count();
        $this->assertSame(1, $activeCount);
        $this->assertSame($providerB->id, $user->fresh()->currentProvider()->id);

        // 审计日志
        $this->assertDatabaseHas('provider_switch_logs', [
            'user_id' => $user->id,
            'from_binding_id' => $bindingA->id,
            'to_binding_id' => $bindingB->id,
            'from_provider_id' => $providerA->id,
            'to_provider_id' => $providerB->id,
            'status' => 'success',
        ]);
    }

    public function test_重复切换到同一个服务商会被拒绝(): void
    {
        $user = $this->makeUser();
        [$provider] = $this->makeProvider();

        $action = app(SwitchProviderAction::class);
        $action->execute($user, $provider);

        $this->expectException(\Illuminate\Validation\ValidationException::class);
        $action->execute($user, $provider->refresh());
    }

    public function test_数据库唯一索引能拦住第二条_active_绑定(): void
    {
        $user = $this->makeUser();
        [$providerA] = $this->makeProvider('pa');
        [$providerB] = $this->makeProvider('pb');

        app(SwitchProviderAction::class)->execute($user, $providerA);

        // 绕过 Action 直接插第二条 active → 必须被 uk_user_active_binding 拒绝
        $this->expectException(\Illuminate\Database\UniqueConstraintViolationException::class);

        $binding = new UserProviderBinding([
            'user_id' => $user->id,
            'provider_id' => $providerB->id,
            'external_user_id' => 'hack',
            'status' => 'active',
            'effective_from' => now(),
        ]);
        $binding->setAuthSecret('x');
        $binding->save();
    }

    // -----------------------------------------------------------------
    // GenerateHysteriaConfigAction
    // -----------------------------------------------------------------

    public function test_生成连接配置时密钥默认脱敏(): void
    {
        $user = $this->makeUser();
        [$provider] = $this->makeProvider();
        app(SwitchProviderAction::class)->execute($user, $provider, 'ext-user-1', 'super-secret-key-123456');

        $config = app(GenerateHysteriaConfigAction::class)->execute($user->fresh());

        $this->assertTrue($config->isReady());
        $this->assertSame('ext-user-1', $config->externalUserId);
        $this->assertStringContainsString('*', $config->maskedSecret());
        $this->assertStringNotContainsString('super-secret-key-123456', $config->toYaml());
        $this->assertStringContainsString($provider->nodes->first()->host, $config->toYaml());

        // 明确要求时才输出明文
        $revealed = app(GenerateHysteriaConfigAction::class)->execute($user->fresh(), revealSecret: true);
        $this->assertStringContainsString('super-secret-key-123456', $revealed->toYaml());
    }

    public function test_没有绑定时不会生成配置(): void
    {
        $user = $this->makeUser();

        $config = app(GenerateHysteriaConfigAction::class)->execute($user);

        $this->assertFalse($config->isReady());
    }

    // -----------------------------------------------------------------
    // UsageBillingService
    // -----------------------------------------------------------------

    public function test_计费按流量发生时间取价并优先命中节点价(): void
    {
        $user = $this->makeUser();
        // 默认价 10，节点价 5 → 节点价优先
        [$provider, $node, $defaultRule, $nodeRule] = $this->makeProvider('p1', 'P1', '10.00000000', '5.00000000', '0.200000');

        $binding = $this->makeActiveBinding($user, $provider, now()->subDay());

        // 给用户充够钱
        app(PurchasePointsAction::class)->execute($user, $this->makePackage('starter', '1000.00000000'));

        // 1 GB 上行，0 下行
        $bucket = TrafficUsageHourly::create([
            'user_id' => $user->id,
            'provider_id' => $provider->id,
            'node_id' => $node->id,
            'binding_id' => $binding->id,
            'period_start' => now()->subHours(2)->startOfHour(),
            'period_end' => now()->subHours(2)->startOfHour()->addHour(),
            'upload_bytes' => 1073741824,
            'download_bytes' => 0,
            'raw_record_count' => 1,
            'billed_status' => 'pending',
        ]);

        $ledger = app(UsageBillingService::class)->billBucket($bucket);

        $this->assertNotNull($ledger);
        $this->assertSame($nodeRule->id, $ledger->pricing_rule_id);            // 命中节点价
        $this->assertSame('5.00000000', (string) $ledger->points_per_gb);      // 费率快照
        $this->assertSame('1.00000000', (string) $ledger->billable_gb);        // 1 GB = 1024^3
        $this->assertSame('5.00000000', (string) $ledger->user_points_amount);
        $this->assertSame('1.00000000', (string) $ledger->platform_points_amount);   // 抽成 20%
        $this->assertSame('4.00000000', (string) $ledger->provider_points_amount);

        // 三方恒等
        $this->assertSame(
            (string) $ledger->user_points_amount,
            bcadd((string) $ledger->platform_points_amount, (string) $ledger->provider_points_amount, 8)
        );

        // 用户扣费 + 服务商记账
        $this->assertSame('995.00000000', (string) $user->wallet()->first()->fresh()->balance);
        $this->assertDatabaseHas('provider_points_ledger', [
            'provider_id' => $provider->id,
            'biz_type' => 'usage_earning',
            'direction' => 'credit',
            'amount' => '4.00000000',
        ]);

        // 桶被标记
        $this->assertSame('billed', $bucket->fresh()->billed_status);
    }

    public function test_计费是幂等的_重复执行不会重复扣费(): void
    {
        $user = $this->makeUser();
        [$provider, $node] = $this->makeProvider();
        $binding = $this->makeActiveBinding($user, $provider, now()->subDay());
        app(PurchasePointsAction::class)->execute($user, $this->makePackage());

        $bucket = TrafficUsageHourly::create([
            'user_id' => $user->id,
            'provider_id' => $provider->id,
            'node_id' => $node->id,
            'binding_id' => $binding->id,
            'period_start' => now()->subHours(3)->startOfHour(),
            'period_end' => now()->subHours(3)->startOfHour()->addHour(),
            'upload_bytes' => 536870912,   // 0.5 GB
            'download_bytes' => 0,
            'raw_record_count' => 1,
            'billed_status' => 'pending',
        ]);

        $service = app(UsageBillingService::class);

        $first = $service->billBucket($bucket);
        $balanceAfterFirst = (string) $user->wallet()->first()->fresh()->balance;

        // 再跑一次：桶已是 billed，直接跳过
        $second = $service->billBucket($bucket->fresh());

        $this->assertNotNull($first);
        $this->assertNull($second);
        $this->assertSame(1, UsageLedger::where('user_id', $user->id)->count());
        $this->assertSame($balanceAfterFirst, (string) $user->wallet()->first()->fresh()->balance);
    }

    public function test_余额不足时不会透支_桶被标记失败(): void
    {
        $user = $this->makeUser();
        [$provider, $node] = $this->makeProvider('p1', 'P1', '1000.00000000');   // 贵
        $binding = $this->makeActiveBinding($user, $provider, now()->subDay());
        app(PurchasePointsAction::class)->execute($user, $this->makePackage('starter', '10.00000000'));   // 只有 10 P

        $bucket = TrafficUsageHourly::create([
            'user_id' => $user->id,
            'provider_id' => $provider->id,
            'node_id' => $node->id,
            'binding_id' => $binding->id,
            'period_start' => now()->subHours(2)->startOfHour(),
            'period_end' => now()->subHours(2)->startOfHour()->addHour(),
            'upload_bytes' => 10737418240,   // 10 GB × 1000 P/GB 远超余额
            'download_bytes' => 0,
            'raw_record_count' => 1,
            'billed_status' => 'pending',
        ]);

        $ledger = app(UsageBillingService::class)->billBucket($bucket);

        $this->assertNull($ledger);
        $this->assertSame('failed', $bucket->fresh()->billed_status);
        $this->assertSame('10.00000000', (string) $user->wallet()->first()->fresh()->balance);   // 余额没被透支
    }

    public function test_切换服务商后旧流量仍按旧服务商计费(): void
    {
        $user = $this->makeUser();
        [$providerA, $nodeA] = $this->makeProvider('pa', 'Provider A', '10.00000000');
        [$providerB, $nodeB] = $this->makeProvider('pb', 'Provider B', '20.00000000');

        app(PurchasePointsAction::class)->execute($user, $this->makePackage('starter', '10000.00000000'));

        // 先绑 A（生效时间设在 1 天前，这样 3 小时前的流量落在 A 的区间内）
        $switchA = $this->makeActiveBinding($user, $providerA, now()->subDay());

        // A 期间的流量（时间故意设在 3 小时前）
        $oldBucket = TrafficUsageHourly::create([
            'user_id' => $user->id,
            'provider_id' => $providerA->id,
            'node_id' => $nodeA->id,
            'binding_id' => $switchA->id,
            'period_start' => now()->subHours(3)->startOfHour(),
            'period_end' => now()->subHours(3)->startOfHour()->addHour(),
            'upload_bytes' => 1073741824,
            'download_bytes' => 0,
            'raw_record_count' => 1,
            'billed_status' => 'pending',
        ]);

        // 切到 B（切换发生在「现在」，历史流量仍属于 A 的绑定区间）
        $switchB = app(SwitchProviderAction::class)->execute($user, $providerB);

        // A 的区间应被关闭为 [昨天, 现在)
        $this->assertSame('closed', $switchA->fresh()->status);

        $service = app(UsageBillingService::class);

        $oldLedger = $service->billBucket($oldBucket->fresh());

        $this->assertNotNull($oldLedger, '旧流量必须仍能按发生时间找到绑定并计费');
        $this->assertSame($providerA->id, $oldLedger->provider_id);
        $this->assertSame($switchA->id, $oldLedger->binding_id);
        $this->assertSame('10.00000000', (string) $oldLedger->points_per_gb);   // 用 A 的价格

        // 新流量（切换之后）→ 归 B
        $newBucket = TrafficUsageHourly::create([
            'user_id' => $user->id,
            'provider_id' => $providerB->id,
            'node_id' => $nodeB->id,
            'binding_id' => $switchB->id,
            'period_start' => now(),
            'period_end' => now()->addHour(),
            'upload_bytes' => 1073741824,
            'download_bytes' => 0,
            'raw_record_count' => 1,
            'billed_status' => 'pending',
        ]);

        $newLedger = $service->billBucket($newBucket->fresh());

        $this->assertNotNull($newLedger);
        $this->assertSame($providerB->id, $newLedger->provider_id);
        $this->assertSame('20.00000000', (string) $newLedger->points_per_gb);
    }

    public function test_红冲会回补用户并扣回服务商(): void
    {
        $user = $this->makeUser();
        [$provider, $node] = $this->makeProvider('p1', 'P1', '10.00000000', null, '0.200000');
        $binding = $this->makeActiveBinding($user, $provider, now()->subDay());
        app(PurchasePointsAction::class)->execute($user, $this->makePackage('starter', '1000.00000000'));

        $bucket = TrafficUsageHourly::create([
            'user_id' => $user->id,
            'provider_id' => $provider->id,
            'node_id' => $node->id,
            'binding_id' => $binding->id,
            'period_start' => now()->subHours(2)->startOfHour(),
            'period_end' => now()->subHours(2)->startOfHour()->addHour(),
            'upload_bytes' => 1073741824,
            'download_bytes' => 0,
            'raw_record_count' => 1,
            'billed_status' => 'pending',
        ]);

        $service = app(UsageBillingService::class);
        $ledger = $service->billBucket($bucket);

        $this->assertSame('990.00000000', (string) $user->wallet()->first()->fresh()->balance);

        $service->revert($ledger, '测试红冲');

        // 用户回补到 1000
        $this->assertSame('1000.00000000', (string) $user->wallet()->first()->fresh()->balance);
        $this->assertSame('reversed', $ledger->fresh()->status);

        // 账本出现红冲分录，且权威余额仍然等于钱包
        $sum = (string) UserPointsLedger::where('user_id', $user->id)->sum('signed_amount');
        $this->assertSame('1000.00000000', $sum);

        // 桶回到待计费
        $this->assertSame('pending', $bucket->fresh()->billed_status);
    }
}
