<?php

namespace App\Actions;

use App\Models\ProviderNode;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * 启用 / 停用 / 下线 节点。
 *
 * 语义（与计费口径一致）：
 *  - active      ：正常接单
 *  - maintenance ：维护中（仍计费，流量照常上报）
 *  - disabled    ：停用 —— **不再接受新用户绑定**，但已经绑定的用户产生的流量仍然
 *                  按定价规则计费（历史账不可因停用而消失）
 *  - offline     ：节点掉线（通常由心跳/巡检自动置位，也允许人工下线）
 *
 * Action 内部强制校验 provider_id 归属：即使调用方传了别家的 node_id，
 * 也无法通过本 Action 改动他人节点。
 */
class ToggleNodeStatusAction
{
    public const ALLOWED = ['active', 'maintenance', 'disabled', 'offline'];

    public function execute(int $providerId, int $nodeId, string $status): ProviderNode
    {
        if (! in_array($status, self::ALLOWED, true)) {
            throw ValidationException::withMessages(['status' => '非法的节点状态。']);
        }

        return DB::transaction(function () use ($providerId, $nodeId, $status) {
            /** @var ProviderNode|null $node */
            $node = ProviderNode::query()
                ->ofProvider($providerId)          // 第一道：查询就限定归属
                ->whereKey($nodeId)
                ->lockForUpdate()
                ->first();

            if (! $node) {
                // 越权访问一律当作「不存在」，不泄露其它服务商的资源是否存在
                throw ValidationException::withMessages(['node' => '节点不存在或不属于当前服务商。']);
            }

            $node->update(['status' => $status]);

            return $node;
        });
    }
}
