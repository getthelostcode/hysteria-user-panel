<?php

namespace App\Filament\User\Resources\PointsPackageResource\Pages;

use App\Actions\PurchasePointsAction;
use App\Filament\User\Resources\PointsPackageResource;
use App\Models\PointsPackage;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Validation\ValidationException;

/**
 * 购买积分（套餐列表）。
 *
 * 购买动作不直接写数据库：一律转交 PurchasePointsAction，
 * 由它在事务里完成「下单 → 模拟支付 → 加钱包余额 → 写账本」。
 */
class ListPointsPackages extends ListRecords
{
    protected static string $resource = PointsPackageResource::class;

    protected function getHeaderActions(): array
    {
        return [];   // 套餐由平台配置，用户不能新建
    }

    /** 供表格 Action 调用 */
    public static function purchase(int $packageId): void
    {
        $user = auth()->user();
        $package = PointsPackage::query()->findOrFail($packageId);

        try {
            $order = app(PurchasePointsAction::class)->execute($user, $package);

            Notification::make()
                ->title('购买成功')
                ->body(sprintf(
                    '订单 %s 已支付，到账 %s Points，当前余额 %s Points。',
                    $order->order_no,
                    rtrim(rtrim((string) $order->points_amount, '0'), '.'),
                    rtrim(rtrim($user->fresh()->pointsBalance(), '0'), '.'),
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
    }
}
