<?php

namespace App\Filament\User\Resources\TrafficUsageResource\Pages;

use App\Filament\User\Resources\TrafficUsageResource;
use App\Filament\User\Widgets\ConsumptionStatsWidget;
use App\Filament\User\Widgets\TrafficTrendChart;
use Filament\Resources\Pages\ListRecords;

/**
 * 流量用量列表：页头挂「消费统计」与「流量趋势」两个 Widget，
 * 表格负责小时级明细，图表负责 30 天趋势。
 */
class ListTrafficUsages extends ListRecords
{
    protected static string $resource = TrafficUsageResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }

    protected function getHeaderWidgets(): array
    {
        return [
            ConsumptionStatsWidget::class,
            TrafficTrendChart::class,
        ];
    }

    public function getTitle(): string
    {
        return '流量用量';
    }

    public function getSubheading(): ?string
    {
        return '按小时聚合展示；1 GB = 1024³ bytes，与计费口径完全一致。';
    }
}
