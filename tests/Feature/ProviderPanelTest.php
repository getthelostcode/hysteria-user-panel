<?php

namespace Tests\Feature;

use App\Actions\CreateNodeAction;
use App\Actions\GenerateNodeConfigAction;
use App\Actions\PurchasePointsAction;
use App\Actions\RequestSettlementAction;
use App\Actions\ToggleNodeStatusAction;
use App\Actions\UpdatePricingAction;
use App\Filament\Provider\Resources\ProviderNodeResource\Pages\ListProviderNodes;
use App\Filament\Provider\Resources\UsageLedgerResource\Pages\ListUsageLedgers;
use App\Models\ProviderNode;
use App\Models\ProviderPointsLedger;
use App\Models\ProviderPointsWallet;
use App\Models\ProviderPricingRule;
use App\Models\ProviderSettlement;
use App\Models\ProviderSettlementItem;
use App\Models\ProviderUser;
use App\Models\TrafficUsageHourly;
use App\Models\UsageLedger;
use App\Models\UserProviderBinding;
use App\Services\UsageBillingService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\Concerns\HysteriaFixtures;
use Tests\Concerns\ProviderFixtures;
use Tests\TestCase;

/**
 * 服务商后台验收测试。
 *
 * 覆盖四件事：
 *  1. 认证与多租户隔离（独立 guard provider + Filament tenancy）；
 *  2. 只读约束（账本 / 计费 / 流量服务商一概不能写）；
 *  3. 写操作必须走 Action（建节点 / 改价 / 停用节点 / 发起结算）；
 *  4. 关键业务规则（密钥加密、改价只增不改、结算门槛与防重复结算）。
 *
 * 全部跑在真实 MySQL 8 结构上（唯一索引 / CHECK / 外键真实生效），
 * 测试类用 DatabaseTransactions 回滚，不使用 RefreshDatabase（基础表不在 Laravel 迁移里）。
 */
class ProviderPanelTest extends TestCase
{
    use DatabaseTransactions;
    use HysteriaFixtures;
    use ProviderFixtures;

    protected function setUp(): void
    {
        parent::setUp();

        // 当前面板切到 provider：Filament 的 Resource / Policy 解析都依赖它
        Filament::setCurrentPanel(Filament::getPanel('provider'));
    }

    /** 常用：造 服务商 + 节点 + 账号，并切好租户上下文 */
    private function makeTenant(): array
    {
        [$provider, $node] = $this->makeProvider('p1', 'Provider One');
        $account = $this->makeProviderUser($provider);

        // 让服务商处于可写状态（fixture 里已经是 active）
        Filament::setTenant($provider, isQuiet: true);

        return [$provider, $node, $account];
    }

    /** 造一条「已计费」的 1GB 上行账单（真实走计费服务，数字可信） */
    private function billOneGigabyte($user, $provider, $node): UsageLedger
    {
        $binding = $this->makeActiveBinding($user, $provider, now()->subDay());

        // 套餐 code 全局唯一：每次造一个独立 code，避免第二个用户复用 starter 撞唯一索引
        app(PurchasePointsAction::class)->execute($user, $this->makePackage('pkg-'.strtolower(\Illuminate\Support\Str::random(6)), '1000.00000000'));

        $bucket = TrafficUsageHourly::create([
            'user_id' => $user->id,
            'provider_id' => $provider->id,
            'node_id' => $node->id,
            'binding_id' => $binding->id,
            'period_start' => now()->subHours(2)->startOfHour(),
            'period_end' => now()->subHours(2)->startOfHour()->addHour(),
            'upload_bytes' => 1073741824,   // 1 GB
            'download_bytes' => 0,
            'raw_record_count' => 1,
            'billed_status' => 'pending',
        ]);

        return app(UsageBillingService::class)->billBucket($bucket);
    }

    // -----------------------------------------------------------------
    // 认证 + 多租户隔离
    // -----------------------------------------------------------------

    public function test_访客访问服务商后台会被重定向到登录页(): void
    {
        $this->get('/provider')->assertRedirect('/provider/login');
        $this->get('/provider/login')->assertSuccessful();
    }

