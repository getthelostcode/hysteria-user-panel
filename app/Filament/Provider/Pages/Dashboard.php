<?php

namespace App\Filament\Provider\Pages;

use App\Filament\Provider\Widgets\EarningsTrendChart;
use App\Filament\Provider\Widgets\ProviderStatsOverview;
use App\Filament\Provider\Widgets\RecentProviderLedgerTable;
use App\Filament\Provider\Widgets\RecentUsageLedgerTable;
use App\Filament\Provider\Widgets\TrafficTrendChart;
use Filament\Pages\Dashboard as BaseDashboard;

/**
 * 服务商后台首页（/provider/{code}）。
 *
 * 只做一件事：聚合 Dashboard 组件并给出中文标题。
 * 数据口径全部来自 traffic_usage_hourly / usage_ledger / provider_points_*，
 * 与该服务商自己的账单同源，所以「面板数字 = 账单数字」。
 */
class Dashboard extends BaseDashboard
{
    protected static ?string $navigationLabel = '概览';

    protected static ?int $navigationSort = 0;

    protected static ?string $title = '经营概览';

    public function getHeading(): string
    {
        return '经营概览';
    }

    public function getSubheading(): ?string
    {
        return '今日/本月流量、应得 Points、余额与节点在线情况一览。所有金额均为服务商自己的数据，与其他服务商完全隔离。';
    }

    public function getWidgets(): array
    {
        return [
            ProviderStatsOverview::class,
            TrafficTrendChart::class,
            EarningsTrendChart::class,
            RecentProviderLedgerTable::class,
            RecentUsageLedgerTable::class,
        ];
    }

    public function getColumns(): int|string|array
    {
        return 2;
    }
}
