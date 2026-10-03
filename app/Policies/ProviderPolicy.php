<?php

namespace App\Policies;

use App\Models\Provider;
use App\Models\ProviderUser;
use App\Models\User;
use App\Policies\Concerns\ResolvesProviderActor;

/**
 * 服务商策略（双面板）。
 *
 * 用户面板：所有登录用户可浏览服务商列表（用于选择/切换），但不可创建/修改。
 * 服务商后台：只能看/改**自己**这一条记录（名称、联系方式、结算周期等资料字段），
 *             code / status / default_commission_rate 仍由平台控制（不放进表单）。
 */
class ProviderPolicy
{
    use ResolvesProviderActor;

    public function viewAny(User|ProviderUser $user): bool
    {
        return true;
    }

    public function view(User|ProviderUser $user, Provider $provider): bool
    {
        return $this->isProviderUser($user)
            ? $this->ownsProvider($user, $provider->id)
            : true;
    }

    public function create(User|ProviderUser $user): bool
    {
        return false;   // 入驻由平台审核创建
    }

    public function update(User|ProviderUser $user, Provider $provider): bool
    {
        // 服务商只能改自己的资料；用户端一律不允许
        return $this->ownsProvider($user, $provider->id);
    }

    public function delete(User|ProviderUser $user, Provider $provider): bool
    {
        return false;
    }
}
