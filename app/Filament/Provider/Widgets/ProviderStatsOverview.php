<?php

namespace App\Filament\Provider\Widgets;

use App\Services\ProviderUsageStatistics;
use App\Support\Bytes;
use App\Support\Decimal;
use Filament\Facades\Filament;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * 概览卡片：今日流量 / 本月流量 / 本月应得 Points / 当前余额 / 在线节点数。
 *
 * 所有数字都限定在「当前登录服务商」范围内（provider_id 来自租户）。
 * Points 全程 bcmath 字符串，绝不用 float。
 */
class ProviderStatsOverview extends StatsOverviewWidget
{
    protected static ?int $sort = 1;

    protected int|string|array $columnSpan = 'full';

    protected function getStats(): array
    {
        $providerId = (int) (Filament::getTenant()?->getKey() ?? 0);

        if ($providerId === 0) {
            return [];
        }

        $stats = app(ProviderUsageStatistics::class);

        $today = $stats->todayTraffic($providerId);
        $month = $stats->monthlyTraffic($providerId);
        $earned = $stats->monthlyEarnedPoints($providerId);
        $balance = $stats->balance($providerId);
        $available = $stats->availablePoints($providerId);
        $nodes = $stats->nodeCounts($providerId);

        // 近 7 天应得走势（卡片内小图）
        $trend = array_slice($stats->dailyEarnedPoints($providerId, 7)['points'], -7);

        return [
            Stat::make('今日流量', Bytes::human($today['total']))
                ->description(sprintf('上行 %s / 下行 %s', Bytes::human($today['upload']), Bytes::human($today['download'])))
                ->descriptionIcon('heroicon-m-arrow-trending-up')
                ->color('info'),

            Stat::make('本月流量', Bytes::human($month['total']))
                ->description(sprintf('上行 %s / 下行 %s', Bytes::human($month['upload']), Bytes::human($month['download'])))
                ->descriptionIcon('heroicon-m-chart-bar')
                ->color('info'),

            Stat::make('本月应得 Points', Decimal::points($earned, 4))
                ->description($stats->monthlyBilledCount($providerId).' 条计费明细')
                ->descriptionIcon('heroicon-m-banknotes')
                ->chart($trend)
                ->color('success'),

            Stat::make('当前余额', Decimal::points($balance, 4))
                ->description('可结算 '.Decimal::points($available, 4))
                ->descriptionIcon('heroicon-m-wallet')
                ->color('primary'),

            Stat::make('在线节点', $nodes['online'].' / '.$nodes['total'])
                ->description(sprintf('正常 %d · 停用 %d · 离线 %d', $nodes['active'], $nodes['disabled'], $nodes['offline']))
                ->descriptionIcon('heroicon-m-signal')
                ->color($nodes['online'] > 0 ? 'success' : 'danger'),
        ];
    }
}
