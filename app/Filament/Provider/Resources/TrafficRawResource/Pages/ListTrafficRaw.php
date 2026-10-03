<?php

namespace App\Filament\Provider\Resources\TrafficRawResource\Pages;

use App\Filament\Provider\Resources\TrafficRawResource;
use Filament\Resources\Pages\ListRecords;

class ListTrafficRaw extends ListRecords
{
    protected static string $resource = TrafficRawResource::class;

    protected function getHeaderActions(): array
    {
        return [];   // 只读
    }
}
