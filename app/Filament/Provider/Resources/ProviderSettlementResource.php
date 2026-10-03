<?php

namespace App\Filament\Provider\Resources;

use App\Actions\RequestSettlementAction;
use App\Filament\Provider\Concerns\ScopedToProvider;
use App\Filament\Provider\Resources\ProviderSettlementResource\Pages;
use App\Filament\Provider\Resources\ProviderSettlementResource\RelationManagers;
use App\Models\ProviderSettlement;
use App\Models\ProviderUser;
use App\Support\Decimal;
use App\Support\StatusBadge;
use Filament\Facades\Filament;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Validation\ValidationException;

/**
 * 结算记录（可发起，创建后不可改）。
 *
 * 「发起结算」是本面板里唯一会动钱的写操作，因此：
 *  - 表单只收区间（period_start/period_end），金额一律由 RequestSettlementAction 现算；
 *  - 校验起结门槛、冻结期、钱包可用余额，任何一条不满足就整体回滚；
 *  - 成功后在同一个事务里写 provider_settlements / items / ledger，并扣减钱包。
 */
class ProviderSettlementResource extends Resource
{
    use ScopedToProvider;

    protected static ?string $model = ProviderSettlement::class;

    protected static ?string $navigationIcon = 'heroicon-o-banknotes';

    protected static ?string $navigationGroup = '结算';

    protected static ?string $navigationLabel = '结算记录';

    protected static ?string $modelLabel = '结算单';

    protected static ?string $pluralModelLabel = '结算记录';

    protected static ?int $navigationSort = 2;

    public static function canCreate(): bool
    {
        $user = Filament::auth()->user();

        // 能发起结算，但落库必须走 Action
        return $user instanceof ProviderUser && $user->provider?->isActive();
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('period_end', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('settlement_no')
                    ->label('结算单号')
                    ->copyable()
                    ->searchable(),

                Tables\Columns\TextColumn::make('period_start')
                    ->label('结算区间')
                    ->dateTime('Y-m-d')
                    ->description(fn (ProviderSettlement $record) => '至 '.$record->period_end?->format('Y-m-d')),

                Tables\Columns\TextColumn::make('points_amount')
                    ->label('应结 Points')
                    ->formatStateUsing(fn ($state) => Decimal::points($state, 4, ''))
                    ->weight('bold')
                    ->alignEnd(),

                Tables\Columns\TextColumn::make('commission_points')
                    ->label('平台抽成')
                    ->formatStateUsing(fn ($state) => Decimal::points($state, 4, ''))
                    ->description('计费时已扣，仅展示')
                    ->alignEnd()
                    ->toggleable(),

                Tables\Columns\TextColumn::make('payout_fee_points')
                    ->label('手续费')
                    ->formatStateUsing(fn ($state) => Decimal::points($state, 4, ''))
                    ->alignEnd()
                    ->toggleable(),

                Tables\Columns\TextColumn::make('tax_points')
                    ->label('税费')
                    ->formatStateUsing(fn ($state) => Decimal::points($state, 4, ''))
                    ->alignEnd()
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('net_points')
                    ->label('净 Points')
                    ->formatStateUsing(fn ($state) => Decimal::points($state, 4, ''))
                    ->alignEnd(),

                Tables\Columns\TextColumn::make('fiat_amount')
                    ->label('应付法币')
                    ->formatStateUsing(fn ($state, ProviderSettlement $record) => $record->fiatDisplay())
                    ->description(fn (ProviderSettlement $record) => '汇率 '.Decimal::group($record->exchange_rate, 6))
                    ->alignEnd(),

                Tables\Columns\TextColumn::make('item_count')->label('明细条数')->alignEnd()->toggleable(),

                Tables\Columns\TextColumn::make('status')
                    ->label('状态')
                    ->badge()
                    ->formatStateUsing(fn (string $state) => StatusBadge::settlement($state)['label'])
                    ->color(fn (string $state) => StatusBadge::settlement($state)['color'])
                    ->icon(fn (string $state) => StatusBadge::settlement($state)['icon']),

                Tables\Columns\TextColumn::make('requested_at')
                    ->label('发起时间 (UTC)')
                    ->dateTime('Y-m-d H:i')
                    ->placeholder('—'),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->label('状态')
                    ->multiple()
                    ->options(ProviderSettlement::statusLabels()),

                Tables\Filters\Filter::make('period')
                    ->label('结算区间')
                    ->form([
                        Forms\Components\DatePicker::make('from')->label('开始日期')->native(false),
                        Forms\Components\DatePicker::make('until')->label('结束日期')->native(false),
                    ])
                    ->query(fn ($query, array $data) => $query
                        ->when($data['from'] ?? null, fn ($q, $date) => $q->where('period_start', '>=', $date.' 00:00:00.000000'))
                        ->when($data['until'] ?? null, fn ($q, $date) => $q->where('period_end', '<=', date('Y-m-d', strtotime($date.' +1 day')).' 00:00:00.000000'))),
            ])
            ->actions([
                Tables\Actions\ViewAction::make()->label('详情'),
            ])
            ->bulkActions([])
            ->headerActions([
                self::requestSettlementAction(),
            ])
            ->emptyStateHeading('还没有结算记录')
            ->emptyStateDescription('有可结算的收益后，点右上角「发起结算」创建结算单。');
    }

    /** 发起结算的 Action（表单只收区间，金额一律服务端计算） */
    public static function requestSettlementAction(): Tables\Actions\Action
    {
        return Tables\Actions\Action::make('request_settlement')
            ->label('发起结算')
            ->icon('heroicon-o-plus-circle')
            ->modalHeading('发起结算')
            ->modalDescription('系统会汇总该区间内「已计费、未结算、已过冻结期」的收益，按当前条款计算抽成、手续费与应付法币。')
            ->form([
                Forms\Components\DatePicker::make('period_start')
                    ->label('结算区间起点 (UTC)')
                    ->required()
                    ->default(fn () => now()->startOfMonth())
                    ->native(false),

                Forms\Components\DatePicker::make('period_end')
                    ->label('结算区间终点 (UTC)')
                    ->required()
                    ->default(fn () => now())
                    ->native(false)
                    ->afterOrEqual('period_start'),
            ])
            ->action(function (array $data): void {
                try {
                    $settlement = app(RequestSettlementAction::class)->execute(
                        providerId: static::currentProviderId(),
                        periodStart: $data['period_start'].' 00:00:00.000000',
                        periodEnd: $data['period_end'].' 23:59:59.999999',
                        operatorId: Filament::auth()->id(),
                    );

                    Notification::make()
                        ->title('结算单已创建：'.$settlement->settlement_no)
                        ->body(sprintf(
                            '应结 %s Points，净额 %s Points，应付 %s',
                            Decimal::points($settlement->points_amount, 4, ''),
                            Decimal::points($settlement->net_points, 4, ''),
                            $settlement->fiatDisplay(),
                        ))
                        ->success()
                        ->persistent()
                        ->send();
                } catch (ValidationException $e) {
                    Notification::make()
                        ->title('无法发起结算')
                        ->body(collect($e->errors())->flatten()->first())
                        ->danger()
                        ->send();
                }
            });
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListProviderSettlements::route('/'),
            'view' => Pages\ViewProviderSettlement::route('/{record}'),
        ];
    }

    public static function getRelations(): array
    {
        return [
            RelationManagers\ItemsRelationManager::class,
        ];
    }
}
