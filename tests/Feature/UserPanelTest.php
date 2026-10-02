<?php

namespace Tests\Feature;

use App\Filament\User\Pages\Auth\Register;
use App\Filament\User\Pages\MyConnection;
use App\Filament\User\Pages\MyWallet;
use App\Filament\User\Pages\SwitchProvider;
use App\Filament\User\Resources\PointsPackageResource\Pages\ListPointsPackages;
use App\Filament\User\Resources\PointsLedgerResource\Pages\ListPointsLedger;
use App\Models\PointsOrder;
use App\Models\TrafficUsageHourly;
use App\Models\UsageLedger;
use App\Models\User;
use App\Models\UserPointsLedger;
use App\Models\UserProviderBinding;
use App\Services\UsageBillingService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Tests\Concerns\HysteriaFixtures;
use Tests\TestCase;

/**
 * 用户后台验收测试：注册 → 买积分 → 切换服务商 → 看流量 → 看账单，以及数据隔离。
 * 全部打真实 HTTP 路由与 Livewire 组件，跑在真实 MySQL 8 结构上。
 */
class UserPanelTest extends TestCase
{
    use DatabaseTransactions;
    use HysteriaFixtures;

    protected function setUp(): void
    {
        parent::setUp();

        // 让 Filament 组件测试知道当前面板
        Filament::setCurrentPanel(Filament::getPanel('user'));
    }

    // -----------------------------------------------------------------
    // 认证
    // -----------------------------------------------------------------

    public function test_访客访问用户后台会被重定向到登录页(): void
    {
        $this->get('/user')->assertRedirect('/user/login');
    }

    public function test_用户能够注册并自动获得钱包(): void
    {
        Livewire::test(Register::class)
            ->fillForm([
                'username' => 'newuser',
                'email' => 'newuser@example.com',
                'password' => 'password123',
                'passwordConfirmation' => 'password123',
            ])
            ->call('register')
            ->assertHasNoFormErrors();

        $user = User::where('email', 'newuser@example.com')->first();

        $this->assertNotNull($user, '注册后应生成用户');
        $this->assertSame('newuser', $user->username);
        $this->assertTrue(Hash::check('password123', $user->password_hash), '密码应写入 password_hash 列');
        $this->assertNotNull($user->wallet, '注册后应自动创建 points 钱包');
        $this->assertAuthenticated();
    }

    public function test_注册时用户名重复会被拒绝(): void
    {
        $this->makeUser('taken', 'taken@example.com');

        Livewire::test(Register::class)
            ->fillForm([
                'username' => 'taken',
                'email' => 'another@example.com',
                'password' => 'password123',
                'passwordConfirmation' => 'password123',
            ])
            ->call('register')
            ->assertHasFormErrors(['username']);
    }

    public function test_被停用的用户无法登录面板(): void
    {
        $user = $this->makeUser('banned');
        $user->update(['status' => 'suspended']);

        $this->assertFalse($user->fresh()->canAccessPanel(Filament::getPanel('user')));
    }

    // -----------------------------------------------------------------
    // 页面可访问性（真实 HTTP 请求，能抓出 Blade / Filament API 错误）
    // -----------------------------------------------------------------

    public function test_登录后所有用户后台页面都能正常打开(): void
    {
        $user = $this->makeUser();
        [$provider] = $this->makeProvider();
        $this->makePackage();
        app(\App\Actions\SwitchProviderAction::class)->execute($user, $provider);

        foreach ([
            '/user',
            '/user/my-wallet',
            '/user/points-ledgers',
            '/user/points-packages',
            '/user/points-orders',
            '/user/providers',
            '/user/switch-provider',
            '/user/user-provider-bindings',
            '/user/my-connection',
            '/user/traffic-usages',
            '/user/usage-ledgers',
            '/user/profile',
        ] as $path) {
            $this->actingAs($user->fresh())->get($path)->assertSuccessful();
        }
    }

    // -----------------------------------------------------------------
    // 数据隔离
    // -----------------------------------------------------------------

