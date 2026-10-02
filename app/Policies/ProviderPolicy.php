<?php

namespace App\Policies;

use App\Models\Provider;
use App\Models\User;

/**
 * 服务商策略：所有登录用户可浏览服务商列表（用于选择/切换），
 * 但任何人都不能在用户面板里创建或修改服务商。
 */
class ProviderPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Provider $provider): bool
    {
        return true;
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, Provider $provider): bool
    {
        return false;
    }

    public function delete(User $user, Provider $provider): bool
    {
        return false;
    }
}
