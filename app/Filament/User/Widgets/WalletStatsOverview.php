<?php

namespace App\Filament\User\Widgets;

use App\Services\UsageStatistics;
use App\Support\Bytes;
use App\Support\Decimal;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * Dashboard 资产概览：当前积分余额 / 本月流量 / 本月消费 Points / 当前服务商。
 */
class WalletStatsOverview extends StatsOverviewWidget
{
    protected static ?int $sort = 1;

    protected int|string|array $columnSpan = 'full';

    protected function getStats(): array
    {
        $user = auth()->user();
        $stats = app(UsageStatistics::class);

        $wallet = $user->wallet()->first();
        $balance = (string) ($wallet?->balance ?? '0');
        $available = (string) ($wallet?->available() ?? '0');

        $traffic = $stats->monthlyTrafficBytes($user);
        $consumed = $stats->monthlyConsumedPoints($user);
        $provider = $user->currentProvider();

        // 近 7 天消费走势（图表线）
        $trend = [];

        for ($i = 6; $i >= 0; $i--) {
            $day = now()->subDays($i);
            $trend[] = (float) $stats->consumedPoints($user, $day->copy()->startOfDay(), $day->copy()->startOfDay()->addDay());
        }

        return [
            Stat::make('当前积分余额', Decimal::group($balance, 2))
                ->description('可用 '.Decimal::group($available, 2).' Points')
                ->descriptionIcon('heroicon-m-wallet')
                ->color('primary'),

            Stat::make('本月流量', Bytes::human($traffic['total']))
                ->description(sprintf('上行 %s / 下行 %s', Bytes::human($traffic['upload']), Bytes::human($traffic['download'])))
                ->descriptionIcon('heroicon-m-arrow-trending-up')
                ->color('success'),

            Stat::make('本月消费', Decimal::group($consumed, 2).' P')
                ->description($stats->monthlyBilledCount($user).' 条计费记录')
                ->descriptionIcon('heroicon-m-receipt-percent')
                ->chart($trend)
                ->color('warning'),

            Stat::make('当前服务商', $provider?->name ?? '未选择')
                ->description($provider ? '绑定于 '.($user->currentBinding()?->effective_from?->format('Y-m-d H:i') ?? '—') : '去「切换服务商」选择')
                ->descriptionIcon('heroicon-m-server-stack')
                ->color($provider ? 'primary' : 'gray'),
        ];
    }
}