    public function test_积分流水只显示自己的数据(): void
    {
        $me = $this->makeUser('me');
        $other = $this->makeUser('other', 'other@example.com');

        $mine = UserPointsLedger::create([
            'user_id' => $me->id, 'biz_type' => 'recharge', 'direction' => 'credit',
            'amount' => '100.00000000', 'balance_after' => '100.00000000',
            'idempotency_key' => 'test:mine',
        ]);

        $theirs = UserPointsLedger::create([
            'user_id' => $other->id, 'biz_type' => 'recharge', 'direction' => 'credit',
            'amount' => '999.00000000', 'balance_after' => '999.00000000',
            'idempotency_key' => 'test:theirs',
        ]);

        $this->actingAs($me);

        Livewire::test(ListPointsLedger::class)
            ->assertCanSeeTableRecords([$mine])
            ->assertCanNotSeeTableRecords([$theirs]);
    }

    public function test_看不到别人的订单(): void
    {
        $me = $this->makeUser('me');
        $other = $this->makeUser('other', 'other@example.com');
        $package = $this->makePackage();

        $mine = PointsOrder::create([
            'order_no' => 'PO-MINE', 'user_id' => $me->id, 'package_id' => $package->id,
            'base_points' => '100', 'bonus_points' => '0', 'points_amount' => '100',
            'fiat_amount' => '1', 'fiat_currency' => 'USD', 'payment_method' => 'sandbox',
            'payment_channel' => 'sandbox', 'status' => 'paid',
        ]);

        $theirs = PointsOrder::create([
            'order_no' => 'PO-THEIRS', 'user_id' => $other->id, 'package_id' => $package->id,
            'base_points' => '100', 'bonus_points' => '0', 'points_amount' => '100',
            'fiat_amount' => '1', 'fiat_currency' => 'USD', 'payment_method' => 'sandbox',
            'payment_channel' => 'sandbox', 'status' => 'paid',
        ]);

        $this->actingAs($me);

        Livewire::test(\App\Filament\User\Resources\PointsOrderResource\Pages\ListPointsOrders::class)
            ->assertCanSeeTableRecords([$mine])
            ->assertCanNotSeeTableRecords([$theirs]);
    }

    // -----------------------------------------------------------------
    // Policy
    // -----------------------------------------------------------------

    public function test_用户不能创建或修改账本与流量数据(): void
    {
        $user = $this->makeUser();

        $this->assertFalse(\Illuminate\Support\Facades\Gate::forUser($user)->allows('create', UserPointsLedger::class));
        $this->assertFalse(\Illuminate\Support\Facades\Gate::forUser($user)->allows('create', TrafficUsageHourly::class));
        $this->assertFalse(\Illuminate\Support\Facades\Gate::forUser($user)->allows('create', UserProviderBinding::class));
        $this->assertFalse(\Illuminate\Support\Facades\Gate::forUser($user)->allows('create', UsageLedger::class));
        $this->assertFalse(\Illuminate\Support\Facades\Gate::forUser($user)->allows('create', PointsOrder::class));

        // 服务商列表可读、不可写
        $this->assertTrue(\Illuminate\Support\Facades\Gate::forUser($user)->allows('viewAny', \App\Models\Provider::class));
        $this->assertFalse(\Illuminate\Support\Facades\Gate::forUser($user)->allows('create', \App\Models\Provider::class));
    }

    // -----------------------------------------------------------------
    // 购买积分（走 Resource 表格 Action）
    // -----------------------------------------------------------------

    public function test_用户能在界面上购买积分(): void
    {
        $user = $this->makeUser();
        $package = $this->makePackage('starter', '1000.00000000', '10.00000000', '100.00000000');

        $this->actingAs($user);

        Livewire::test(ListPointsPackages::class)
            ->callTableAction('purchase', $package)
            ->assertHasNoTableActionErrors();

        $this->assertDatabaseHas('points_orders', ['user_id' => $user->id, 'status' => 'paid']);
        $this->assertSame('1100.00000000', (string) $user->wallet()->first()->fresh()->balance);
        $this->assertDatabaseHas('user_points_ledger', [
            'user_id' => $user->id,
            'biz_type' => 'recharge',
            'direction' => 'credit',
            'amount' => '1100.00000000',
        ]);
    }

