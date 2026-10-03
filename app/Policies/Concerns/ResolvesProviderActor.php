<?php

namespace App\Policies\Concerns;

use App\Models\ProviderUser;
use App\Models\User;

/**
 * 双面板策略的公共判定。
 *
 * 为什么需要它：
 *  同一个模型会同时被「用户面板」（actor = App\Models\User）和
 *  「服务商后台」（actor = App\Models\ProviderUser，独立 guard）访问，
 *  而 Laravel 的 Gate 一个模型只能绑定一个 Policy 类。
 *  因此 Policy 方法统一声明成 `User|ProviderUser $user` 的**联合类型**，
 *  再用本 trait 判定当前 actor 是谁、以及是否拥有该资源所属的 provider。
 *
 * 已核对框架行为：Gate::canBeCalledWithUser() 在 actor 非空时直接返回 true，
 * 会把 actor 原样传给 Policy 方法，因此联合类型签名是安全的（不会被反射拒绝）。
 */
trait ResolvesProviderActor
{
    protected function isProviderUser(User|ProviderUser $user): bool
    {
        return $user instanceof ProviderUser;
    }

    /**
     * 当前 actor（服务商账号）是否拥有该 provider 的资源。
     * 用户端 actor 一律返回 false —— 用户端绝不能通过这些策略拿到服务商侧写权限。
     */
    protected function ownsProvider(User|ProviderUser $user, mixed $providerId): bool
    {
        if (! $user instanceof ProviderUser) {
            return false;
        }

        return $providerId !== null && (int) $providerId === (int) $user->provider_id;
    }
}
