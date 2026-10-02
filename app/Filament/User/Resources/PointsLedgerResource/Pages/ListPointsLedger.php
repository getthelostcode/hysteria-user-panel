<?php

namespace App\Filament\User\Resources\PointsLedgerResource\Pages;

use App\Filament\User\Resources\PointsLedgerResource;
use Filament\Resources\Pages\ListRecords;

/**
 * 积分流水列表（只读，无任何写操作入口）。
 */
class ListPointsLedger extends ListRecords
{
    protected static string $resource = PointsLedgerResource::class;

    protected function getHeaderActions(): array
    {
        return [];   // 账本不可变：没有新建/编辑/删除
    }

    public function getTitle(): string
    {
        return '积分流水';
    }

    public function getSubheading(): ?string
    {
        return '账本不可变：任何修正都以红冲分录体现，历史记录永不改写。';
    }
}