    public function test_服务商账号登录后进入自己的租户(): void
    {
        [$provider, , $account] = $this->makeTenant();

        // /provider 会由 Filament 的 RedirectToTenantController 跳到 /provider/{code}
        $this->actingAs($account, 'provider')
            ->get('/provider')
            ->assertRedirect('/provider/'.$provider->code);

        $this->actingAs($account, 'provider')
            ->get('/provider/'.$provider->code)
            ->assertSuccessful();
    }

    public function test_服务商能用邮箱密码真正登录(): void
    {
        [$provider, , $account] = $this->makeTenant();

        // 走 Filament 的登录页（真实校验独立 guard + provider_users.password 列）
        Livewire::test(\Filament\Pages\Auth\Login::class)
            ->fillForm(['email' => $account->email, 'password' => 'password'])
            ->call('authenticate')
            ->assertHasNoFormErrors();

        $this->assertAuthenticatedAs($account, 'provider');

        // 登录后能直接打开自己的后台首页
        $this->get('/provider/'.$provider->code)->assertSuccessful();
    }

    public function test_密码错误无法登录(): void
    {
        [, , $account] = $this->makeTenant();

        Livewire::test(\Filament\Pages\Auth\Login::class)
            ->fillForm(['email' => $account->email, 'password' => 'wrong-password'])
            ->call('authenticate')
            ->assertHasFormErrors();

        $this->assertGuest('provider');
    }

    public function test_服务商后台所有页面都能正常打开(): void
    {
        [$provider, $node, $account] = $this->makeTenant();
        $user = $this->makeUser();
        $ledger = $this->billOneGigabyte($user, $provider, $node);

        $settlement = ProviderSettlement::create([
            'settlement_no' => 'PS-TEST-1',
            'provider_id' => $provider->id,
            'period_start' => now()->subDays(2)->startOfDay(),
            'period_end' => now()->startOfDay(),
            'points_amount' => '1.00000000',
            'commission_points' => '0.00000000',
            'payout_fee_points' => '0.00000000',
            'tax_points' => '0.00000000',
            'net_points' => '1.00000000',
            'exchange_rate' => '0.05000000',
            'fiat_amount' => '0.05000000',
            'currency' => 'CNY',
            'item_count' => 0,
            'status' => 'pending',
        ]);

        $prefix = '/provider/'.$provider->code;

        foreach ([
            $prefix,
            $prefix.'/provider-nodes',
            $prefix.'/provider-nodes/create',
            $prefix.'/provider-nodes/'.$node->id,
            $prefix.'/provider-nodes/'.$node->id.'/edit',
            $prefix.'/provider-pricing-rules',
            $prefix.'/provider-pricing-rules/create',
            $prefix.'/traffic-usages',
            $prefix.'/traffic-raws',
            $prefix.'/provider-user-bindings',
            $prefix.'/top-users',
            $prefix.'/provider-points-ledgers',
            $prefix.'/usage-ledgers',
            $prefix.'/provider-settlement-terms',
            $prefix.'/provider-settlements',
            $prefix.'/provider-settlements/'.$settlement->id,
            $prefix.'/manage-provider-profile',
            $prefix.'/manage-api-credentials',
            $prefix.'/change-password',
        ] as $path) {
            $this->actingAs($account, 'provider')->get($path)->assertSuccessful();
        }
    }

    public function test_服务商不能进入别人的租户(): void
    {
        [$providerA, , $accountA] = $this->makeTenant();
        [$providerB] = $this->makeProvider('p2', 'Provider Two');

        $response = $this->actingAs($accountA, 'provider')->get('/provider/'.$providerB->code);

        // canAccessTenant() 返回 false ⇒ 被中间件拦下（403 或 404，都算越权被拒）
        $this->assertContains($response->status(), [403, 404], '跨租户访问必须被拒绝');
    }

    public function test_服务商不能打开别人的节点详情(): void
    {
        [$providerA, , $accountA] = $this->makeTenant();
        [$providerB, $nodeB] = $this->makeProvider('p2', 'Provider Two');

        $this->actingAs($accountA, 'provider')
            ->get('/provider/'.$providerA->code.'/provider-nodes/'.$nodeB->id)
            ->assertNotFound();
    }

