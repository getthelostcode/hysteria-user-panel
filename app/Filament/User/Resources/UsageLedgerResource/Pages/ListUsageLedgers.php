<?php

namespace App\Filament\User\Resources\UsageLedgerResource\Pages;

use App\Filament\User\Resources\UsageLedgerResource;
use Filament\Resources\Pages\ListRecords;

class ListUsageLedgers extends ListRecords
{
    protected static string $resource = UsageLedgerResource::class;

    protected function getHeaderActions(): array
    {
        return [];   // 账单由计费服务生成，用户不能新增/修改
    }

    public function getTitle(): string
    {
        return '计费明细';
    }

    public function getSubheading(): ?string
    {
        return '每条账单都保存了计费当时的费率快照，服务商之后改价不会影响历史账单。';
    }
}
