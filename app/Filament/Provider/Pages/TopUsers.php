<?php

namespace App\Filament\Provider\Pages;

use App\Filament\Provider\Concerns\ScopedToProvider;
use App\Services\ProviderUsageStatistics;
use Filament\Pages\Page;

/**
 * 用户流量排行（Top N）。
 *
 * 用自定义页面 + 简单表格而不是 Filament Table：
 * 这张表是「按 user_id 聚合后的结果」，不是某个模型的行集合，
 * 用纯查询聚合更直接，也避免为了套 Table 造一个假模型。
 * 数据同样强制限定当前服务商（provider_id）。
 */
class TopUsers extends Page
{
    use ScopedToProvider;

    protected static ?string $navigationIcon = 'heroicon-o-trophy';

    protected static ?string $navigationGroup = '流量与用户';

    protected static ?string $navigationLabel = '用户流量排行';

    protected static ?string $title = '用户流量排行';

    protected static ?int $navigationSort = 4;

    protected static string $view = 'filament.provider.pages.top-users';

    /** 统计区间（天）：7 / 30 / 90 */
    public string $range = '30';

    public function getSubheading(): ?string
    {
        return '按流量从高到低列出本服务商的用户，用于识别大流量用户与异常用量。';
    }

    /** @return array<int, object> */
    public function getRows(): array
    {
        $providerId = static::currentProviderId();

        if ($providerId === 0) {
            return [];
        }

        $days = in_array($this->range, ['7', '30', '90'], true) ? (int) $this->range : 30;

        [$from, $to] = [now()->startOfDay()->subDays($days - 1), now()->startOfDay()->addDay()];

        return app(ProviderUsageStatistics::class)
            ->topUsers($providerId, $from, $to, 20)
            ->all();
    }

    protected function getViewData(): array
    {
        return [
            'rows' => $this->getRows(),
            'range' => $this->range,
        ];
    }
}
