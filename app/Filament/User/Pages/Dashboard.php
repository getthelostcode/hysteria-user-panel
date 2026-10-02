<?php

namespace App\Filament\User\Pages;

use App\Filament\User\Widgets\RecentPointsLedgerTable;
use App\Filament\User\Widgets\TrafficTrendChart;
use App\Filament\User\Widgets\WalletStatsOverview;
use Filament\Pages\Dashboard as BaseDashboard;

/**
 * 用户后台首页（登录后跳转到 /user）。
 *
 * 三块内容：
 *  1. WalletStatsOverview   —— 余额 / 本月流量 / 本月消费 / 当前服务商
 *  2. TrafficTrendChart     —— 最近 30 天流量趋势
 *  3. RecentPointsLedgerTable —— 最近 10 条积分流水
 */
class Dashboard extends BaseDashboard
{
    protected static ?string $title = '概览';

    protected static ?string $navigationLabel = '概览';

    protected static ?string $navigationIcon = 'heroicon-o-home';

    public function getWidgets(): array
    {
        return [
            WalletStatsOverview::class,
            TrafficTrendChart::class,
            RecentPointsLedgerTable::class,
        ];
    }

    /** 每行 2 列，图表占满整行的效果更好 */
    public function getColumns(): int|string|array
    {
        return 2;
    }
}
