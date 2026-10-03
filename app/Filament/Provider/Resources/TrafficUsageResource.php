<?php

namespace App\Filament\Provider\Resources;

use App\Filament\Provider\Concerns\ScopedToProvider;
use App\Filament\Provider\Resources\TrafficUsageResource\Pages;
use App\Models\ProviderNode;
use App\Models\TrafficUsageHourly;
use App\Models\User;
use App\Models\UserProviderBinding;
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
 * 流量用量（小时聚合桶，**只读**）。
 *
 * 只查 traffic_usage_hourly 而不是原始明细表：小时桶是计费口径的同源数据，
 * 面板数字与账单能对上；原始明细有单独页面（流量原始明细），用于排查。
 * 数据由节点上报链路写入，面板不提供任何写入口。
 */
class TrafficUsageResource extends Resource
{
    use ScopedToProvider;

    protected static ?string $model = TrafficUsageHourly::class;

    protected static ?string $navigationIcon = 'heroicon-o-chart-bar';

    protected static ?string $navigationGroup = '流量与用户';

    protected static ?string $navigationLabel = '流量用量';

    protected static ?string $modelLabel = '流量用量';

    protected static ?string $pluralModelLabel = '流量用量';

    protected static ?int $navigationSort = 1;

    /** 只读 */
    public static function canCreate(): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('period_start', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('period_start')
                    ->label('时间桶 (UTC)')
                    ->dateTime('Y-m-d H:00')
                    ->sortable(),

                Tables\Columns\TextColumn::make('node.name')
                    ->label('节点')
                    ->placeholder('—')
                    ->description(fn (TrafficUsageHourly $record) => $record->node?->node_code),

                Tables\Columns\TextColumn::make('user.username')
                    ->label('用户')
                    ->searchable()
                    ->placeholder('—'),

                Tables\Columns\TextColumn::make('upload_bytes')
                    ->label('上行')
                    ->state(fn (TrafficUsageHourly $record) => Bytes::human($record->upload_bytes))
                    ->alignEnd(),

                Tables\Columns\TextColumn::make('download_bytes')
                    ->label('下行')
                    ->state(fn (TrafficUsageHourly $record) => Bytes::human($record->download_bytes))
                    ->alignEnd(),

                Tables\Columns\TextColumn::make('total_bytes')
                    ->label('合计')
                    ->state(fn (TrafficUsageHourly $record) => Bytes::human($record->total_bytes))
                    ->weight('bold')
                    ->alignEnd()
                    ->summarize(Tables\Columns\Summarizers\Sum::make()
                        ->label('区间合计')
                        ->formatStateUsing(fn ($state) => Bytes::human((int) $state))),

                Tables\Columns\TextColumn::make('billed_status')
                    ->label('计费状态')
                    ->badge()
                    ->formatStateUsing(fn (string $state) => StatusBadge::traffic($state)['label'])
                    ->color(fn (string $state) => StatusBadge::traffic($state)['color'])
                    ->icon(fn (string $state) => StatusBadge::traffic($state)['icon']),

                Tables\Columns\TextColumn::make('raw_record_count')
                    ->label('原始条数')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('node_id')
                    ->label('节点')
                    ->options(fn (): array => ProviderNode::query()
                        ->ofProvider(static::currentProviderId())
                        ->orderBy('name')
                        ->pluck('name', 'id')
                        ->all())
                    ->searchable(),

                SelectFilter::make('user_id')
                    ->label('用户')
                    ->searchable()
                    // 只搜「绑定到本服务商」的用户，避免越权看到别家的用户
                    ->getSearchResultsUsing(fn (string $search): array => User::query()
                        ->whereIn('id', UserProviderBinding::query()
                            ->ofProvider(static::currentProviderId())
                            ->select('user_id'))
                        ->where('username', 'like', "%{$search}%")
                        ->limit(30)
                        ->pluck('username', 'id')
                        ->all())
                    ->getOptionLabelUsing(fn ($value): ?string => User::query()->whereKey($value)->value('username')),

                SelectFilter::make('billed_status')
                    ->label('计费状态')
                    ->options([
                        TrafficUsageHourly::STATUS_PENDING => '待计费',
                        TrafficUsageHourly::STATUS_BILLED => '已计费',
                        TrafficUsageHourly::STATUS_SKIPPED => '已跳过',
                        TrafficUsageHourly::STATUS_FAILED => '计费失败',
                    ]),

                Filter::make('period')
                    ->label('时间区间')
                    ->form([
                        DatePicker::make('from')->label('开始日期')->native(false),
                        DatePicker::make('until')->label('结束日期')->native(false),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return $query
                            ->when($data['from'] ?? null, fn (Builder $q, $date) => $q->where('period_start', '>=', $date.' 00:00:00.000000'))
                            ->when($data['until'] ?? null, fn (Builder $q, $date) => $q->where('period_start', '<', date('Y-m-d', strtotime($date.' +1 day')).' 00:00:00.000000'));
                    }),
            ])
            ->actions([])
            ->bulkActions([])
            ->emptyStateHeading('暂无流量数据')
            ->emptyStateDescription('节点上报流量后，这里会按小时展示上下行用量与计费状态。');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListTrafficUsages::route('/'),
        ];
    }
}
