<?php

namespace App\Filament\User\Resources;

use App\Filament\User\Resources\PointsPackageResource\Pages;
use App\Models\PointsPackage;
use App\Support\Decimal;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;

/**
 * 购买积分：展示 points_packages，点「购买」创建 points_orders。
 *
 * 只展示 status=active 且落在有效期内的套餐；用户不能创建或修改套餐。
 * 真正下单走 App\Actions\PurchasePointsAction（不在 Resource 里直接写库）。
 */
class PointsPackageResource extends Resource
{
    protected static ?string $model = PointsPackage::class;

    protected static ?string $navigationIcon = 'heroicon-o-shopping-cart';

    protected static ?string $navigationGroup = '资产';

    protected static ?string $navigationLabel = '购买积分';

    protected static ?string $modelLabel = '积分套餐';

    protected static ?string $pluralModelLabel = '购买积分';

    protected static ?int $navigationSort = 3;

    protected static bool $isScopedToTenant = false;

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->active()->ordered();
    }

    public static function table(Table $table): Table
    {
        return $table
            ->paginated(false)
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->label('套餐')
                    ->weight('bold')
                    ->description(fn (PointsPackage $record) => $record->code),

                Tables\Columns\TextColumn::make('base_points')
                    ->label('基础 Points')
                    ->state(fn (PointsPackage $record) => Decimal::points($record->base_points, 2, ''))
                    ->suffix(' Points'),

                Tables\Columns\TextColumn::make('bonus_points')
                    ->label('赠送')
                    ->badge()
                    ->icon('heroicon-m-gift')
                    ->color('success')
                    ->state(fn (PointsPackage $record) => bccomp((string) $record->bonus_points, '0', 8) > 0
                        ? Decimal::points($record->bonus_points, 2, ' Points')
                        : '无赠送'),

                Tables\Columns\TextColumn::make('price_amount')
                    ->label('售价')
                    ->state(fn (PointsPackage $record) => Decimal::points($record->price_amount, 2, '').' '.$record->currency),

                Tables\Columns\TextColumn::make('points_per_currency')
                    ->label('性价比')
                    ->state(fn (PointsPackage $record) => $record->pointsPerCurrency())
                    ->description('每 1 单位法币可得 Points')
                    ->toggleable(),
            ])
            ->actions([
                Tables\Actions\Action::make('purchase')
                    ->label('购买')
                    ->icon('heroicon-o-bolt')
                    ->color('primary')
                    ->button()
                    ->requiresConfirmation()
                    ->modalHeading(fn (PointsPackage $record) => '确认购买「'.$record->name.'」')
                    ->modalDescription(fn (PointsPackage $record) => sprintf(
                        '将支付 %s %s，到账 %s（模拟支付，确认后立即入账）。',
                        Decimal::points($record->price_amount, 2, ''),
                        $record->currency,
                        Decimal::points($record->totalPoints(), 2),
                    ))
                    ->modalSubmitActionLabel('确认支付')
                    ->action(function (PointsPackage $record): void {
                        // 购买必须走 Action：事务内下单 + 加余额 + 写账本
                        try {
                            $order = app(\App\Actions\PurchasePointsAction::class)
                                ->execute(auth()->user(), $record);

                            Notification::make()
                                ->title('购买成功')
                                ->body(sprintf(
                                    '订单 %s 已支付，到账 %s，当前余额 %s。',
                                    $order->order_no,
                                    Decimal::points($order->points_amount, 2),
                                    Decimal::points(auth()->user()->fresh()->pointsBalance(), 2),
                                ))
                                ->success()
                                ->send();
                        } catch (ValidationException $exception) {
                            Notification::make()
                                ->title('购买失败')
                                ->body(collect($exception->errors())->flatten()->first())
                                ->danger()
                                ->send();
                        }
                    }),
            ])
            ->bulkActions([])
            ->emptyStateHeading('暂无可购买的套餐')
            ->emptyStateDescription('平台还没有上架积分套餐。');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListPointsPackages::route('/'),
        ];
    }
}
