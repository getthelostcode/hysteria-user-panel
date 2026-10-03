<?php

namespace App\Filament\Provider\Resources;

use App\Filament\Provider\Concerns\ScopedToProvider;
use App\Filament\Provider\Resources\ProviderPointsLedgerResource\Pages;
use App\Models\ProviderPointsLedger;
use App\Support\Decimal;
use App\Support\StatusBadge;
use Filament\Forms\Components\DatePicker;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Points 流水（**只读，不可变**）。
 *
 * 服务商赚的每一分都来自 usage_earning(credit)，被结算扣掉的是 settlement(debit)；
 * 修正只能红冲(reversal)，绝不允许面板改数。余额快照 balance_after 逐行可核对钱包。
 */
class ProviderPointsLedgerResource extends Resource
{
    use ScopedToProvider;

    protected static ?string $model = ProviderPointsLedger::class;

    protected static ?string $navigationIcon = 'heroicon-o-clipboard-document-list';

    protected static ?string $navigationGroup = '收益';

    protected static ?string $navigationLabel = 'Points 流水';

    protected static ?string $modelLabel = 'Points 流水';

    protected static ?string $pluralModelLabel = 'Points 流水';

    protected static ?int $navigationSort = 2;

    public static function canCreate(): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('created_at')
                    ->label('时间 (UTC)')
                    ->dateTime('Y-m-d H:i:s')
                    ->sortable(),

                Tables\Columns\TextColumn::make('biz_type')
                    ->label('类型')
                    ->badge()
                    ->formatStateUsing(fn (string $state) => StatusBadge::providerLedger($state)['label'])
                    ->color(fn (string $state) => StatusBadge::providerLedger($state)['color'])
                    ->icon(fn (string $state) => StatusBadge::providerLedger($state)['icon']),

                Tables\Columns\TextColumn::make('amount')
                    ->label('变动 Points')
                    ->state(fn (ProviderPointsLedger $record) => ($record->direction === 'credit' ? '+' : '-').Decimal::points($record->amount, 8, ''))
                    ->color(fn (ProviderPointsLedger $record) => $record->direction === 'credit' ? 'success' : 'danger')
                    ->weight('bold')
                    ->alignEnd(),

                Tables\Columns\TextColumn::make('balance_after')
                    ->label('变动后余额')
                    ->formatStateUsing(fn ($state) => Decimal::points($state, 8, ''))
                    ->alignEnd()
                    ->toggleable(),

                Tables\Columns\TextColumn::make('biz_ref_type')
                    ->label('关联业务')
                    ->formatStateUsing(fn ($state, ProviderPointsLedger $record) => $state ? $state.' #'.$record->biz_ref_id : '—')
                    ->toggleable(),

                Tables\Columns\TextColumn::make('remark')
                    ->label('备注')
                    ->wrap()
                    ->limit(48),
            ])
            ->filters([
                SelectFilter::make('biz_type')
                    ->label('类型')
                    ->multiple()
                    ->options([
                        'usage_earning' => '流量应得',
                        'settlement' => '结算转出',
                        'adjust' => '人工调整',
                        'reversal' => '红冲',
                    ]),

                SelectFilter::make('direction')
                    ->label('方向')
                    ->options(['credit' => '收入', 'debit' => '支出']),

                Filter::make('created_at')
                    ->label('时间区间')
                    ->form([
                        DatePicker::make('from')->label('开始日期')->native(false),
                        DatePicker::make('until')->label('结束日期')->native(false),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when($data['from'] ?? null, fn (Builder $q, $date) => $q->where('created_at', '>=', $date.' 00:00:00.000000'))
                        ->when($data['until'] ?? null, fn (Builder $q, $date) => $q->where('created_at', '<', date('Y-m-d', strtotime($date.' +1 day')).' 00:00:00.000000'))),
            ])
            ->actions([])
            ->bulkActions([])
            ->emptyStateHeading('暂无 Points 流水')
            ->emptyStateDescription('计费产生的应得与结算转出都会在这里留下不可修改的记录。');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListProviderPointsLedgers::route('/'),
        ];
    }
}
