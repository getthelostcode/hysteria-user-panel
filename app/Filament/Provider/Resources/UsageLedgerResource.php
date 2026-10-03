<?php

namespace App\Filament\Provider\Resources;

use App\Filament\Provider\Concerns\ScopedToProvider;
use App\Filament\Provider\Resources\UsageLedgerResource\Pages;
use App\Models\ProviderNode;
use App\Models\UsageLedger;
use App\Models\User;
use App\Models\UserProviderBinding;
use App\Support\Decimal;
use App\Support\StatusBadge;
use Filament\Forms\Components\DatePicker;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * 计费明细（**只读**）：服务商能看清「哪一小时、哪个用户、哪个节点、按什么价格、算出了多少钱」。
 *
 * 每行都带计费当时的费率快照（points_per_gb / 系数 / 抽成），所以事后改价绝不会改变历史行；
 * 服务商对金额有异议时应看这一页，而不是看当前定价。
 */
class UsageLedgerResource extends Resource
{
    use ScopedToProvider;

    protected static ?string $model = UsageLedger::class;

    protected static ?string $navigationIcon = 'heroicon-o-receipt-percent';

    protected static ?string $navigationGroup = '收益';

    protected static ?string $navigationLabel = '计费明细';

    protected static ?string $modelLabel = '计费明细';

    protected static ?string $pluralModelLabel = '计费明细';

    protected static ?int $navigationSort = 3;

    public static function canCreate(): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('period_start', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('ledger_no')
                    ->label('计费单号')
                    ->copyable()
                    ->searchable()
                    ->limit(28),

                Tables\Columns\TextColumn::make('period_start')
                    ->label('计费小时 (UTC)')
                    ->dateTime('Y-m-d H:00')
                    ->sortable(),

                Tables\Columns\TextColumn::make('user.username')
                    ->label('用户')
                    ->searchable()
                    ->placeholder('—'),

                Tables\Columns\TextColumn::make('node.name')
                    ->label('节点')
                    ->placeholder('—'),

                Tables\Columns\TextColumn::make('billable_gb')
                    ->label('计费量 (GB)')
                    ->formatStateUsing(fn ($state) => Decimal::group($state, 4))
                    ->alignEnd(),

                Tables\Columns\TextColumn::make('points_per_gb')
                    ->label('单价快照')
                    ->formatStateUsing(fn ($state) => Decimal::group($state, 4))
                    ->description(fn (UsageLedger $record) => sprintf('上/下系数 %s / %s', Decimal::group($record->upload_ratio, 4), Decimal::group($record->download_ratio, 4)))
                    ->toggleable(),

                Tables\Columns\TextColumn::make('user_points_amount')
                    ->label('用户扣费')
                    ->formatStateUsing(fn ($state) => Decimal::points($state, 8, ''))
                    ->alignEnd()
                    ->toggleable(),

                Tables\Columns\TextColumn::make('platform_points_amount')
                    ->label('平台抽成')
                    ->formatStateUsing(fn ($state) => Decimal::points($state, 8, ''))
                    ->description(fn (UsageLedger $record) => '费率 '.Decimal::group((string) ((float) $record->platform_commission_rate * 100), 2).'%')
                    ->alignEnd()
                    ->toggleable(),

                Tables\Columns\TextColumn::make('provider_points_amount')
                    ->label('我应得')
                    ->formatStateUsing(fn ($state) => Decimal::points($state, 8, ''))
                    ->weight('bold')
                    ->color('success')
                    ->alignEnd()
                    ->summarize(Tables\Columns\Summarizers\Sum::make()
                        ->label('合计应得')
                        ->formatStateUsing(fn ($state) => Decimal::points($state, 4, ''))),

                Tables\Columns\TextColumn::make('status')
                    ->label('状态')
                    ->badge()
                    ->formatStateUsing(fn (string $state) => StatusBadge::usage($state)['label'])
                    ->color(fn (string $state) => StatusBadge::usage($state)['color'])
                    ->icon(fn (string $state) => StatusBadge::usage($state)['icon']),

                Tables\Columns\TextColumn::make('settlement_id')
                    ->label('结算单')
                    ->placeholder('未结算')
                    ->badge()
                    ->color(fn ($state) => $state ? 'info' : 'warning')
                    ->toggleable(),
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
                    ->getSearchResultsUsing(fn (string $search): array => User::query()
                        ->whereIn('id', UserProviderBinding::query()
                            ->ofProvider(static::currentProviderId())
                            ->select('user_id'))
                        ->where('username', 'like', "%{$search}%")
                        ->limit(30)
                        ->pluck('username', 'id')
                        ->all())
                    ->getOptionLabelUsing(fn ($value): ?string => User::query()->whereKey($value)->value('username')),

                SelectFilter::make('status')
                    ->label('状态')
                    ->multiple()
                    ->options(UsageLedger::statusLabels()),

                TernaryFilter::make('settled')
                    ->label('是否已结算')
                    ->placeholder('全部')
                    ->trueLabel('已归属结算单')
                    ->falseLabel('未结算')
                    ->queries(
                        true: fn (Builder $q) => $q->whereNotNull('settlement_id'),
                        false: fn (Builder $q) => $q->whereNull('settlement_id')->where('status', UsageLedger::STATUS_CHARGED),
                    ),

                Filter::make('period')
                    ->label('计费周期')
                    ->form([
                        DatePicker::make('from')->label('开始日期')->native(false),
                        DatePicker::make('until')->label('结束日期')->native(false),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when($data['from'] ?? null, fn (Builder $q, $date) => $q->where('period_start', '>=', $date.' 00:00:00.000000'))
                        ->when($data['until'] ?? null, fn (Builder $q, $date) => $q->where('period_start', '<', date('Y-m-d', strtotime($date.' +1 day')).' 00:00:00.000000'))),
            ])
            ->actions([])
            ->bulkActions([])
            ->emptyStateHeading('暂无计费明细')
            ->emptyStateDescription('用户产生流量并完成计费后，这里会列出每一笔账单与费率快照。');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListUsageLedgers::route('/'),
        ];
    }
}
