<?php

namespace App\Filament\Provider\Resources\ProviderPricingRuleResource\Pages;

use App\Filament\Provider\Resources\ProviderPricingRuleResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListProviderPricingRules extends ListRecords
{
    protected static string $resource = ProviderPricingRuleResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make()
                ->label('新增定价（改价）')
                ->icon('heroicon-o-plus')
                ->modalHeading('新增定价规则')
                ->modalDescription('改价 = 新增一条记录，系统会自动把旧规则截断到新规则的生效时间，历史账单不受影响。'),
        ];
    }
}
