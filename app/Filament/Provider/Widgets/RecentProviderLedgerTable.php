<?php

namespace App\Filament\Provider\Widgets;

use App\Models\ProviderPointsLedger;
use App\Support\Decimal;
use App\Support\StatusBadge;
use Filament\Facades\Filament;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;

/**
 * 最近 10 条 Points 流水（Dashboard 表格组件）。
 * 只读，且强制限定当前服务商。
 */
class RecentProviderLedgerTable extends TableWidget
{
    protected static ?int $sort = 4;

    protected int|string|array $columnSpan = 'full';

    protected static ?string $heading = '最近 10 条 Points 流水';

    public function table(Table $table): Table
    {
        $providerId = (int) (Filament::getTenant()?->getKey() ?? 0);

        return $table
            ->query(ProviderPointsLedger::query()
                ->where('provider_id', $providerId)
                ->latest('id')
                ->limit(10))
            ->paginated(false)
            ->columns([
                Tables\Columns\TextColumn::make('created_at')->label('时间 (UTC)')->dateTime('m-d H:i'),

                Tables\Columns\TextColumn::make('biz_type')
                    ->label('类型')
                    ->badge()
                    ->formatStateUsing(fn (string $state) => StatusBadge::providerLedger($state)['label'])
                    ->color(fn (string $state) => StatusBadge::providerLedger($state)['color'])
                    ->icon(fn (string $state) => StatusBadge::providerLedger($state)['icon']),

                Tables\Columns\TextColumn::make('amount')
                    ->label('变动')
                    ->state(fn (ProviderPointsLedger $record) => ($record->direction === 'credit' ? '+' : '-').Decimal::points($record->amount, 4, ''))
                    ->color(fn (ProviderPointsLedger $record) => $record->direction === 'credit' ? 'success' : 'danger')
                    ->weight('bold')
                    ->alignEnd(),

                Tables\Columns\TextColumn::make('balance_after')
                    ->label('变动后余额')
                    ->formatStateUsing(fn ($state) => Decimal::points($state, 4, ''))
                    ->alignEnd(),

                Tables\Columns\TextColumn::make('remark')->label('备注')->wrap()->limit(30),
            ])
            ->actions([])
            ->bulkActions([]);
    }
}
