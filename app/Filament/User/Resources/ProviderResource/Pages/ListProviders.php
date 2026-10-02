<?php

namespace App\Filament\User\Resources\ProviderResource\Pages;

use App\Filament\User\Resources\ProviderResource;
use Filament\Resources\Pages\ListRecords;

class ListProviders extends ListRecords
{
    protected static string $resource = ProviderResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }

    public function getTitle(): string
    {
        return '服务商列表';
    }

    public function getSubheading(): ?string
    {
        return '各家服务商自行定价（多少 Hyper Points = 1GB），节点价优先于服务商默认价。';
    }
}
