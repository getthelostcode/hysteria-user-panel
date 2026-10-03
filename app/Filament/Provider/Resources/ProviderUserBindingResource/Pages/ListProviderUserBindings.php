<?php

namespace App\Filament\Provider\Resources\ProviderUserBindingResource\Pages;

use App\Filament\Provider\Resources\ProviderUserBindingResource;
use Filament\Resources\Pages\ListRecords;

class ListProviderUserBindings extends ListRecords
{
    protected static string $resource = ProviderUserBindingResource::class;

    protected function getHeaderActions(): array
    {
        return [];   // 只读
    }
}
