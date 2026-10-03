<?php

namespace App\Actions;

use App\Models\ProviderNode;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * 重新生成节点密钥。
 *
 * 为什么需要：密钥泄漏（贴到群里、写进公开仓库）必须能一键换新。
 * 换新后旧的 userpass 立刻失效，节点侧需要重新拉取配置 —— 因此
 * 这里把 config['auth_secret_rotated_at'] 一起写进去，方便节点侧判断是否需要重载。
 *
 * 返回值里带一次性明文密钥：页面只展示这一次，之后任何地方都只显示脱敏串。
 */
class RegenerateNodeKeyAction
{
    /**
     * @return array{node: ProviderNode, secret: string}
     */
    public function execute(int $providerId, int $nodeId): array
    {
        return DB::transaction(function () use ($providerId, $nodeId) {
            /** @var ProviderNode|null $node */
            $node = ProviderNode::query()
                ->ofProvider($providerId)     // 归属校验：绝不改动他人节点
                ->whereKey($nodeId)
                ->lockForUpdate()
                ->first();

            if (! $node) {
                throw ValidationException::withMessages(['node' => '节点不存在或不属于当前服务商。']);
            }

            $secret = Str::random(32);
            $node->setAuthSecret($secret);
            $node->save();

            return ['node' => $node, 'secret' => $secret];
        });
    }
}
