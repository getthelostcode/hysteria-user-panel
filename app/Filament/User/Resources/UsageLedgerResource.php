<?php

namespace App\Filament\User\Resources;

use App\Filament\User\Resources\UsageLedgerResource\Pages;
use App\Models\UsageLedger;
use App\Support\Bytes;
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
 * 计费明细（只读）。
 *
 * 每一行都是「费率快照」：points_per_gb / 上下行系数 / 平台抽成比例都记录的是
 * 计费当时的值，因此服务商之后改价不会改写历史账单。
 */
class UsageLedgerResource extends Resource
{
    protected static ?string $model = UsageLedger::class;

    protected static ?string $navigationIcon = 'heroicon-o-receipt-refund';

    protected static ?string $navigationGroup = '用量';

    protected static ?string $navigationLabel = '计费明细';

    protected static ?string $modelLabel = '计费明细';

    protected static ?string $pluralModelLabel = '计费明细';

    protected static ?int $navigationSort = 2;

    protected static bool $isScopedToTenant = false;

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->where('user_id', auth()->id())
            ->with(['provider', 'node']);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('period_start', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('period_start')
                    ->label('计费周期(UTC)')
                    ->dateTime('Y-m-d H:00')
                    ->description(fn (UsageLedger $record) => $record->period_end?->format('H:00').' 结束')
                    ->sortable(),

                Tables\Columns\TextColumn::make('provider.name')
                    ->label('服务商'),

                Tables\Columns\TextColumn::make('node.name')
                    ->label('节点')
                    ->toggleable(),

                Tables\Columns\TextColumn::make('billable_gb')
                    ->label('计费流量')
                    ->state(fn (UsageLedger $record) => Decimal::points($record->billable_gb, 4, ' GB'))
                    ->description(fn (UsageLedger $record) => '上行 '.Bytes::human($record->upload_bytes).' / 下行 '.Bytes::human($record->download_bytes))
                    ->alignRight(),

                Tables\Columns\TextColumn::make('points_per_gb')
                    ->label('单价')
                    ->state(fn (UsageLedger $record) => Decimal::points($record->points_per_gb, 4, ' P/GB'))
                    ->description(fn (UsageLedger $record) => '系数 ↑'.$record->upload_ratio.' ↓'.$record->download_ratio)
                    ->alignRight(),

                Tables\Columns\TextColumn::make('user_points_amount')
                    ->label('扣除 Points')
                    ->state(fn (UsageLedger $record) => Decimal::points($record->user_points_amount, 8, ''))
                    ->suffix(' Points')
                    ->weight('bold')
                    ->color('danger')
                    ->alignRight(),

                Tables\Columns\TextColumn::make('platform_points_amount')
                    ->label('平台抽成')
                    ->state(fn (UsageLedger $record) => Decimal::points($record->platform_points_amount, 8, ''))
                    ->suffix(' Points')
                    ->description(fn (UsageLedger $record) => '比例 '.rtrim(rtrim((string) $record->platform_commission_rate, '0'), '.'))
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->alignRight(),

                Tables\Columns\TextColumn::make('provider_points_amount')
                    ->label('服务商应得')
                    ->state(fn (UsageLedger $record) => Decimal::points($record->provider_points_amount, 8, ''))
                    ->suffix(' Points')
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->alignRight(),

                Tables\Columns\TextColumn::make('status')
                    ->label('状态')
                    ->badge()
                    // 文字 + 图标 + 颜色三重表达
                    ->formatStateUsing(fn (string $state) => StatusBadge::usage($state)['label'])
                    ->color(fn (string $state) => StatusBadge::usage($state)['color'])
                    ->icon(fn (string $state) => StatusBadge::usage($state)['icon']),
            ])
            ->filters([
                SelectFilter::make('provider_id')
                    ->label('服务商')
                    ->relationship('provider', 'name'),

                SelectFilter::make('status')
                    ->label('状态')
                    ->options(UsageLedger::statusLabels()),

                Filter::make('period_start')
                    ->label('周期区间')
                    ->form([
                        DatePicker::make('from')->label('开始日期')->native(false),
                        DatePicker::make('until')->label('结束日期')->native(false),
                    ])
                    ->query(fn (Builder $query, array $data) => $query
                        ->when($data['from'] ?? null, fn (Builder $q, $d) => $q->where('period_start', '>=', $d.' 00:00:00'))
                        ->when($data['until'] ?? null, fn (Builder $q, $d) => $q->where('period_start', '<', date('Y-m-d', strtotime($d.' +1 day')).' 00:00:00'))),
            ])
            ->actions([])
            ->bulkActions([])
            ->emptyStateHeading('暂无计费明细')
            ->emptyStateDescription('计费任务处理完流量桶后，账单会出现在这里。');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListUsageLedgers::route('/'),
        ];
    }
}