    public function test_节点列表只显示自己的节点(): void
    {
        [$providerA, $nodeA, $accountA] = $this->makeTenant();
        [$providerB, $nodeB] = $this->makeProvider('p2', 'Provider Two');

        Filament::setTenant($providerA, isQuiet: true);

        $this->actingAs($accountA, 'provider');

        Livewire::test(ListProviderNodes::class)
            ->assertCanSeeTableRecords([$nodeA])
            ->assertCanNotSeeTableRecords([$nodeB]);
    }

    public function test_计费明细只显示自己的账单(): void
    {
        [$providerA, $nodeA, $accountA] = $this->makeTenant();
        [$providerB, $nodeB] = $this->makeProvider('p2', 'Provider Two');

        $ledgerA = $this->billOneGigabyte($this->makeUser('alice'), $providerA, $nodeA);
        $ledgerB = $this->billOneGigabyte($this->makeUser('bob', 'bob@example.com'), $providerB, $nodeB);

        Filament::setTenant($providerA, isQuiet: true);
        $this->actingAs($accountA, 'provider');

        Livewire::test(ListUsageLedgers::class)
            ->assertCanSeeTableRecords([$ledgerA])
            ->assertCanNotSeeTableRecords([$ledgerB]);
    }

    public function test_被禁用的服务商账号无法进入后台(): void
    {
        [$provider] = $this->makeProvider('p1', 'Provider One');
        $account = $this->makeProviderUser($provider, 'ops@example.com', 'password', 'disabled');

        $this->assertFalse($account->canAccessPanel(Filament::getPanel('provider')));

        // Filament 的 Authenticate 中间件对 canAccessPanel()=false 直接 abort(403)
        $this->actingAs($account, 'provider')
            ->get('/provider/'.$provider->code)
            ->assertForbidden();
    }

    public function test_服务商账号只能进_provider_面板(): void
    {
        [, , $account] = $this->makeTenant();

        $this->assertFalse($account->canAccessPanel(Filament::getPanel('user')));
        $this->assertTrue($account->canAccessPanel(Filament::getPanel('provider')));
    }

    // -----------------------------------------------------------------
    // Policy：只读约束
    // -----------------------------------------------------------------

    public function test_服务商不能创建或修改账本与计费数据(): void
    {
        [$provider, $node, $account] = $this->makeTenant();
        $ledger = $this->billOneGigabyte($this->makeUser(), $provider, $node);

        $gate = Gate::forUser($account);

        // 账本 / 计费 / 流量 / 结算明细：一律不可写
        $this->assertFalse($gate->allows('create', UsageLedger::class));
        $this->assertFalse($gate->allows('update', $ledger));
        $this->assertFalse($gate->allows('delete', $ledger));

        $this->assertFalse($gate->allows('create', ProviderPointsLedger::class));
        $this->assertFalse($gate->allows('create', TrafficUsageHourly::class));
        $this->assertFalse($gate->allows('create', ProviderSettlementItem::class));
        $this->assertFalse($gate->allows('create', UserProviderBinding::class));

        // 定价规则：可新增，不可改/删（改价只能新增版本）
        $rule = ProviderPricingRule::where('provider_id', $provider->id)->first();
        $this->assertTrue($gate->allows('create', ProviderPricingRule::class));
        $this->assertFalse($gate->allows('update', $rule));
        $this->assertFalse($gate->allows('delete', $rule));

        // 节点：可建可改，不可删
        $this->assertTrue($gate->allows('create', ProviderNode::class));
        $this->assertTrue($gate->allows('update', $node));
        $this->assertFalse($gate->allows('delete', $node));

        // 自己的结算单：可发起、创建后不可改
        $settlement = new ProviderSettlement(['provider_id' => $provider->id]);
        $this->assertFalse($gate->allows('update', $settlement));
        $this->assertFalse($gate->allows('delete', $settlement));

        // 服务商资料：可改自己的，不可删
        $this->assertTrue($gate->allows('update', $provider));
        $this->assertFalse($gate->allows('delete', $provider));
        $this->assertFalse($gate->allows('create', \App\Models\Provider::class));
    }

