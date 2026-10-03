<?php

namespace App\Filament\Provider\Resources\ProviderSettlementResource\Pages;

use App\Filament\Provider\Resources\ProviderSettlementResource;
use Filament\Resources\Pages\ListRecords;

class ListProviderSettlements extends ListRecords
{
    protected static string $resource = ProviderSettlementResource::class;

    /** 发起结算的入口由 Resource::table() 的 headerActions 提供 */
    protected function getHeaderActions(): array
    {
        return [];
    }
}
