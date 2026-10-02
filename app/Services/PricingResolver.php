<?php

namespace App\Services;

use App\Models\ProviderPricingRule;
use App\Models\ProviderSettlementTerm;
use App\Models\UserProviderBinding;
use DateTimeInterface;

/**
 * 定价 / 绑定 / 结算条款的「时间点解析器」。
 *
 * 计费铁律：**绝不能用当前绑定和当前价**。
 * 按流量发生时间 occurred_at / period_start 回溯，才能保证用户切换服务商后
 * 旧流量仍归旧服务商、改价后历史账单不被改写。
 *
 * 所有解析规则与 MySQL 架构师的 SQL（09_reconciliation_queries.sql）逐条对齐。
 */
class PricingResolver
{
    /**
     * 某时刻生效的定价规则。
     *
     * 匹配顺序（与 SQL 完全一致）：
     *  ① provider_id 相同
     *  ② node_id = 具体节点 或 node_id IS NULL（服务商级默认价）
     *  ③ 节点价优先 → priority 大者优先 → effective_from 新者优先 → id 大者兜底
     *  ④ 时间落在 [effective_from, effective_to)
     */
    public function ruleFor(int $providerId, ?int $nodeId, DateTimeInterface|string $at): ?ProviderPricingRule
    {
        return ProviderPricingRule::query()
            ->where('provider_id', $providerId)
            ->where(function ($query) use ($nodeId) {
                $query->whereNull('node_id');

                if ($nodeId !== null) {
                    $query->orWhere('node_id', $nodeId);
                }
            })
            ->where('status', 'active')
            ->effectiveAt($at)
            ->bestMatch()
            ->first();
    }

    /**
     * 某时刻生效的用户绑定（切换服务商后旧流量归旧服务商的关键）。
     * 时间区间同样左闭右开：[effective_from, effective_to)
     */
    public function bindingFor(int $userId, DateTimeInterface|string $at): ?UserProviderBinding
    {
        return UserProviderBinding::query()
            ->where('user_id', $userId)
            ->effectiveAt($at)
            ->orderByDesc('effective_from')
            ->orderByDesc('id')
            ->first();
    }

    /**
     * 某时刻生效的结算条款（抽成比例 / 汇率 / 冻结期）。
     * 抽成比例必须取「流量发生时刻」的条款快照，不能取最新。
     */
    public function termFor(int $providerId, DateTimeInterface|string $at): ?ProviderSettlementTerm
    {
        return ProviderSettlementTerm::query()
            ->where('provider_id', $providerId)
            ->where('status', 'active')
            ->effectiveAt($at)
            ->bestMatch()
            ->first();
    }

    /**
     * 计算某个小时桶的账单金额（纯计算，不落库）。
     *
     * @return array{
     *     billable_bytes:int, billable_gb:string,
     *     raw_points:string, user_points:string,
     *     platform_points:string, provider_points:string,
     *     commission_rate:string, rule:ProviderPricingRule, term:?ProviderSettlementTerm
     * }
     */
    public function calculate(
        int $uploadBytes,
        int $downloadBytes,
        ProviderPricingRule $rule,
        ?ProviderSettlementTerm $term,
    ): array {
        // 计费字节 = 上行 × 上行系数 + 下行 × 下行系数（四舍五入到整数字节）
        $billableBytes = (int) round(
            $uploadBytes * (float) $rule->upload_ratio + $downloadBytes * (float) $rule->download_ratio
        );

        // 1 GB = 1024^3，8 位小数
        $billableGb = bcdiv((string) $billableBytes, (string) \App\Support\Decimal::BYTES_PER_GB, 8);

        // 按量原始值
        $rawPoints = bcmul($billableGb, (string) $rule->points_per_gb, 8);

        // 单次最低扣费
        $userPoints = bccomp($rawPoints, (string) $rule->min_charge_points, 8) >= 0
            ? $rawPoints
            : (string) $rule->min_charge_points;

        $commissionRate = (string) ($term?->commission_rate ?? '0');

        // 平台抽成 = 用户扣费 × 抽成比例；服务商应得 = 用户扣费 - 平台抽成（恒等式）
        $platformPoints = bcmul($userPoints, $commissionRate, 8);
        $providerPoints = bcsub($userPoints, $platformPoints, 8);

        return [
            'billable_bytes' => $billableBytes,
            'billable_gb' => $billableGb,
            'raw_points' => $rawPoints,
            'user_points' => $userPoints,
            'platform_points' => $platformPoints,
            'provider_points' => $providerPoints,
            'commission_rate' => $commissionRate,
            'rule' => $rule,
            'term' => $term,
        ];
    }
}