    public function test_服务商不能操作其他服务商的资源(): void
    {
        [, , $accountA] = $this->makeTenant();
        [$providerB, $nodeB] = $this->makeProvider('p2', 'Provider Two');

        $gate = Gate::forUser($accountA);

        $this->assertFalse($gate->allows('view', $nodeB));
        $this->assertFalse($gate->allows('update', $nodeB));
        $this->assertFalse($gate->allows('update', $providerB));
    }

    // -----------------------------------------------------------------
    // CreateNodeAction
    // -----------------------------------------------------------------

    public function test_新增节点会生成加密密钥且明文只在返回值里(): void
    {
        [$provider, , $account] = $this->makeTenant();

        $result = app(CreateNodeAction::class)->execute($provider->id, [
            'name' => '日本 02',
            'host' => 'jp02.example.com',
            'port' => 443,
            'region' => '日本',
            'capacity_mbps' => 500,
        ], $account->id);

        $node = $result['node'];

        $this->assertSame('active', $node->status);
        $this->assertSame($provider->id, $node->provider_id);
        $this->assertNotEmpty($result['secret']);

        // 明文绝不落库：JSON 里只有密文
        $raw = json_encode($node->fresh()->config);
        $this->assertStringNotContainsString($result['secret'], $raw);
        $this->assertSame($result['secret'], $node->fresh()->authSecret());

        // 列表页看到的是脱敏串
        $this->assertStringContainsString('*', $node->fresh()->maskedAuthSecret());
    }

    public function test_同一服务商下_host_与_port_重复会被拒绝(): void
    {
        [$provider, $node] = $this->makeTenant();
        $this->assertSame('p1.example.com', $node->host);
        $this->assertSame(443, $node->port);

        $this->expectException(ValidationException::class);

        app(CreateNodeAction::class)->execute($provider->id, [
            'name' => '重复节点',
            'host' => $node->host,
            'port' => $node->port,
        ]);
    }

    public function test_不同服务商可以有相同_host_端口(): void
    {
        [$providerA, $nodeA] = $this->makeTenant();
        [$providerB] = $this->makeProvider('p2', 'Provider Two');

        $result = app(CreateNodeAction::class)->execute($providerB->id, [
            'name' => '同名地址节点',
            'host' => $nodeA->host,
            'port' => $nodeA->port,
        ]);

        $this->assertNotNull($result['node']->id);
    }

    // -----------------------------------------------------------------
    // ToggleNodeStatusAction
    // -----------------------------------------------------------------

    public function test_停用节点会走_action_且跨服务商被拒绝(): void
    {
        [$providerA, $nodeA] = $this->makeTenant();
        [$providerB, $nodeB] = $this->makeProvider('p2', 'Provider Two');

        $action = app(ToggleNodeStatusAction::class);

        $updated = $action->execute($providerA->id, $nodeA->id, 'disabled');
        $this->assertSame('disabled', $updated->status);

        // 拿别人的 node_id 过来 → 报「节点不存在或不属于当前服务商」，且数据不变
        try {
            $action->execute($providerA->id, $nodeB->id, 'disabled');
            $this->fail('跨服务商操作节点必须被拒绝');
        } catch (ValidationException) {
            // ok
        }

        $this->assertSame('active', $nodeB->fresh()->status);
    }

    // -----------------------------------------------------------------
    // UpdatePricingAction（版本化改价）
    // -----------------------------------------------------------------

    public function test_改价会新增记录并截断旧规则(): void
    {
        [$provider] = $this->makeTenant();

        $old = ProviderPricingRule::where('provider_id', $provider->id)
            ->whereNull('node_id')
            ->first();

        $this->assertSame('10.00000000', (string) $old->points_per_gb);
        $this->assertNull($old->effective_to);

        $from = now()->addMinute();

        $new = app(UpdatePricingAction::class)->execute($provider->id, [
            'node_id' => null,
            'points_per_gb' => '20.00000000',
            'effective_from' => $from,
            'remark' => '调价测试',
        ]);

        $this->assertSame('20.00000000', (string) $new->points_per_gb);
        $this->assertSame($provider->id, $new->provider_id);

        // 旧记录：价格一个字节都没改，只是右端点被截断
        $old->refresh();
        $this->assertSame('10.00000000', (string) $old->points_per_gb);
        $this->assertNotNull($old->effective_to);
        $this->assertSame(
            $new->effective_from->format('Y-m-d H:i:s.u'),
            $old->effective_to->format('Y-m-d H:i:s.u'),
        );

        // 区间无缝：任何时刻都有且只有一条规则命中
        $this->assertSame(1, ProviderPricingRule::where('provider_id', $provider->id)
            ->whereNull('node_id')
            ->effectiveAt($from->copy()->addSecond())
            ->count());
    }

