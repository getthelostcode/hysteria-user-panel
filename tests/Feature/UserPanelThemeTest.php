<?php

namespace Tests\Feature;

use App\Filament\User\UserPanelTheme;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * 面板主题与品牌验收（对应验收命令里的「自定义品牌 / 主色 / 暗黑模式不受影响」）。
 */
class UserPanelThemeTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel(Filament::getPanel('user'));
    }

    public function test_面板_基础信息_路径与默认面板(): void
    {
        $panel = Filament::getPanel('user');

        $this->assertSame('user', $panel->getId());
        $this->assertSame('user', $panel->getPath());
        $this->assertSame('user', Filament::getDefaultPanel()->getId());
    }

    public function test_注册了独立主题_且不在其它面板生效(): void
    {
        $panel = Filament::getPanel('user');

        // 面板确实注册了独立主题（读 protected 属性，避免触发 Vite manifest 解析）
        $property = new \ReflectionProperty($panel, 'viteTheme');
        $property->setAccessible(true);

        $this->assertSame(UserPanelTheme::VITE_THEME, $property->getValue($panel));

        // 另一个面板（模拟平台后台 / 服务商后台）不会拿到这个主题
        $otherPanel = \Filament\Panel::make()->id('admin');
        $this->assertNull($property->getValue($otherPanel));

        // vite 入口包含该主题（构建产物由 Vite manifest 管理）
        $this->assertStringContainsString(
            'resources/css/filament/user/theme.css',
            file_get_contents(base_path('vite.config.js')),
        );

        // 主题文件必须存在，且是 Filament 允许的「只导入不覆盖 vendor」写法
        $themePath = base_path(UserPanelTheme::VITE_THEME);
        $this->assertFileExists($themePath);
        $theme = file_get_contents($themePath);
        $this->assertStringContainsString('@import', $theme);
        $this->assertStringContainsString('@config', $theme);
        // 不允许把 vendor 里的主题改掉（本主题只 import 它）
        $this->assertStringContainsString('vendor/filament/filament/resources/css/theme.css', $theme);
    }

    public function test_品牌_名称_Logo_与_favicon(): void
    {
        $panel = Filament::getPanel('user');

        $this->assertSame('Hysteria VPN 用户中心', $panel->getBrandName());
        $this->assertSame('/images/brand/user-logo.svg', $panel->getBrandLogo());
        $this->assertSame('/images/brand/user-logo-dark.svg', $panel->getDarkModeBrandLogo());
        $this->assertSame('/images/brand/user-favicon.svg', $panel->getFavicon());

        // 占位资源必须真实存在，否则页面会 404
        foreach ([UserPanelTheme::LOGO, UserPanelTheme::LOGO_DARK, UserPanelTheme::FAVICON] as $asset) {
            $this->assertFileExists(public_path(ltrim($asset, '/')));
        }
    }

    public function test_语义色_与_暗黑模式(): void
    {
        $panel = Filament::getPanel('user');

        $colors = $panel->getColors();
        foreach (['primary', 'success', 'warning', 'danger', 'info'] as $semantic) {
            $this->assertArrayHasKey($semantic, $colors, "缺少语义色 {$semantic}");
        }

        // 暗黑模式必须可用
        $this->assertTrue($panel->hasDarkMode());
    }

    public function test_登录页显示中文品牌_与_主题资源(): void
    {
        $response = $this->get('/user/login');

        $response->assertOk()
            ->assertSee('Hysteria VPN 用户中心', false)   // brandName
            ->assertSee('/images/brand/user-logo.svg', false)
            ->assertSee('/images/brand/user-favicon.svg', false)
            ->assertSee('邮箱', false)                     // zh_CN 登录表单
            ->assertSee('密码', false)
            ->assertSee('Hyper Points', false);            // 品牌文案 RenderHook

        // 构建过主题后，页面会引用 Vite 产物；未构建时不应报错
        $this->assertTrue(true);
    }

    public function test_登录后的仪表盘使用自定义主色并带暗黑模式开关(): void
    {
        $user = \App\Models\User::factory()->create([
            'username' => 'themeuser',
            'email' => 'themeuser@example.com',
        ]);

        $response = $this->actingAs($user)->get('/user');

        $response->assertOk()
            // 暗黑模式切换入口（Filament 用户菜单里的主题切换器）
            ->assertSee('fi-theme-switcher', false)
            // 面板主色被注入（Indigo 色阶）
            ->assertSee('--primary-500', false)
            // Framework 的亮/暗 class 机制可用
            ->assertSee('color-scheme', false);
    }

    public function test_注册_与_密码重置页也带品牌文案(): void
    {
        $this->get('/user/register')->assertOk()
            ->assertSee('Hysteria VPN 用户中心', false)
            ->assertSee('用户名', false)
            ->assertSee('Hyper Points', false);

        $this->get('/user/password-reset/request')->assertOk()
            ->assertSee('Hysteria VPN 用户中心', false);
    }

    public function test_导航分组图标符合要求(): void
    {
        $panel = Filament::getPanel('user');
        $groups = collect($panel->getNavigationGroups())->keyBy(fn ($group) => $group->getLabel());

        $this->assertSame('heroicon-o-wallet', $groups['资产']->getIcon());
        $this->assertSame('heroicon-o-server-stack', $groups['服务']->getIcon());
        $this->assertSame('heroicon-o-chart-bar', $groups['用量']->getIcon());
        $this->assertSame('heroicon-o-cog-6-tooth', $groups['设置']->getIcon());
    }

    public function test_状态徽章_文字与图标并存_不只靠颜色(): void
    {
        foreach ([
            \App\Support\StatusBadge::binding('active'),
            \App\Support\StatusBadge::order('pending'),
            \App\Support\StatusBadge::provider('suspended'),
            \App\Support\StatusBadge::traffic('failed'),
            \App\Support\StatusBadge::usage('charged'),
            \App\Support\StatusBadge::pointsLedger('usage'),
        ] as $badge) {
            $this->assertNotEmpty($badge['label'], '状态必须有文字');
            $this->assertNotEmpty($badge['icon'], '状态必须有图标');
            $this->assertNotEmpty($badge['color'], '状态必须有颜色');
        }

        // 颜色映射符合要求：active 绿 / pending 黄 / inactive 灰 / failed 红
        $this->assertSame('success', \App\Support\StatusBadge::binding('active')['color']);
        $this->assertSame('warning', \App\Support\StatusBadge::order('pending')['color']);
        $this->assertSame('gray', \App\Support\StatusBadge::binding('closed')['color']);
        $this->assertSame('danger', \App\Support\StatusBadge::traffic('failed')['color']);
    }

    public function test_points_展示格式_千分位_去尾零_带后缀(): void
    {
        $this->assertSame('1,000 Points', \App\Support\Decimal::points('1000.00000000'));
        $this->assertSame('1,234.5 Points', \App\Support\Decimal::points('1234.50000000', 2));
        $this->assertSame('3.13426171 Points', \App\Support\Decimal::points('3.13426171', 8));
        $this->assertSame('0 Points', \App\Support\Decimal::points(null));
        // 绝不出现科学计数法 / 浮点尾差
        $this->assertStringNotContainsString('E+', \App\Support\Decimal::points('123456789.12345678', 8));
    }
}
