<?php

namespace App\Filament\User\Resources;

use App\Filament\User\Resources\PointsLedgerResource\Pages;
use App\Models\UserPointsLedger;
use App\Support\Decimal;
use Filament\Forms\Components\DatePicker;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * 积分流水（只读）。
 *
 * 数据可见性：getEloquentQuery() 强制 user_id = 当前登录用户（第一道防线），
 * 同时 UserPointsLedgerPolicy 提供第二道授权断言。
 */
class PointsLedgerResource extends Resource
{
    protected static ?string $model = UserPointsLedger::class;

    protected static ?string $navigationIcon = 'heroicon-o-clipboard-document-list';

    protected static ?string $navigationGroup = '资产';

    protected static ?string $navigationLabel = '积分流水';

    protected static ?string $modelLabel = '积分流水';

    protected static ?string $pluralModelLabel = '积分流水';

    protected static ?int $navigationSort = 2;

    /** 用户面板不做多租户，显式关闭以免混淆 */
    protected static bool $isScopedToTenant = false;

    /** 账本不可变：不允许在面板里新建 */
    public static function canCreate(): bool
    {
        return false;
    }

    /** 只能看自己的流水 */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->where('user_id', auth()->id());
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('created_at')
                    ->label('时间(UTC)')
                    ->dateTime('Y-m-d H:i:s')
                    ->sortable(),

                Tables\Columns\TextColumn::make('biz_type')
                    ->label('类型')
                    ->badge()
                    ->formatStateUsing(fn (string $state) => UserPointsLedger::bizTypeLabels()[$state] ?? $state)
                    ->color(fn (string $state) => match ($state) {
                        UserPointsLedger::BIZ_RECHARGE => 'success',
                        UserPointsLedger::BIZ_USAGE => 'warning',
                        UserPointsLedger::BIZ_REVERSAL, UserPointsLedger::BIZ_REFUND => 'danger',
                        default => 'gray',
                    }),

                Tables\Columns\TextColumn::make('signed_amount')
                    ->label('变动 Points')
                    ->state(fn (UserPointsLedger $record) => $record->signedAmount())
                    // 用字符串千分位格式化，绝不经过 float
                    ->formatStateUsing(fn (string $state) => (str_starts_with($state, '-') ? '' : '+').Decimal::group($state, 8))
                    ->color(fn (UserPointsLedger $record) => $record->isCredit() ? 'success' : 'danger')
                    ->weight('bold'),

                Tables\Columns\TextColumn::make('balance_after')
                    ->label('变动后余额')
                    ->formatStateUsing(fn ($state) => Decimal::group($state, 8))
                    ->toggleable(),

                Tables\Columns\TextColumn::make('remark')
                    ->label('备注')
                    ->wrap()
                    ->limit(48),

                Tables\Columns\TextColumn::make('biz_ref_type')
                    ->label('关联业务')
                    ->formatStateUsing(fn ($state, UserPointsLedger $record) => $state ? $state.' #'.$record->biz_ref_id : '—')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('biz_type')
                    ->label('类型')
                    ->options(UserPointsLedger::bizTypeLabels()),

                SelectFilter::make('direction')
                    ->label('方向')
                    ->options([
                        UserPointsLedger::DIRECTION_CREDIT => '收入',
                        UserPointsLedger::DIRECTION_DEBIT => '支出',
                    ]),

                Filter::make('created_at')
                    ->label('时间区间')
                    ->form([
                        DatePicker::make('from')->label('开始日期')->native(false),
                        DatePicker::make('until')->label('结束日期')->native(false),
                    ])
                    ->query(fn (Builder $query, array $data) => $query->betweenDates($data['from'] ?? null, $data['until'] ?? null)),
            ])
            ->actions([])
            ->bulkActions([])
            ->emptyStateHeading('暂无积分流水')
            ->emptyStateDescription('充值、消费、红冲都会在这里留下不可篡改的记录。');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListPointsLedger::route('/'),
        ];
    }
}
