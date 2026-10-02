<?php

namespace App\Policies;

use App\Models\User;
use App\Models\UserProviderBinding;

/**
 * 绑定策略：只读自己的绑定；**不允许在 Resource 里创建/修改**。
 * 切换服务商必须走 App\Actions\SwitchProviderAction（事务内关旧建新 + 审计）。
 */
class UserProviderBindingPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, UserProviderBinding $binding): bool
    {
        return $user->id === $binding->user_id;
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, UserProviderBinding $binding): bool
    {
        return false;
    }

    public function delete(User $user, UserProviderBinding $binding): bool
    {
        return false;
    }
}
