<?php

namespace App\Filament\User\Resources;

use App\Filament\User\Resources\TrafficUsageResource\Pages;
use App\Models\TrafficUsageHourly;
use App\Support\Bytes;
use App\Support\StatusBadge;
use Filament\Forms\Components\DatePicker;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * 流量用量（只读，按小时聚合）。
 *
 * 数据源是 traffic_usage_hourly（小时桶），不是原始明细表 traffic_raw ——
 * 原始表按月分区且量级大，用户面板不需要扫它。
 */
class TrafficUsageResource extends Resource
{
    protected static ?string $model = TrafficUsageHourly::class;

    protected static ?string $navigationIcon = 'heroicon-o-chart-bar';

    protected static ?string $navigationGroup = '用量';

    protected static ?string $navigationLabel = '流量用量';

    protected static ?string $modelLabel = '流量记录';

    protected static ?string $pluralModelLabel = '流量用量';

    protected static ?int $navigationSort = 1;

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
                    ->label('时间(UTC)')
                    ->dateTime('Y-m-d H:00')
                    ->sortable(),

                Tables\Columns\TextColumn::make('provider.name')
                    ->label('服务商')
                    ->toggleable(),

                Tables\Columns\TextColumn::make('node.name')
                    ->label('节点')
                    ->description(fn (TrafficUsageHourly $record) => $record->node?->region),

                Tables\Columns\TextColumn::make('upload_bytes')
                    ->label('上行')
                    ->state(fn (TrafficUsageHourly $record) => Bytes::human($record->upload_bytes))
                    ->alignRight(),

                Tables\Columns\TextColumn::make('download_bytes')
                    ->label('下行')
                    ->state(fn (TrafficUsageHourly $record) => Bytes::human($record->download_bytes))
                    ->alignRight(),

                Tables\Columns\TextColumn::make('total_bytes')
                    ->label('合计')
                    ->state(fn (TrafficUsageHourly $record) => Bytes::human($record->total_bytes))
                    ->weight('bold')
                    ->alignRight(),

                Tables\Columns\TextColumn::make('billed_status')
                    ->label('计费状态')
                    ->badge()
                    // 文字 + 图标 + 颜色三重表达
                    ->formatStateUsing(fn (string $state) => StatusBadge::traffic($state)['label'])
                    ->color(fn (string $state) => StatusBadge::traffic($state)['color'])
                    ->icon(fn (string $state) => StatusBadge::traffic($state)['icon']),
            ])
            ->filters([
                SelectFilter::make('provider_id')
                    ->label('服务商')
                    ->relationship('provider', 'name'),

                SelectFilter::make('billed_status')
                    ->label('计费状态')
                    ->options([
                        'pending' => '待计费',
                        'billed' => '已计费',
                        'failed' => '计费失败',
                    ]),

                Filter::make('period_start')
                    ->label('时间区间')
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
            ->emptyStateHeading('暂无流量记录')
            ->emptyStateDescription('服务商节点上报流量后，这里会按小时展示用量。');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListTrafficUsages::route('/'),
        ];
    }
}
