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
            // 本月流量 → info
            Stat::make('本月流量', Bytes::human($traffic['total']))
                ->description('1 GB = 1024³ bytes')
                ->descriptionIcon('heroicon-m-arrow-path')
                ->color('info'),

            // 本月消费 → warning
            Stat::make('本月消费', Decimal::points($consumed, 2))
                ->description('按计费明细汇总（不含红冲）')
                ->descriptionIcon('heroicon-m-banknotes')
                ->color('warning'),

            // 当前服务商 → success
            Stat::make('当前服务商', $provider?->name ?? '未选择')
                ->description($provider?->priceHint() ?? '尚未绑定服务商')
                ->descriptionIcon($provider ? 'heroicon-m-check-badge' : 'heroicon-m-question-mark-circle')
                ->color($provider ? 'success' : 'gray'),

            // 待计费 → 有积压时警告色，否则灰色
            Stat::make('待计费流量', Bytes::human($pendingBytes))
                ->description($pendingCount > 0 ? $pendingCount.' 个小时桶等待计费' : '暂无待计费流量')
                ->descriptionIcon($pendingCount > 0 ? 'heroicon-m-clock' : 'heroicon-m-check-circle')
                ->color($pendingCount > 0 ? 'warning' : 'gray'),
        ];
    }
}
