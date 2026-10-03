<?php

namespace App\Filament\Provider\Resources\ProviderNodeResource\Pages;

use App\Filament\Provider\Resources\ProviderNodeResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListProviderNodes extends ListRecords
{
    protected static string $resource = ProviderNodeResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make()->label('新增节点'),
        ];
    }
}
