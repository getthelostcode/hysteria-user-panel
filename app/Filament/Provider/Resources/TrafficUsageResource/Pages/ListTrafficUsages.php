<?php

namespace App\Filament\Provider\Resources\TrafficUsageResource\Pages;

use App\Filament\Provider\Resources\TrafficUsageResource;
use Filament\Resources\Pages\ListRecords;

class ListTrafficUsages extends ListRecords
{
    protected static string $resource = TrafficUsageResource::class;

    protected function getHeaderActions(): array
    {
        return [];   // 只读
    }
}
