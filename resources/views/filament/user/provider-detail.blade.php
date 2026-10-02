{{-- 服务商详情模态框内容：节点 + 当前生效定价 --}}
<div class="space-y-4 text-sm">
    <div>
        <div class="mb-2 font-semibold">可用节点（{{ $nodes->count() }}）</div>

        @forelse ($nodes as $node)
            <div class="flex items-center justify-between border-b border-gray-100 py-1.5 dark:border-white/5">
                <div>
                    <span class="font-medium">{{ $node->name ?: $node->node_code }}</span>
                    <span class="ml-2 text-xs text-gray-400">{{ $node->region }} {{ $node->country_code }}</span>
                </div>
                <div class="font-mono text-xs text-gray-500">{{ $node->endpoint() }}</div>
            </div>
        @empty
            <div class="text-gray-400">暂无可用节点</div>
        @endforelse
    </div>

    <div>
        <div class="mb-2 font-semibold">当前生效定价</div>

        @forelse ($rules as $rule)
            <div class="flex items-center justify-between border-b border-gray-100 py-1.5 dark:border-white/5">
                <div>
                    @if ($rule->node_id)
                        <span class="rounded bg-primary-50 px-1.5 py-0.5 text-xs text-primary-700 dark:bg-primary-500/10 dark:text-primary-400">
                            节点覆盖价
                        </span>
                    @else
                        <span class="rounded bg-gray-100 px-1.5 py-0.5 text-xs text-gray-600 dark:bg-white/10">
                            服务商默认价
                        </span>
                    @endif
                    <span class="ml-2 text-xs text-gray-400">
                        系数 ↑{{ $rule->upload_ratio }} ↓{{ $rule->download_ratio }}
                        @if (bccomp((string) $rule->min_charge_points, '0', 8) > 0)
                            · 最低 {{ rtrim(rtrim((string) $rule->min_charge_points, '0'), '.') }} P
                        @endif
                    </span>
                </div>
                <div class="font-semibold">
                    {{ rtrim(rtrim((string) $rule->points_per_gb, '0'), '.') }} Points/GB
                </div>
            </div>
        @empty
            <div class="text-gray-400">该服务商暂未配置定价</div>
        @endforelse
    </div>

    <p class="text-xs text-gray-400">
        匹配规则：节点覆盖价优先于服务商默认价；同优先级下取生效时间最新的一条。
        定价是版本化的，服务商改价只新增记录，不会影响你已产生的历史账单。
    </p>
</div>
