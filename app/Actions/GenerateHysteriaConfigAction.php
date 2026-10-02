<?php

namespace App\Actions;

use App\Models\ProviderNode;
use App\Models\TrafficUsageHourly;
use App\Models\User;
use App\Models\UserProviderBinding;
use App\Support\HysteriaConfig;

/**
 * 生成当前绑定的 Hysteria 连接配置。
 *
 * 说明：
 *  - 绑定是「用户 ↔ 服务商」级别，不含节点；节点在此处解析：
 *      ① 优先用该用户最近实际上报过流量的节点（体验最好）
 *      ② 否则用该服务商第一个可用节点
 *  - 认证密钥从 user_provider_bindings.auth_secret_encrypted 解密；
 *    除用户主动「显示密钥」外，一律脱敏输出。
 */
class GenerateHysteriaConfigAction
{
    public function execute(User $user, bool $revealSecret = false): HysteriaConfig
    {
        /** @var UserProviderBinding|null $binding */
        $binding = $user->currentBinding();

        if (! $binding) {
            return new HysteriaConfig(
                provider: null,
                node: null,
                externalUserId: null,
                secret: null,
            );
        }

        $provider = $binding->provider;
        $node = $this->resolveNode($user, $binding);

        return new HysteriaConfig(
            provider: $provider,
            node: $node,
            externalUserId: $binding->external_user_id,
            secret: $binding->authSecret(),
            revealed: $revealSecret,
            // SNI 缺省用节点域名；真实环境应由服务商在 provider_nodes.config 中下发
            sni: (string) ($node?->config['sni'] ?? $node?->host ?? ''),
            insecure: (bool) ($node?->config['insecure'] ?? false),
        );
    }

    /** 解析该用户在此服务商下应该使用的节点 */
    protected function resolveNode(User $user, UserProviderBinding $binding): ?ProviderNode
    {
        // ① 用户最近真实使用过的节点
        $lastNodeId = TrafficUsageHourly::query()
            ->where('user_id', $user->id)
            ->where('provider_id', $binding->provider_id)
            ->orderByDesc('period_start')
            ->value('node_id');

        if ($lastNodeId) {
            $node = ProviderNode::query()->whereKey($lastNodeId)->where('status', 'active')->first();

            if ($node) {
                return $node;
            }
        }

        // ② 该服务商第一个可用节点
        return ProviderNode::query()
            ->where('provider_id', $binding->provider_id)
            ->where('status', 'active')
            ->orderBy('id')
            ->first();
    }
}
