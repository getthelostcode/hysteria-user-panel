<?php

namespace App\Policies;

use App\Models\PointsOrder;
use App\Models\User;

/**
 * 订单策略：用户只能看自己的订单；**不允许直接创建**。
 * 下单必须走 App\Actions\PurchasePointsAction，否则会绕过钱包与账本的事务。
 */
class PointsOrderPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, PointsOrder $order): bool
    {
        return $user->id === $order->user_id;
    }

    public function create(User $user): bool
    {
        return false;   // 必须走 Action
    }

    public function update(User $user, PointsOrder $order): bool
    {
        return false;
    }

    public function delete(User $user, PointsOrder $order): bool
    {
        return false;
    }
}
