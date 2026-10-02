<?php

namespace App\Filament\User\Resources;

use App\Filament\User\Resources\PointsOrderResource\Pages;
use App\Models\PointsOrder;
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
 * 我的订单（只读）。
 * 下单入口在「购买积分」，本页只做查询与追溯。
 */
class PointsOrderResource extends Resource
{
    protected static ?string $model = PointsOrder::class;

    protected static ?string $navigationIcon = 'heroicon-o-document-text';

    protected static ?string $navigationGroup = '资产';

    protected static ?string $navigationLabel = '我的订单';

    protected static ?string $modelLabel = '订单';

    protected static ?string $pluralModelLabel = '我的订单';

    protected static ?int $navigationSort = 4;

    protected static bool $isScopedToTenant = false;

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->where('user_id', auth()->id());
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('order_no')
                    ->label('订单号')
                    ->searchable()
                    ->copyable()
                    ->fontFamily('mono'),

                Tables\Columns\TextColumn::make('package.name')
                    ->label('套餐')
                    ->default('—'),

                Tables\Columns\TextColumn::make('points_amount')
                    ->label('到账 Points')
                    ->state(fn (PointsOrder $record) => Decimal::points($record->points_amount, 2, ''))
                    ->suffix(' Points')
                    ->weight('bold'),

                Tables\Columns\TextColumn::make('fiat_amount')
                    ->label('支付金额')
                    ->state(fn (PointsOrder $record) => Decimal::points($record->fiat_amount, 2, '').' '.$record->fiat_currency),

                Tables\Columns\TextColumn::make('status')
                    ->label('状态')
                    ->badge()
                    // 文字 + 图标 + 颜色三重表达
                    ->formatStateUsing(fn (string $state) => StatusBadge::order($state)['label'])
                    ->color(fn (string $state) => StatusBadge::order($state)['color'])
                    ->icon(fn (string $state) => StatusBadge::order($state)['icon']),

                Tables\Columns\TextColumn::make('paid_at')
                    ->label('支付时间(UTC)')
                    ->dateTime('Y-m-d H:i:s')
                    ->placeholder('—'),

                Tables\Columns\TextColumn::make('created_at')
                    ->label('下单时间(UTC)')
                    ->dateTime('Y-m-d H:i:s')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label('状态')
                    ->options(PointsOrder::statusLabels()),

                Filter::make('created_at')
                    ->label('下单时间区间')
                    ->form([
                        DatePicker::make('from')->label('开始日期')->native(false),
                        DatePicker::make('until')->label('结束日期')->native(false),
                    ])
                    ->query(fn (Builder $query, array $data) => $query
                        ->when($data['from'] ?? null, fn (Builder $q, $d) => $q->where('created_at', '>=', $d.' 00:00:00'))
                        ->when($data['until'] ?? null, fn (Builder $q, $d) => $q->where('created_at', '<', date('Y-m-d', strtotime($d.' +1 day')).' 00:00:00'))),
            ])
            ->actions([])
            ->bulkActions([])
            ->emptyStateHeading('还没有订单')
            ->emptyStateDescription('去「购买积分」充值后，订单会出现在这里。');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListPointsOrders::route('/'),
        ];
    }
}
