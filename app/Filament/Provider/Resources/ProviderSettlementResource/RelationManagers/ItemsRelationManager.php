<?php

namespace App\Filament\Provider\Resources\ProviderSettlementResource\RelationManagers;

use App\Models\ProviderSettlementItem;
use App\Support\Decimal;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;

/**
 * 结算明细（usage 级，只读）。
 * 通过 provider_settlement_items 能逐条核对「这张结算单由哪些计费明细构成」。
 */
class ItemsRelationManager extends RelationManager
{
    protected static string $relationship = 'items';

    protected static ?string $title = '结算明细';

    public function table(Table $table): Table
    {
        return $table
            ->defaultSort('period_start', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('usage_ledger_id')->label('计费明细 #'),

                Tables\Columns\TextColumn::make('period_start')->label('计费小时 (UTC)')->dateTime('Y-m-d H:00'),

                Tables\Columns\TextColumn::make('user_id')->label('用户 ID'),

                Tables\Columns\TextColumn::make('node_id')->label('节点 ID'),

                Tables\Columns\TextColumn::make('billable_gb')
                    ->label('计费量 (GB)')
                    ->formatStateUsing(fn ($state) => Decimal::group($state, 4))
                    ->alignEnd(),

                Tables\Columns\TextColumn::make('platform_points_amount')
                    ->label('平台抽成')
                    ->formatStateUsing(fn ($state) => Decimal::points($state, 8, ''))
                    ->alignEnd()
                    ->toggleable(),

                Tables\Columns\TextColumn::make('provider_points_amount')
                    ->label('我应得')
                    ->formatStateUsing(fn ($state) => Decimal::points($state, 8, ''))
                    ->weight('bold')
                    ->alignEnd()
                    ->summarize(Tables\Columns\Summarizers\Sum::make()
                        ->label('合计')
                        ->formatStateUsing(fn ($state) => Decimal::points($state, 4, ''))),
            ])
            ->actions([])
            ->bulkActions([])
            ->headerActions([])
            ->emptyStateHeading('没有明细')
            ->emptyStateDescription('结算明细在发起结算时生成，一条计费明细只能被结算一次。');
    }

    /** 只读关系：不提供任何 attach/detach/create */
    public static function canViewForRecord(\Illuminate\Database\Eloquent\Model $ownerRecord, string $pageClass): bool
    {
        return (int) $ownerRecord->provider_id === (int) \Filament\Facades\Filament::getTenant()?->getKey();
    }
}
