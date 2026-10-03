<?php

namespace App\Providers\Filament;

use App\Filament\Provider\Pages\Dashboard;
use App\Filament\Provider\Pages\Settings\ChangePassword;
use App\Filament\Provider\Pages\Settings\ManageApiCredentials;
use App\Filament\Provider\Pages\Settings\ManageProviderProfile;
use App\Filament\Provider\Pages\TopUsers;
use App\Filament\Provider\ProviderPanelTheme;
use App\Filament\Provider\Widgets\EarningsTrendChart;
use App\Filament\Provider\Widgets\ProviderStatsOverview;
use App\Filament\Provider\Widgets\RecentProviderLedgerTable;
use App\Filament\Provider\Widgets\RecentUsageLedgerTable;
use App\Filament\Provider\Widgets\TrafficTrendChart;
use App\Models\Provider;
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
 * 服务商后台面板（panel id = provider，路径 /provider）。
 *
 * 关键设计：
 *  1. authGuard('provider') —— 认证走**独立 guard + 独立表 provider_users**，
 *     与用户端（web guard / users 表）完全隔离，同一个浏览器可以同时登录两个面板；
 *  2. tenant(Provider::class, slugAttribute: 'code') —— 启用 Filament 原生多租户：
 *     所有 Resource 自动按当前服务商收窄，URL 形如 /provider/{provider.code}/nodes；
 *     服务商账号只能进入自己那一个租户（canAccessTenant 二次校验）；
 *  3. 登录后：/provider 由 Filament 的 RedirectToTenantController 自动跳到
 *     /provider/{自己的 code}，只有一个租户时无感；
 *  4. 导航分组按业务划分为 概览 / 节点管理 / 定价管理 / 流量与用户 / 收益 / 结算 / 设置。
 */
class ProviderPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->id('provider')
            ->path('provider')

            // ---- 认证：独立 guard，只注册登录（邮箱验证/密码重置可在平台配好 MAIL_* 后打开）----
            ->authGuard('provider')
            ->login()

            // ---- 多租户：租户 = 服务商，URL 用 provider.code（唯一）----
            ->tenant(Provider::class, slugAttribute: 'code')

            // ---- 品牌与配色 ----
            ->brandName(ProviderPanelTheme::BRAND_NAME)
            ->brandLogo(ProviderPanelTheme::LOGO)
            ->darkModeBrandLogo(ProviderPanelTheme::LOGO_DARK)
            ->brandLogoHeight(ProviderPanelTheme::LOGO_HEIGHT)
            ->favicon(ProviderPanelTheme::FAVICON)
            ->viteTheme(ProviderPanelTheme::VITE_THEME)   // 独立主题，只作用于本面板
            ->colors(ProviderPanelTheme::colors())
            ->darkMode(isForced: false)

            // ---- 顶栏常驻标识：提醒「这里是服务商后台」（动的是钱，不是自己的流量） ----
            ->renderHook(
                PanelsRenderHook::TOPBAR_START,
                fn (): View => view('filament.provider.topbar-badge'),
            )

            // ---- 布局 ----
            ->maxContentWidth(MaxWidth::Full)
            ->sidebarCollapsibleOnDesktop()
            ->font('Inter')

            // ---- 导航分组 ----
            ->navigationGroups([
                NavigationGroup::make('概览')->icon('heroicon-o-home-modern'),
                NavigationGroup::make('节点管理')->icon('heroicon-o-server-stack'),
                NavigationGroup::make('定价管理')->icon('heroicon-o-currency-yen'),
                NavigationGroup::make('流量与用户')->icon('heroicon-o-chart-bar'),
                NavigationGroup::make('收益')->icon('heroicon-o-banknotes'),
                NavigationGroup::make('结算')->icon('heroicon-o-receipt-percent'),
                NavigationGroup::make('设置')->icon('heroicon-o-cog-6-tooth'),
            ])

            // ---- 资源 / 页面 / 组件发现 ----
            ->discoverResources(
                in: app_path('Filament/Provider/Resources'),
                for: 'App\\Filament\\Provider\\Resources',
            )
            ->discoverPages(
                in: app_path('Filament/Provider/Pages'),
                for: 'App\\Filament\\Provider\\Pages',
            )
            ->pages([
                Dashboard::class,
            ])
            ->discoverWidgets(
                in: app_path('Filament/Provider/Widgets'),
                for: 'App\\Filament\\Provider\\Widgets',
            )
            ->widgets([
                ProviderStatsOverview::class,
                TrafficTrendChart::class,
                EarningsTrendChart::class,
                RecentProviderLedgerTable::class,
                RecentUsageLedgerTable::class,
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
            ]);
    }
}
