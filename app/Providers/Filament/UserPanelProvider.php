<?php

namespace App\Providers\Filament;

use App\Filament\User\Pages\Auth\EditProfile;
use App\Filament\User\Pages\Auth\Login;
use App\Filament\User\Pages\Auth\Register;
use App\Filament\User\Pages\Dashboard;
use App\Filament\User\UserPanelTheme;
use App\Filament\User\Widgets\RecentPointsLedgerTable;
use App\Filament\User\Widgets\TrafficTrendChart;
use App\Filament\User\Widgets\WalletStatsOverview;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Navigation\NavigationGroup;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Enums\MaxWidth;
use Filament\View\PanelsRenderHook;
use Illuminate\Contracts\View\View;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;

/**
 * 用户后台面板（panel id = user，路径 /user）。
 *
 * - 登录后跳转 /user
 * - 启用：登录、注册、密码重置、个人资料、暗黑模式
 * - 独立主题：resources/css/filament/user/theme.css（只作用于本面板）
 *
 * 本文件只服务「普通用户」；平台运营后台、服务商后台各自有独立 Panel，
 * 各自的 viteTheme / colors / 品牌配置互不影响。
 */
class UserPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->id('user')
            ->path('user')

            // ---- 认证：登录 / 注册 / 密码重置 / 个人资料 ----
            // 登录页自定义过跳转逻辑：只认本面板的 url.intended（详见 App\Filament\User\Pages\Auth\Login）
            ->login(Login::class)
            ->registration(Register::class)           // 自定义注册页（多一个 username 字段）
            ->passwordReset()
            ->profile(EditProfile::class, isSimple: false)

            // ---- 品牌（Logo / favicon 为占位路径，换正式资源只改 UserPanelTheme）----
            ->default()                               // 访问 / 自动进入 /user
            ->brandName(UserPanelTheme::BRAND_NAME)   // Hysteria VPN 用户中心
            ->brandLogo(UserPanelTheme::LOGO)
            ->darkModeBrandLogo(UserPanelTheme::LOGO_DARK)
            ->brandLogoHeight(UserPanelTheme::LOGO_HEIGHT)
            ->favicon(UserPanelTheme::FAVICON)

            // ---- 主题与配色（全部使用 Filament 语义色，不写死十六进制）----
            ->viteTheme(UserPanelTheme::VITE_THEME)   // 独立主题，只作用于本面板
            ->colors(UserPanelTheme::colors())        // primary/success/warning/danger/info/gray
            ->darkMode(isForced: false)               // 亮/暗可切换（用户菜单里切换）

            // ---- 布局 ----
            ->maxContentWidth(MaxWidth::Full)
            ->sidebarCollapsibleOnDesktop()           // 桌面可折叠；移动端 Filament 自动用抽屉式侧边栏
            ->font('Inter')

            // ---- 导航分组（分组图标按要求固定）----
            ->navigationGroups([
                NavigationGroup::make('资产')->icon('heroicon-o-wallet'),
                NavigationGroup::make('服务')->icon('heroicon-o-server-stack'),
                NavigationGroup::make('用量')->icon('heroicon-o-chart-bar'),
                NavigationGroup::make('设置')->icon('heroicon-o-cog-6-tooth'),
            ])

            // ---- 登录 / 注册 / 重置密码页的中文品牌文案（RenderHook 注入，不覆盖官方页面）----
            ->renderHook(
                PanelsRenderHook::AUTH_LOGIN_FORM_BEFORE,
                fn (): View => view('filament.user.auth.tagline'),
            )
            ->renderHook(
                PanelsRenderHook::AUTH_REGISTER_FORM_BEFORE,
                fn (): View => view('filament.user.auth.tagline'),
            )
            ->renderHook(
                PanelsRenderHook::AUTH_PASSWORD_RESET_REQUEST_FORM_BEFORE,
                fn (): View => view('filament.user.auth.tagline'),
            )

            // ---- 资源 / 页面 / 组件发现 ----
            ->discoverResources(
                in: app_path('Filament/User/Resources'),
                for: 'App\\Filament\\User\\Resources',
            )
            ->discoverPages(
                in: app_path('Filament/User/Pages'),
                for: 'App\\Filament\\User\\Pages',
            )
            ->pages([
                Dashboard::class,
            ])
            ->discoverWidgets(
                in: app_path('Filament/User/Widgets'),
                for: 'App\\Filament\\User\\Widgets',
            )
            ->widgets([
                WalletStatsOverview::class,
                TrafficTrendChart::class,
                RecentPointsLedgerTable::class,
            ])

            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                VerifyCsrfToken::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->authMiddleware([
                Authenticate::class,
            ])
            // 需要邮箱验证时：打开下面一行，并让 App\Models\User 实现 MustVerifyEmail
            // ->emailVerification()
            ;
    }
}
