<?php

namespace App\Filament\Provider\Widgets;

use App\Services\ProviderUsageStatistics;
use Filament\Facades\Filament;
use Filament\Widgets\ChartWidget;

/**
 * 最近 N 天流量趋势（上下行两条线，单位 GB）。
 *
 * 数据源与计费同源（traffic_usage_hourly 按天聚合），所以图上用量与计费明细
 * 能对上（差异只来自计费系数与小时桶封口时间）。
 */
class TrafficTrendChart extends ChartWidget
{
    protected static ?int $sort = 2;

    protected int|string|array $columnSpan = 'full';

    protected static ?string $maxHeight = '280px';

    protected static ?string $heading = '流量趋势';

    public ?string $filter = '30';

    public function getHeading(): string
    {
        return '流量趋势（最近 '.$this->filter.' 天）';
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

        $data = app(ProviderUsageStatistics::class)->dailyTraffic($providerId, $days);

        return [
            'datasets' => [
                [
                    'label' => '下行 (GB)',
                    'data' => $data['download'],
                    'borderColor' => '#0ea5e9',                  // info-500
                    'backgroundColor' => 'rgba(14, 165, 233, 0.16)',
                    'fill' => true,
                    'tension' => 0.3,
                    'pointRadius' => 0,
                ],
                [
                    'label' => '上行 (GB)',
                    'data' => $data['upload'],
                    'borderColor' => '#7dd3fc',                  // info-300
                    'backgroundColor' => 'rgba(125, 211, 252, 0.12)',
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
                'y' => ['beginAtZero' => true, 'title' => ['display' => true, 'text' => 'GB']],
            ],
            'plugins' => ['legend' => ['position' => 'bottom']],
        ];
    }
}
