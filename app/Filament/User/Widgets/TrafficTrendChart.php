<?php

namespace App\Filament\User\Widgets;

use App\Services\UsageStatistics;
use Filament\Widgets\ChartWidget;

/**
 * 最近 30 天流量趋势（上下行分两条线，单位 GB）。
 *
 * 数据来自 traffic_usage_hourly 按天聚合 —— 与账单口径同源，
 * 所以图上的用量和账单里的计费流量能对上（差异只来自计费系数与计费周期）。
 */
class TrafficTrendChart extends ChartWidget
{
    protected static ?int $sort = 2;

    protected int|string|array $columnSpan = 'full';

    /** 图表高度（px） */
    protected static ?string $maxHeight = '280px';

    public ?string $filter = '30';

    public function getHeading(): string
    {
        return '流量趋势（最近 '.$this->filter.' 天）';
    }

    protected function getFilters(): ?array
    {
        // 图表右上角的时间范围切换
        return [
            '7' => '最近 7 天',
            '30' => '最近 30 天',
            '90' => '最近 90 天',
        ];
    }

    protected function getData(): array
    {
        $days = (int) ($this->filter ?: 30);
        $data = app(UsageStatistics::class)->dailyTraffic(auth()->user(), $days);

        return [
            'datasets' => [
                [
                    'label' => '下行 (GB)',
                    'data' => $data['download'],
                    'borderColor' => '#6366f1',
                    'backgroundColor' => 'rgba(99, 102, 241, 0.15)',
                    'fill' => true,
                    'tension' => 0.3,
                    'pointRadius' => 0,
                ],
                [
                    'label' => '上行 (GB)',
                    'data' => $data['upload'],
                    'borderColor' => '#10b981',
                    'backgroundColor' => 'rgba(16, 185, 129, 0.12)',
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
                'y' => [
                    'beginAtZero' => true,
                    'title' => ['display' => true, 'text' => 'GB'],
                ],
            ],
            'plugins' => [
                'legend' => ['position' => 'bottom'],
            ],
        ];
    }
}
