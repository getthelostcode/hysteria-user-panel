<?php

namespace App\Filament\Provider\Resources\ProviderSettlementResource\Pages;

use App\Filament\Provider\Resources\ProviderSettlementResource;
use App\Models\ProviderSettlement;
use App\Support\Decimal;
use App\Support\StatusBadge;
use Filament\Infolists;
use Filament\Infolists\Infolist;
use Filament\Resources\Pages\ViewRecord;

class ViewProviderSettlement extends ViewRecord
{
    protected static string $resource = ProviderSettlementResource::class;

    protected function getHeaderActions(): array
    {
        // 结算单一经创建不可修改；状态流转由平台侧控制
        return [];
    }

    public function infolist(Infolist $infolist): Infolist
    {
        return $infolist->schema([
            Infolists\Components\Section::make('结算概览')->schema([
                Infolists\Components\TextEntry::make('settlement_no')->label('结算单号')->copyable(),
                Infolists\Components\TextEntry::make('status')
                    ->label('状态')
                    ->badge()
                    ->formatStateUsing(fn (string $state) => StatusBadge::settlement($state)['label'])
                    ->color(fn (string $state) => StatusBadge::settlement($state)['color'])
                    ->icon(fn (string $state) => StatusBadge::settlement($state)['icon']),
                Infolists\Components\TextEntry::make('period_start')->label('区间起点 (UTC)')->dateTime('Y-m-d H:i:s'),
                Infolists\Components\TextEntry::make('period_end')->label('区间终点 (UTC)')->dateTime('Y-m-d H:i:s'),
                Infolists\Components\TextEntry::make('requested_at')->label('发起时间 (UTC)')->dateTime('Y-m-d H:i:s')->placeholder('—'),
                Infolists\Components\TextEntry::make('item_count')->label('明细条数'),
            ])->columns(3),

            Infolists\Components\Section::make('金额拆解')
                ->description('抽成在计费时已按「流量发生时刻」的条款扣除，这里只做展示；净额 = 应结 - 手续费 - 税费。')
                ->schema([
                    Infolists\Components\TextEntry::make('points_amount')->label('应结 Points')->state(fn (ProviderSettlement $r) => Decimal::points($r->points_amount, 8, '')),
                    Infolists\Components\TextEntry::make('commission_points')->label('平台抽成 (展示)')->state(fn (ProviderSettlement $r) => Decimal::points($r->commission_points, 8, '')),
                    Infolists\Components\TextEntry::make('payout_fee_points')->label('打款手续费')->state(fn (ProviderSettlement $r) => Decimal::points($r->payout_fee_points, 8, '')),
                    Infolists\Components\TextEntry::make('tax_points')->label('税费')->state(fn (ProviderSettlement $r) => Decimal::points($r->tax_points, 8, '')),
                    Infolists\Components\TextEntry::make('net_points')->label('净 Points')->state(fn (ProviderSettlement $r) => Decimal::points($r->net_points, 8, ''))->weight('bold'),
                    Infolists\Components\TextEntry::make('fiat_amount')->label('应付法币')->state(fn (ProviderSettlement $r) => $r->fiatDisplay())->weight('bold'),
                    Infolists\Components\TextEntry::make('exchange_rate')->label('汇率快照')->state(fn (ProviderSettlement $r) => Decimal::group($r->exchange_rate, 8)),
                    Infolists\Components\TextEntry::make('currency')->label('币种'),
                ])->columns(4),
        ]);
    }
}
