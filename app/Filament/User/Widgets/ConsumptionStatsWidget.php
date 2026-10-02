<?php

namespace App\Filament\User\Widgets;

use App\Models\TrafficUsageHourly;
use App\Services\UsageStatistics;
use App\Support\Bytes;
use App\Support\Decimal;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * 消费统计（挂在「流量用量」页头，也可放进 Dashboard）。
 *
 * 指标：本月流量 / 本月消费 Points / 当前服务商 / 待计费桶数。
 */
class ConsumptionStatsWidget extends StatsOverviewWidget
{
    protected static ?int $sort = 2;

    protected int|string|array $columnSpan = 'full';

    protected function getStats(): array
    {
        $user = auth()->user();
        $stats = app(UsageStatistics::class);

        $traffic = $stats->monthlyTrafficBytes($user);
        $consumed = $stats->monthlyConsumedPoints($user);
        $provider = $user->currentProvider();

        // 待计费桶数：让用户知道「还没出账单」的流量有多少
        $pendingBytes = (int) TrafficUsageHourly::query()
            ->ofUser($user->id)
            ->pending()
            ->sum('total_bytes');

        $pendingCount = TrafficUsageHourly::query()
            ->ofUser($user->id)
            ->pending()
            ->count();

        return [
            Stat::make('本月流量', Bytes::human($traffic['total']))
                ->description('1 GB = 1024³ bytes')
                ->descriptionIcon('heroicon-m-arrow-path')
                ->color('primary'),

            Stat::make('本月消费', Decimal::group($consumed, 2).' P')
                ->description('按计费明细汇总（不含红冲）')
                ->descriptionIcon('heroicon-m-banknotes')
                ->color('warning'),

            Stat::make('当前服务商', $provider?->name ?? '未选择')
                ->description($provider?->priceHint() ?? '尚未绑定服务商')
                ->descriptionIcon('heroicon-m-server-stack')
                ->color($provider ? 'success' : 'gray'),

            Stat::make('待计费流量', Bytes::human($pendingBytes))
                ->description($pendingCount.' 个小时桶等待计费')
                ->descriptionIcon('heroicon-m-clock')
                ->color($pendingCount > 0 ? 'warning' : 'gray'),
        ];
    }
}
