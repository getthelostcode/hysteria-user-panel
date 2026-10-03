<?php

namespace App\Filament\Provider\Resources\ProviderPointsLedgerResource\Pages;

use App\Filament\Provider\Resources\ProviderPointsLedgerResource;
use Filament\Resources\Pages\ListRecords;

class ListProviderPointsLedgers extends ListRecords
{
    protected static string $resource = ProviderPointsLedgerResource::class;

    protected function getHeaderActions(): array
    {
        return [];   // 只读
    }
}
