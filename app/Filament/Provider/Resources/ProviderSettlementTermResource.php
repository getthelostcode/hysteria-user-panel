<?php

namespace App\Filament\Provider\Resources;

use App\Filament\Provider\Concerns\ScopedToProvider;
use App\Filament\Provider\Resources\ProviderSettlementTermResource\Pages;
use App\Models\ProviderSettlementTerm;
use App\Support\Decimal;
use App\Support\StatusBadge;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

/**
 * 结算条款（**只读**，版本化）。
 *
 * 条款是平台/法务口径：汇率、抽成、打款手续费、税率、起结门槛、冻结天数。
 * 服务商只能查看；如需调整，通过列表页的「申请调整条款」动作提交申请
 * （写进 providers.metadata.settlement_term_requests，由平台侧处理）。
 *
 * 注意：生成历史账单时用的是**流量发生时刻**的条款快照，不能用最新条款倒算，
 * 所以服务商改条款不会影响任何已产生的账。
 */
class ProviderSettlementTermResource extends Resource
{
    use ScopedToProvider;

    protected static ?string $model = ProviderSettlementTerm::class;

    protected static ?string $navigationIcon = 'heroicon-o-document-check';

    protected static ?string $navigationGroup = '结算';

    protected static ?string $navigationLabel = '结算条款';

    protected static ?string $modelLabel = '结算条款';

    protected static ?string $pluralModelLabel = '结算条款';

    protected static ?int $navigationSort = 1;

    public static function canCreate(): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('effective_from', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('currency')->label('币种')->badge()->color('gray'),

                Tables\Columns\TextColumn::make('points_to_fiat_rate')
                    ->label('汇率 (1 Point = ?)')
                    ->formatStateUsing(fn ($state) => Decimal::group($state, 6)),

                Tables\Columns\TextColumn::make('commission_rate')
                    ->label('平台抽成')
                    ->formatStateUsing(fn ($state) => Decimal::group((string) ((float) $state * 100), 2).'%'),

                Tables\Columns\TextColumn::make('payout_fee_rate')
                    ->label('打款手续费')
                    ->formatStateUsing(fn ($state) => Decimal::group((string) ((float) $state * 100), 2).'%'),

                Tables\Columns\TextColumn::make('tax_rate')
                    ->label('税率')
                    ->formatStateUsing(fn ($state) => Decimal::group((string) ((float) $state * 100), 2).'%'),

                Tables\Columns\TextColumn::make('min_payout_points')
                    ->label('起结门槛')
                    ->formatStateUsing(fn ($state) => Decimal::points($state, 4, '')),

                Tables\Columns\TextColumn::make('hold_days')->label('冻结天数')->suffix(' 天'),

                Tables\Columns\TextColumn::make('settlement_cycle')
                    ->label('结算周期')
                    ->badge()
                    ->formatStateUsing(fn (string $state) => match ($state) {
                        'weekly' => '每周',
                        'biweekly' => '每两周',
                        'monthly' => '每月',
                        'manual' => '手动',
                        default => $state,
                    }),

                Tables\Columns\TextColumn::make('status')
                    ->label('状态')
                    ->badge()
                    ->formatStateUsing(fn (string $state) => $state === 'active' ? '生效' : '停用')
                    ->color(fn (string $state) => $state === 'active' ? 'success' : 'gray'),

                Tables\Columns\TextColumn::make('effective_from')
                    ->label('生效时间 (UTC)')
                    ->dateTime('Y-m-d H:i')
                    ->description(fn (ProviderSettlementTerm $record) => $record->effective_from > now() ? '待生效' : '已生效'),

                Tables\Columns\TextColumn::make('effective_to')
                    ->label('失效时间 (UTC)')
                    ->dateTime('Y-m-d H:i')
                    ->placeholder('长期'),
            ])
            ->filters([
                Tables\Filters\TernaryFilter::make('active_now')
                    ->label('当前条款')
                    ->placeholder('全部')
                    ->trueLabel('当前生效')
                    ->falseLabel('历史版本')
                    ->queries(
                        true: fn ($query) => $query->effectiveAt(now())->where('status', 'active'),
                        false: fn ($query) => $query->where(fn ($q) => $q->where('effective_to', '<=', now())->orWhere('status', 'inactive')),
                    ),
            ])
            ->actions([])
            ->bulkActions([])
            ->emptyStateHeading('暂无结算条款')
            ->emptyStateDescription('结算条款由平台配置后才能发起结算。');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListProviderSettlementTerms::route('/'),
        ];
    }
}
