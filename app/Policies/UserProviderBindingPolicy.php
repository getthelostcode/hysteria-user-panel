<?php

namespace App\Policies;

use App\Models\ProviderUser;
use App\Models\User;
use App\Models\UserProviderBinding;
use App\Policies\Concerns\ResolvesProviderActor;

/**
 * 绑定策略（双面板）：只读自己的绑定；**不允许在 Resource 里创建/修改**。
 *
 * - 用户面板：只能看自己的绑定（切换服务商必须走 SwitchProviderAction）；
 * - 服务商后台：「我的用户」列表只能看绑定到自己服务商的用户（provider_id 匹配）。
 */
class UserProviderBindingPolicy
{
    use ResolvesProviderActor;

    public function viewAny(User|ProviderUser $user): bool
    {
        return true;
    }

    public function view(User|ProviderUser $user, UserProviderBinding $binding): bool
    {
        return $this->isProviderUser($user)
            ? $this->ownsProvider($user, $binding->provider_id)
            : $user->id === $binding->user_id;
    }

    public function create(User|ProviderUser $user): bool
    {
        return false;
    }

    public function update(User|ProviderUser $user, UserProviderBinding $binding): bool
    {
        return false;
    }

    public function delete(User|ProviderUser $user, UserProviderBinding $binding): bool
    {
        return false;
    }
}
