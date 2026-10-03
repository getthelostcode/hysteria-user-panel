<?php

namespace App\Actions;

use App\Models\ProviderNode;
use App\Models\ProviderPricingRule;
use App\Support\DbTime;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * 定价变更（**版本化：只增不改**）。
 *
 * 铁律：
 *  1. 绝不 UPDATE 已有规则的 points_per_gb / 比率 —— 历史账单必须能凭当时的规则回溯；
 *  2. 改价 = 新增一条规则 + 把被覆盖的旧规则 effective_to 截断到新规则起点；
 *  3. 同一 provider + node 的时间区间不允许重叠（open-ended 区间也要显式判断）；
 *  4. 支持定时生效：effective_from 传未来时间即可，生效前老价继续命中。
 *
 * 区间语义与全库一致：左闭右开 [effective_from, effective_to)，NULL 表示长期有效。
 *
 * 冲突判定（三种情况）：
 *  A. 新规则起点早于某条既有规则的起点  → 拒绝（会导致负区间/覆盖空洞）
 *  B. 新规则终点落在某条既有规则内部    → 拒绝（会在旧规则中挖洞，之后的流量没有价格）
 *  C. 新规则完全覆盖/接续既有规则尾部    → 允许，截断旧规则后新增
 */
class UpdatePricingAction
{
    /**
     * @param  array{node_id?:int|null, points_per_gb:string|int|float, upload_ratio?:string, download_ratio?:string,
     *               min_charge_points?:string, priority?:int, effective_from?:string|\DateTimeInterface|null,
     *               effective_to?:string|\DateTimeInterface|null, remark?:string|null}  $data
     */
    public function execute(int $providerId, array $data, ?int $operatorId = null): ProviderPricingRule
    {
        $nodeId = isset($data['node_id']) && $data['node_id'] !== '' ? (int) $data['node_id'] : null;

        // 节点覆盖价：节点必须属于当前服务商（越权防护）
        if ($nodeId !== null) {
            $owned = ProviderNode::query()->ofProvider($providerId)->whereKey($nodeId)->exists();

            if (! $owned) {
                throw ValidationException::withMessages(['node_id' => '节点不存在或不属于当前服务商。']);
            }
        }

        $from = $data['effective_from'] ?? now();
        $to = $data['effective_to'] ?? null;

        // 显式格式化到微秒：避免同秒内比较被判错（DATETIME(6)）
        $fromSql = DbTime::sql($from);
        $toSql = $to === null ? null : DbTime::sql($to);

        if ($toSql !== null && strtotime($toSql) <= strtotime($fromSql)) {
            throw ValidationException::withMessages(['effective_to' => '失效时间必须晚于生效时间。']);
        }

        return DB::transaction(function () use ($providerId, $nodeId, $data, $operatorId, $from, $to, $fromSql, $toSql) {
            // 锁定该 provider + node 的全部规则，串行化并发改价
            $existing = ProviderPricingRule::query()
                ->ofProvider($providerId)
                ->when($nodeId === null, fn ($q) => $q->whereNull('node_id'), fn ($q) => $q->where('node_id', $nodeId))
                ->orderBy('effective_from')
                ->lockForUpdate()
                ->get();

            foreach ($existing as $rule) {
                // 与新区间无交集：跳过（含「完全在新区间之后」的排期规则）
                $ruleFrom = DbTime::sql($rule->effective_from);
                $ruleTo = $rule->effective_to === null ? null : DbTime::sql($rule->effective_to);

                $overlaps = $ruleTo === null || strtotime($ruleTo) > strtotime($fromSql);

                if (! $overlaps) {
                    continue;
                }

                // 情况 A：新规则起点早于既有规则起点
                if (strtotime($fromSql) < strtotime($ruleFrom)) {
                    throw ValidationException::withMessages([
                        'effective_from' => sprintf(
                            '生效时间与既有规则冲突：已存在自 %s 起生效的规则，新规则不能更早开始。',
                            $rule->effective_from?->format('Y-m-d H:i:s') ?? '-'
                        ),
                    ]);
                }

                // 情况 B：新规则有终点，而既有规则延伸得更远（会被挖洞）
                if ($toSql !== null && ($ruleTo === null || strtotime($ruleTo) > strtotime($toSql))) {
                    throw ValidationException::withMessages([
                        'effective_to' => '失效时间落在既有规则内部，会导致该时间段之后没有可用价格；请将失效时间留空或延后。',
                    ]);
                }

                // 情况 C：截断旧规则（不修改任何价格字段，只改区间的右端点）
                $rule->update(['effective_to' => $from]);
            }

            return ProviderPricingRule::create([
                'provider_id' => $providerId,
                'node_id' => $nodeId,
                'points_per_gb' => $data['points_per_gb'],
                'upload_ratio' => $data['upload_ratio'] ?? '1',
                'download_ratio' => $data['download_ratio'] ?? '1',
                'min_charge_points' => $data['min_charge_points'] ?? '0',
                'priority' => (int) ($data['priority'] ?? 0),
                'status' => 'active',
                'effective_from' => $from,
                'effective_to' => $to,
                'created_by' => $operatorId,
                'remark' => $data['remark'] ?? null,
            ]);
        });
    }
}
