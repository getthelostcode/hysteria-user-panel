<?php

namespace App\Filament\Provider\Resources\UsageLedgerResource\Pages;

use App\Filament\Provider\Resources\UsageLedgerResource;
use Filament\Resources\Pages\ListRecords;

class ListUsageLedgers extends ListRecords
{
    protected static string $resource = UsageLedgerResource::class;

    protected function getHeaderActions(): array
    {
        return [];   // 只读
    }
}
