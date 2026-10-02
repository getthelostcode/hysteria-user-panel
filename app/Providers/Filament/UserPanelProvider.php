<?php

namespace App\Providers\Filament;

use App\Filament\User\Pages\Auth\EditProfile;
use App\Filament\User\Pages\Auth\Register;
use App\Filament\User\Pages\Dashboard;
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
use Filament\Support\Colors\Color;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;

/**
 * 用户后台面板。
 *
 * - panel id : user
 * - 访问路径 : /user
 * - 登录后跳转 /user
 * - 注册页 : 自定义（多一个 username 字段，对应 users.username NOT NULL UNIQUE）
 * - 资料页 : 自定义（users 表没有 name 列，改用户名/邮箱/手机号 + 改密码）
 *
 * 注意：本面板**只**服务 VPN 用户；平台运营后台与服务商后台不在本工程内。
 */
class UserPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->id('user')
            ->path('user')
            ->login()
            ->registration(Register::class)
            ->passwordReset()
            ->profile(EditProfile::class, isSimple: false)
            ->default()                                  // 访问 / 自动进入 /user
            ->brandName('Hysteria VPN')
            ->colors([
                'primary' => Color::Indigo,
                'danger' => Color::Rose,
                'success' => Color::Emerald,
                'warning' => Color::Amber,
            ])
            ->font('Inter')
            ->navigationGroups([
                // 导航分组顺序即左侧菜单顺序
                NavigationGroup::make()->label('资产'),
                NavigationGroup::make()->label('服务'),
                NavigationGroup::make()->label('用量'),
                NavigationGroup::make()->label('设置'),
            ])
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
            // 需要邮箱验证时取消下一行注释即可（users.email_verified_at 已由补充迁移创建）
            // ->emailVerification()
            ;
    }
}
