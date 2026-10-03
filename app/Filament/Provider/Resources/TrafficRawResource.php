<?php

namespace App\Filament\Provider\Resources;

use App\Filament\Provider\Concerns\ScopedToProvider;
use App\Filament\Provider\Resources\TrafficRawResource\Pages;
use App\Models\ProviderNode;
use App\Models\TrafficRaw;
use App\Models\UserProviderBinding;
use App\Support\Bytes;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * 流量原始明细（**只读**，按月分区表）。
 *
 * 分区裁剪的硬要求：WHERE 必须直接比较分区列 occurred_at，
 * 写成 DATE(occurred_at) = ? 会全分区扫描。因此这里的筛选一律用
 * occurred_at 的区间比较，并且**默认只查最近 7 天**（可选"全部时间"，
 * 但默认状态必须是收窄的，避免一进页面就把全表扫一遍）。
 */
class TrafficRawResource extends Resource
{
    use ScopedToProvider;

    protected static ?string $model = TrafficRaw::class;

    protected static ?string $navigationIcon = 'heroicon-o-document-magnifying-glass';

    protected static ?string $navigationGroup = '流量与用户';

    protected static ?string $navigationLabel = '流量原始明细';

    protected static ?string $modelLabel = '原始明细';

    protected static ?string $pluralModelLabel = '原始明细';

    protected static ?int $navigationSort = 2;

    public static function canCreate(): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('occurred_at', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('occurred_at')
                    ->label('发生时间 (UTC)')
                    ->dateTime('Y-m-d H:i:s')
                    ->sortable(),

                Tables\Columns\TextColumn::make('session_id')
                    ->label('会话 ID')
                    ->copyable()
                    ->searchable()
                    ->limit(24),

                Tables\Columns\TextColumn::make('node.name')
                    ->label('节点')
                    ->placeholder('—'),

                Tables\Columns\TextColumn::make('user.username')
                    ->label('用户')
                    ->placeholder('—'),

                Tables\Columns\TextColumn::make('external_user_id')
                    ->label('服务商侧用户')
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('upload_bytes')
                    ->label('上行')
                    ->state(fn (TrafficRaw $record) => Bytes::human($record->upload_bytes))
                    ->alignEnd(),

                Tables\Columns\TextColumn::make('download_bytes')
                    ->label('下行')
                    ->state(fn (TrafficRaw $record) => Bytes::human($record->download_bytes))
                    ->alignEnd(),

                Tables\Columns\TextColumn::make('total_bytes')
                    ->label('合计')
                    ->state(fn (TrafficRaw $record) => Bytes::human($record->total_bytes))
                    ->weight('bold')
                    ->alignEnd(),

                Tables\Columns\TextColumn::make('source')
                    ->label('来源')
                    ->badge()
                    ->formatStateUsing(fn (string $state) => TrafficRaw::sourceLabels()[$state] ?? $state)
                    ->color(fn (string $state) => $state === 'reconcile' ? 'warning' : 'gray'),

                Tables\Columns\TextColumn::make('idempotency_key')
                    ->label('幂等键')
                    ->copyable()
                    ->limit(16)
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                // 默认 7 天：保证任何一次页面加载都不会全分区扫描
                SelectFilter::make('range')
                    ->label('时间范围')
                    ->options([
                        '1' => '最近 24 小时',
                        '7' => '最近 7 天',
                        '30' => '最近 30 天',
                        'all' => '全部时间（慢，慎用）',
                    ])
                    ->default('7')
                    ->query(function (Builder $query, array $data): Builder {
                        $value = $data['value'] ?? '7';

                        if ($value === 'all' || $value === null || $value === '') {
                            return $query;
                        }

                        return $query->where('occurred_at', '>=', now()->subDays((int) $value)->format('Y-m-d H:i:s.u'));
                    }),

                Filter::make('occurred')
                    ->label('自定义时间区间')
                    ->form([
                        DatePicker::make('from')->label('开始日期')->native(false),
                        DatePicker::make('until')->label('结束日期')->native(false),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return $query
                            ->when($data['from'] ?? null, fn (Builder $q, $date) => $q->where('occurred_at', '>=', $date.' 00:00:00.000000'))
                            ->when($data['until'] ?? null, fn (Builder $q, $date) => $q->where('occurred_at', '<', date('Y-m-d', strtotime($date.' +1 day')).' 00:00:00.000000'));
                    }),

                SelectFilter::make('node_id')
                    ->label('节点')
                    ->options(fn (): array => ProviderNode::query()
                        ->ofProvider(static::currentProviderId())
                        ->orderBy('name')
                        ->pluck('name', 'id')
                        ->all())
                    ->searchable(),

                Filter::make('session_id')
                    ->label('会话 ID')
                    ->form([
                        TextInput::make('session_id')->label('会话 ID')->placeholder('完整或部分匹配'),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when($data['session_id'] ?? null, fn (Builder $q, $sid) => $q->where('session_id', 'like', '%'.$sid.'%'))),

                SelectFilter::make('user_id')
                    ->label('用户')
                    ->searchable()
                    ->getSearchResultsUsing(fn (string $search): array => \App\Models\User::query()
                        ->whereIn('id', UserProviderBinding::query()
                            ->ofProvider(static::currentProviderId())
                            ->select('user_id'))
                        ->where('username', 'like', "%{$search}%")
                        ->limit(30)
                        ->pluck('username', 'id')
                        ->all())
                    ->getOptionLabelUsing(fn ($value): ?string => \App\Models\User::query()->whereKey($value)->value('username')),
            ])
            ->actions([])
            ->bulkActions([])
            ->emptyStateHeading('暂无原始明细')
            ->emptyStateDescription('节点上报的每一条流量都会落在这里（按月分区，默认只查最近 7 天）。');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListTrafficRaw::route('/'),
        ];
    }
}