    // -----------------------------------------------------------------
    // 切换服务商（走 Page 的 Livewire action）
    // -----------------------------------------------------------------

    public function test_用户能在界面上切换服务商(): void
    {
        $user = $this->makeUser();
        [$providerA] = $this->makeProvider('pa', 'Provider A');
        [$providerB] = $this->makeProvider('pb', 'Provider B');

        $this->actingAs($user);

        Livewire::test(SwitchProvider::class)->call('switchTo', $providerA->id);
        $this->assertSame($providerA->id, $user->fresh()->currentProvider()->id);

        Livewire::test(SwitchProvider::class)->call('switchTo', $providerB->id);

        $this->assertSame($providerB->id, $user->fresh()->currentProvider()->id);
        $this->assertSame(1, UserProviderBinding::where('user_id', $user->id)->where('status', 'active')->count());
        $this->assertDatabaseHas('provider_switch_logs', [
            'user_id' => $user->id,
            'to_provider_id' => $providerB->id,
        ]);
    }

    public function test_切换到当前服务商会提示错误而不是写入脏数据(): void
    {
        $user = $this->makeUser();
        [$provider] = $this->makeProvider();

        app(\App\Actions\SwitchProviderAction::class)->execute($user, $provider);

        $this->actingAs($user);

        Livewire::test(SwitchProvider::class)->call('switchTo', $provider->id);

        // 仍然只有一条 active 绑定
        $this->assertSame(1, UserProviderBinding::where('user_id', $user->id)->where('status', 'active')->count());
    }

    // -----------------------------------------------------------------
    // 我的积分 / 我的连接
    // -----------------------------------------------------------------

    public function test_我的积分页面显示钱包与账本一致的余额(): void
    {
        $user = $this->makeUser();
        app(\App\Actions\PurchasePointsAction::class)->execute($user, $this->makePackage());

        $this->actingAs($user);

        Livewire::test(MyWallet::class)
            ->assertSee('1,000')                       // 千分位（去尾零，不显示 .00）
            ->assertSee('Points')
            ->assertSee('钱包缓存与账本一致');
    }

    public function test_我的连接页面展示脱敏后的配置(): void
    {
        $user = $this->makeUser();
        [$provider] = $this->makeProvider();
        app(\App\Actions\SwitchProviderAction::class)->execute($user, $provider, 'ext-1', 'topsecret123456');

        $this->actingAs($user);

        Livewire::test(MyConnection::class)
            ->assertSee('ext-1')
            ->assertDontSee('topsecret123456')          // 默认脱敏
            ->call('toggleSecret')
            ->assertSee('topsecret123456');              // 主动显示明文
    }

    // -----------------------------------------------------------------
    // 用量与账单展示
    // -----------------------------------------------------------------

    public function test_流量用量与计费明细页面能展示真实数据(): void
    {
        $user = $this->makeUser();
        [$provider, $node] = $this->makeProvider();
        $binding = app(\App\Actions\SwitchProviderAction::class)->execute($user, $provider);
        app(\App\Actions\PurchasePointsAction::class)->execute($user, $this->makePackage('starter', '1000.00000000'));

        $bucket = TrafficUsageHourly::create([
            'user_id' => $user->id,
            'provider_id' => $provider->id,
            'node_id' => $node->id,
            'binding_id' => $binding->id,
            'period_start' => now()->subHours(2)->startOfHour(),
            'period_end' => now()->subHours(2)->startOfHour()->addHour(),
            'upload_bytes' => 1073741824,
            'download_bytes' => 1073741824,
            'raw_record_count' => 1,
            'billed_status' => 'pending',
        ]);

        app(UsageBillingService::class)->billBucket($bucket);

        $this->actingAs($user);

        // 流量用量页
        $this->get('/user/traffic-usages')->assertOk()->assertSee('2.00 GB');

        // 计费明细页展示单价与扣费
        $this->get('/user/usage-ledgers')->assertOk()->assertSee('10')->assertSee('20');
    }
}