    public function test_改价区间落在既有规则内部会被拒绝(): void
    {
        [$provider] = $this->makeTenant();

        // 既有：服务商级默认价，长期有效。现在要建一条「只到明天」的规则 → 会挖洞，必须拒绝
        $this->expectException(ValidationException::class);

        app(UpdatePricingAction::class)->execute($provider->id, [
            'node_id' => null,
            'points_per_gb' => '30.00000000',
            'effective_from' => now(),
            'effective_to' => now()->addDay(),
        ]);
    }

    public function test_改价可以给未来的时间点排期(): void
    {
        [$provider] = $this->makeTenant();

        $future = now()->addDays(7);

        $rule = app(UpdatePricingAction::class)->execute($provider->id, [
            'node_id' => null,
            'points_per_gb' => '15.00000000',
            'effective_from' => $future,
            'remark' => '下周生效',
        ]);

        // 排期规则尚未生效，当前时刻仍命中旧价
        $this->assertTrue($rule->effective_from->greaterThan(now()));

        $current = app(\App\Services\PricingResolver::class)->ruleFor($provider->id, null, now());
        $this->assertSame('10.00000000', (string) $current->points_per_gb);
    }

    // -----------------------------------------------------------------
    // RequestSettlementAction
    // -----------------------------------------------------------------

    public function test_发起结算会写结算单_明细_账本并扣钱包(): void
    {
        [$provider, $node, $account] = $this->makeTenant();
        $user = $this->makeUser();

        // 默认价 10 P/GB，抽成 20% → 服务商应得 8 P
        $ledger = $this->billOneGigabyte($user, $provider, $node);

        $this->assertSame('8.00000000', (string) $ledger->provider_points_amount);
        $this->assertSame('2.00000000', (string) $ledger->platform_points_amount);

        $wallet = ProviderPointsWallet::where('provider_id', $provider->id)->first();
        $this->assertSame('8.00000000', (string) $wallet->balance);

        $settlement = app(RequestSettlementAction::class)->execute(
            providerId: $provider->id,
            periodStart: now()->subDay(),
            periodEnd: now()->addDay(),
            operatorId: $account->id,
        );

        // 金额拆解：抽成已在计费时扣除，这里不再抽一次（避免重复抽成）
        $this->assertSame('8.00000000', (string) $settlement->points_amount);
        $this->assertSame('2.00000000', (string) $settlement->commission_points);
        $this->assertSame('0.00000000', (string) $settlement->payout_fee_points);
        $this->assertSame('8.00000000', (string) $settlement->net_points);
        $this->assertSame('0.40000000', (string) $settlement->fiat_amount);   // 8 × 0.05
        $this->assertSame('pending', $settlement->status);
        $this->assertSame(1, $settlement->item_count);

        // 明细 + 归属回写（uk_psi_usage 保证一条明细只结一次）
        $this->assertSame(1, ProviderSettlementItem::where('settlement_id', $settlement->id)->count());
        $this->assertSame($settlement->id, $ledger->fresh()->settlement_id);

        // 账本 debit + 钱包扣减
        $this->assertDatabaseHas('provider_points_ledger', [
            'provider_id' => $provider->id,
            'biz_type' => 'settlement',
            'direction' => 'debit',
            'amount' => '8.00000000',
            'balance_after' => '0.00000000',
            'biz_ref_type' => 'provider_settlements',
            'biz_ref_id' => $settlement->id,
        ]);

        $wallet->refresh();
        $this->assertSame('0.00000000', (string) $wallet->balance);
        $this->assertSame('8.00000000', (string) $wallet->total_settled);
        $this->assertSame('8.00000000', (string) $wallet->total_earned);

        // 账本权威值 = 钱包余额
        $sum = (string) ProviderPointsLedger::where('provider_id', $provider->id)->sum('signed_amount');
        $this->assertSame((string) $wallet->balance, $sum);
    }

