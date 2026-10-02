<?php

namespace App\Filament\User\Resources\PointsOrderResource\Pages;

use App\Filament\User\Resources\PointsOrderResource;
use Filament\Resources\Pages\ListRecords;

class ListPointsOrders extends ListRecords
{
    protected static string $resource = PointsOrderResource::class;

    protected function getHeaderActions(): array
    {
        return [];   // 用户不能手工创建订单（必须走 PurchasePointsAction）
    }

    public function getTitle(): string
    {
        return '我的订单';
    }
}
