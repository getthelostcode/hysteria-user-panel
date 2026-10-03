<?php

namespace App\Filament\Provider\Widgets;

use App\Services\ProviderUsageStatistics;
use App\Support\Decimal;
use Filament\Facades\Filament;
use Filament\Widgets\ChartWidget;

/**
 * 最近 N 天 Points 收益趋势（按日汇总的应得 Points）。
 *
 * 口径：usage_ledger.provider_points_amount（已计费、非红冲），
 * 与 provider_points_ledger 的 usage_earning 分录一致 —— 两条路径都在用同一批账单。
 */
class EarningsTrendChart extends ChartWidget
{
    protected static ?int $sort = 3;

    protected int|string|array $columnSpan = 'full';

    protected static ?string $maxHeight = '280px';

    public ?string $filter = '30';

    public function getHeading(): string
    {
        return 'Points 收益趋势（最近 '.$this->filter.' 天）';
    }

    public function getDescription(): ?string
    {
        $providerId = (int) (Filament::getTenant()?->getKey() ?? 0);

        if ($providerId === 0) {
            return null;
        }

        $days = (int) ($this->filter ?: 30);
        $data = app(ProviderUsageStatistics::class)->dailyEarnedPoints($providerId, $days);

        return '区间合计 '.Decimal::points($data['total'], 4);
    }

    protected function getFilters(): ?array
    {
        return [
            '7' => '最近 7 天',
            '30' => '最近 30 天',
            '90' => '最近 90 天',
        ];
    }

    protected function getData(): array
    {
        $providerId = (int) (Filament::getTenant()?->getKey() ?? 0);
        $days = (int) ($this->filter ?: 30);

        if ($providerId === 0) {
            return ['datasets' => [], 'labels' => []];
        }

        $data = app(ProviderUsageStatistics::class)->dailyEarnedPoints($providerId, $days);

        return [
            'datasets' => [
                [
                    'label' => '应得 Points',
                    'data' => $data['points'],
                    'borderColor' => '#10b981',                  // success-500
                    'backgroundColor' => 'rgba(16, 185, 129, 0.2)',
                    'fill' => true,
                    'tension' => 0.3,
                    'pointRadius' => 0,
                ],
            ],
            'labels' => $data['labels'],
        ];
    }

    protected function getType(): string
    {
        return 'line';
    }

    protected function getOptions(): array
    {
        return [
            'scales' => [
                'y' => ['beginAtZero' => true, 'title' => ['display' => true, 'text' => 'Points']],
            ],
            'plugins' => ['legend' => ['position' => 'bottom']],
        ];
    }
}