    public function test_未达起结门槛无法发起结算(): void
    {
        [$provider, $node] = $this->makeTenant();
        $ledger = $this->billOneGigabyte($this->makeUser(), $provider, $node);

        // 把门槛抬到 100 P（当前只有 8 P）
        $provider->settlementTerms()->first()->update(['min_payout_points' => '100.00000000']);

        try {
            app(RequestSettlementAction::class)->execute($provider->id, now()->subDay(), now()->addDay());
            $this->fail('未达起结门槛必须被拒绝');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('起结门槛', collect($e->errors())->flatten()->first());
        }

        // 失败时不留任何脏数据
        $this->assertSame(0, ProviderSettlement::where('provider_id', $provider->id)->count());
        $this->assertNull($ledger->fresh()->settlement_id);
        $this->assertSame('8.00000000', (string) ProviderPointsWallet::where('provider_id', $provider->id)->first()->balance);
    }

    public function test_冻结期内的流量不可结算(): void
    {
        [$provider, $node] = $this->makeTenant();
        $this->billOneGigabyte($this->makeUser(), $provider, $node);

        // 冻结 7 天：刚计费完的流量还没到可结算时间
        $provider->settlementTerms()->first()->update(['hold_days' => 7]);

        $this->expectException(ValidationException::class);

        app(RequestSettlementAction::class)->execute($provider->id, now()->subDay(), now()->addDay());
    }

    public function test_同一区间不能重复结算(): void
    {
        [$provider, $node] = $this->makeTenant();
        $this->billOneGigabyte($this->makeUser(), $provider, $node);

        app(RequestSettlementAction::class)->execute($provider->id, now()->subDay(), now()->addDay());

        // 第二次：明细已被 settle 归属，没有可结算明细 → 拒绝
        $this->expectException(ValidationException::class);

        app(RequestSettlementAction::class)->execute($provider->id, now()->subDay(), now()->addDay());
    }

    // -----------------------------------------------------------------
    // GenerateNodeConfigAction
    // -----------------------------------------------------------------

    public function test_节点配置默认脱敏_显式要求才输出明文(): void
    {
        [$provider, $node] = $this->makeTenant();

        $node->setAuthSecret('node-secret-abcdef123456');
        $node->save();

        $masked = app(GenerateNodeConfigAction::class)->execute($node->fresh(), revealSecret: false);

        $this->assertStringNotContainsString('node-secret-abcdef123456', $masked);
        $this->assertStringContainsString($node->node_code, $masked);
        $this->assertStringContainsString('hysteria', $masked);

        $revealed = app(GenerateNodeConfigAction::class)->execute($node->fresh(), revealSecret: true);
        $this->assertStringContainsString('node-secret-abcdef123456', $revealed);
    }

    // -----------------------------------------------------------------
    // 修改密码（防双重哈希）
    // -----------------------------------------------------------------

    public function test_修改密码页面会正确哈希并拒绝错误的当前密码(): void
    {
        [$provider, , $account] = $this->makeTenant();

        $this->actingAs($account, 'provider');

        // 当前密码错误 → 表单报错
        Livewire::test(\App\Filament\Provider\Pages\Settings\ChangePassword::class)
            ->fillForm([
                'current_password' => 'wrong-password',
                'password' => 'new-password-123',
                'password_confirmation' => 'new-password-123',
            ])
            ->call('save')
            ->assertHasFormErrors(['current_password']);

        // 正确流程
        Livewire::test(\App\Filament\Provider\Pages\Settings\ChangePassword::class)
            ->fillForm([
                'current_password' => 'password',
                'password' => 'new-password-123',
                'password_confirmation' => 'new-password-123',
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $fresh = ProviderUser::find($account->id);

        // 只哈希一次：明文与新密码的 Hash::check 必须通过（双重哈希会失败）
        $this->assertTrue(Hash::check('new-password-123', $fresh->password));
        $this->assertFalse(Hash::check('password', $fresh->password));
    }
}
