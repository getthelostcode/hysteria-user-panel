<?php

namespace Tests\Feature;

use App\Filament\Provider\ProviderPanelTheme;
use App\Models\ProviderUser;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\Concerns\HysteriaFixtures;
use Tests\Concerns\ProviderFixtures;
use Tests\TestCase;

/**
 * 服务商后台主题与品牌验收。
 *
 * 与 UserPanelThemeTest 对应：两个面板各自有**独立主题**，
 * 谁也不允许加载对方那份 CSS（否则"改一边、崩另一边"）。
 */
class ProviderPanelThemeTest extends TestCase
{
    use DatabaseTransactions;
    use HysteriaFixtures;
    use ProviderFixtures;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel(Filament::getPanel('provider'));
    }

    public function test_面板_基础信息_路径与认证_guard(): void
    {
        $panel = Filament::getPanel('provider');

        $this->assertSame('provider', $panel->getId());
        $this->assertSame('provider', $panel->getPath());
        $this->assertSame('provider', $panel->getAuthGuard());

        // 服务商后台不是默认面板：访问 / 仍然落在用户中心
        $this->assertNotSame('provider', Filament::getDefaultPanel()->getId());
    }

    public function test_注册了独立主题_且与用户中心各自一份(): void
    {
        $panel = Filament::getPanel('provider');

        $property = new \ReflectionProperty($panel, 'viteTheme');
        $property->setAccessible(true);

        // provider 面板注册的是 provider 主题
        $this->assertSame(ProviderPanelTheme::VITE_THEME, $property->getValue($panel));

        // 用户面板注册的是用户主题 —— 两者必须是不同文件
        // （注意：reflection 读属性要传对应面板实例，传错实例会读到同一对象的属性而"假相等"）
        $userProperty = new \ReflectionProperty(Filament::getPanel('user'), 'viteTheme');
        $userProperty->setAccessible(true);
        $this->assertSame('resources/css/filament/user/theme.css', $userProperty->getValue(Filament::getPanel('user')));
        $this->assertNotSame($property->getValue($panel), $userProperty->getValue(Filament::getPanel('user')));

        // 没有主题的面板拿不到任何主题
        $this->assertNull($property->getValue(\Filament\Panel::make()->id('admin')));

        // vite 入口包含 provider 主题
        $this->assertStringContainsString(
            'resources/css/filament/provider/theme.css',
            file_get_contents(base_path('vite.config.js')),
        );

        // 主题文件：存在 + 只 import 不覆盖 vendor + 有 Tailwind 配置
        $themePath = base_path(ProviderPanelTheme::VITE_THEME);
        $this->assertFileExists($themePath);

        $theme = file_get_contents($themePath);
        $this->assertStringContainsString('@import', $theme);
        $this->assertStringContainsString('@config', $theme);
        $this->assertStringContainsString('vendor/filament/filament/resources/css/theme.css', $theme);

        // 主题目录必须有配套 tailwind.config.js，且 content 覆盖本面板的 PHP 与 Blade
        $tailwindConfig = dirname($themePath).'/tailwind.config.js';
        $this->assertFileExists($tailwindConfig);

        $config = file_get_contents($tailwindConfig);
        $this->assertStringContainsString('./app/Filament/Provider/**/*.php', $config);
        $this->assertStringContainsString('./resources/views/filament/provider/**/*.blade.php', $config);
        $this->assertStringContainsString('./vendor/filament/**/*.blade.php', $config);

        // 服务商后台专属的结构化定制必须在主题里（不是只换个颜色）
        foreach (['--hv-accent-bar', '.hv-ops-badge', '.hv-code', 'min-width: 720px'] as $token) {
            $this->assertStringContainsString($token, $theme, "主题缺少服务商后台专属样式：{$token}");
        }
    }

    public function test_品牌_名称_Logo_与_语义色(): void
    {
        $panel = Filament::getPanel('provider');

        $this->assertSame('Hysteria VPN 服务商后台', $panel->getBrandName());
        $this->assertSame('/images/brand/user-logo.svg', $panel->getBrandLogo());
        $this->assertSame('/images/brand/user-favicon.svg', $panel->getFavicon());

        // 占位资源必须真实存在，否则页面 404
        foreach ([ProviderPanelTheme::LOGO, ProviderPanelTheme::LOGO_DARK, ProviderPanelTheme::FAVICON] as $asset) {
            $this->assertFileExists(public_path(ltrim($asset, '/')));
        }

        $colors = $panel->getColors();
        foreach (['primary', 'success', 'warning', 'danger', 'info', 'gray'] as $semantic) {
            $this->assertArrayHasKey($semantic, $colors, "缺少语义色 {$semantic}");
        }

        // 服务商后台主色必须与用户中心不同（Teal vs Indigo）——一眼可分是硬要求
        $this->assertNotSame(
            $colors['primary'],
            Filament::getPanel('user')->getColors()['primary'],
        );

        $this->assertTrue($panel->hasDarkMode());
    }

    public function test_登录页显示服务商品牌(): void
    {
        $this->get('/provider/login')
            ->assertOk()
            ->assertSee('Hysteria VPN 服务商后台', false)
            ->assertSee('/images/brand/user-logo.svg', false)
            ->assertSee('/images/brand/user-favicon.svg', false)
            ->assertSee('邮箱', false)
            ->assertSee('密码', false);
    }

    public function test_登录后台的顶栏标识与主色注入(): void
    {
        [$provider] = $this->makeProvider('p1', 'Provider One');
        $account = $this->makeProviderUser($provider);

        $this->actingAs($account, 'provider')
            ->get('/provider/'.$provider->code)
            ->assertOk()
            // 顶栏常驻「服务商后台」标识（避免误把后台当用户中心）
            ->assertSee('hv-ops-badge', false)
            ->assertSee(ProviderPanelTheme::TOPBAR_BADGE, false)
            // 暗黑模式切换器与主色注入
            ->assertSee('fi-theme-switcher', false)
            ->assertSee('--primary-500', false)
            ->assertSee('color-scheme', false);
    }

    public function test_两个面板的主题与标识互不串台(): void
    {
        // 注意顺序：Filament 的 renderHook 注册表是容器里的单例（ViewManager），
        // 同一个测试进程内先访问过 user 面板后，它的 hook 会残留在注册表里，
        // 再访问 provider 面板就会"串台"（真实环境每个请求独立进程，不存在此现象）。
        // 所以这里先断言 provider，再断言 user。
        $this->get('/provider/login')
            ->assertOk()
            ->assertSee('Hysteria VPN 服务商后台', false)
            ->assertDontSee('用户中心', false);

        // 用户中心的登录页不会带服务商后台的标识
        $this->get('/user/login')
            ->assertOk()
            ->assertSee('Hysteria VPN 用户中心', false)
            ->assertDontSee('hv-ops-badge', false);

        // 服务商账号进不了用户面板（canAccessPanel 只认 provider 面板）
        [$provider] = $this->makeProvider('p1', 'Provider One');
        $account = $this->makeProviderUser($provider);

        $this->assertFalse($account->canAccessPanel(Filament::getPanel('user')));
        $this->assertTrue($account->canAccessPanel(Filament::getPanel('provider')));
    }

    public function test_导航分组图标符合服务商侧业务划分(): void
    {
        $groups = collect(Filament::getPanel('provider')->getNavigationGroups())
            ->keyBy(fn ($group) => $group->getLabel());

        $this->assertSame('heroicon-o-home-modern', $groups['概览']->getIcon());
        $this->assertSame('heroicon-o-server-stack', $groups['节点管理']->getIcon());
        $this->assertSame('heroicon-o-currency-yen', $groups['定价管理']->getIcon());
        $this->assertSame('heroicon-o-chart-bar', $groups['流量与用户']->getIcon());
        $this->assertSame('heroicon-o-banknotes', $groups['收益']->getIcon());
        $this->assertSame('heroicon-o-receipt-percent', $groups['结算']->getIcon());
        $this->assertSame('heroicon-o-cog-6-tooth', $groups['设置']->getIcon());
    }

    public function test_构建产物里两个主题各自独立(): void
    {
        $manifestPath = public_path('build/manifest.json');

        if (! file_exists($manifestPath)) {
            $this->markTestSkipped('尚未执行 npm run build，跳过产物断言（CI 里单独跑构建）。');
        }

        $manifest = json_decode(file_get_contents($manifestPath), true);

        $providerEntry = $manifest[ProviderPanelTheme::VITE_THEME] ?? null;
        $userEntry = $manifest['resources/css/filament/user/theme.css'] ?? null;

        $this->assertNotNull($providerEntry, 'manifest 缺少 provider 主题产物');
        $this->assertNotNull($userEntry, 'manifest 缺少 user 主题产物');
        $this->assertNotSame($providerEntry['file'], $userEntry['file'], '两个面板必须是两份独立产物');

        $providerCss = file_get_contents(public_path('build/'.$providerEntry['file']));
        $userCss = file_get_contents(public_path('build/'.$userEntry['file']));

        // provider 产物的专属类不会出现在 user 产物里，反之亦然
        $this->assertStringContainsString('hv-ops-badge', $providerCss);
        $this->assertStringContainsString('--hv-accent-bar', $providerCss);
        $this->assertStringNotContainsString('hv-ops-badge', $userCss);
        $this->assertStringNotContainsString('--hv-accent-bar', $userCss);

        // 两边都包含 Filament 全量组件样式 + 暗色规则（说明是真主题，不是空壳）
        foreach ([$providerCss, $userCss] as $css) {
            $this->assertStringContainsString('fi-wi-stats-overview-stat', $css);
            $this->assertGreaterThan(100, substr_count($css, '.dark'));
        }
    }
}
