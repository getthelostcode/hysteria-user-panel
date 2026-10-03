<?php

namespace App\Policies;

use App\Models\ProviderNode;
use App\Models\ProviderUser;
use App\Models\User;
use App\Policies\Concerns\ResolvesProviderActor;

/**
 * 节点策略。
 *
 * 服务商：可以看、建、改自己的节点；**不能删除**（节点有历史账单与流量外键，
 * 删除会破坏账实一致），下线请用「停用/下线」状态而不是删除。
 * 用户：只读（用户面板会展示服务商节点信息）。
 */
class ProviderNodePolicy
{
    use ResolvesProviderActor;

    public function viewAny(User|ProviderUser $user): bool
    {
        return true;
    }

    public function view(User|ProviderUser $user, ProviderNode $node): bool
    {
        return $this->isProviderUser($user)
            ? $this->ownsProvider($user, $node->provider_id)
            : true;
    }

    public function create(User|ProviderUser $user): bool
    {
        // 新建节点必须走 App\Actions\CreateNodeAction（生成密钥 + host:port 去重）
        return $this->isProviderUser($user) && $user->provider?->isActive();
    }

    public function update(User|ProviderUser $user, ProviderNode $node): bool
    {
        return $this->ownsProvider($user, $node->provider_id);
    }

    /** 永不删除：节点承载历史账目，只能停用/下线 */
    public function delete(User|ProviderUser $user, ProviderNode $node): bool
    {
        return false;
    }

    public function deleteAny(User|ProviderUser $user): bool
    {
        return false;
    }
}
