@php
    use App\Support\Decimal;

    $currentProviderId = $this->currentProviderId();
@endphp

<x-filament-panels::page>
    <div class="hv-grid hv-grid-2">
        @forelse ($this->providers() as $provider)
            @php $isCurrent = $provider->id === $currentProviderId; @endphp

            <x-filament::section>
                <div class="flex items-start justify-between gap-3">
                    <div>
                        <div class="text-lg font-semibold">{{ $provider->name }}</div>
                        <div class="text-xs text-gray-400">{{ $provider->code }}</div>
                    </div>

                    @if ($isCurrent)
                        <x-filament::badge color="primary">使用中</x-filament::badge>
                    @else
                        <x-filament::badge color="gray">{{ $provider->nodes_count }} 个节点</x-filament::badge>
                    @endif
                </div>

                <div class="mt-3 grid grid-cols-2 gap-3 text-sm">
                    <div>
                        <div class="text-gray-500 dark:text-gray-400">价格</div>
                        <div class="font-semibold">{{ $provider->priceHint() }}</div>
                    </div>
                    <div>
                        <div class="text-gray-500 dark:text-gray-400">节点</div>
                        <div>
                            @foreach ($provider->nodes->take(3) as $node)
                                <span class="mr-1 inline-block rounded bg-gray-100 px-1.5 py-0.5 text-xs dark:bg-white/10">
                                    {{ $node->region ?: $node->node_code }}
                                </span>
                            @endforeach
                            @if ($provider->nodes->count() > 3)
                                <span class="text-xs text-gray-400">+{{ $provider->nodes->count() - 3 }}</span>
                            @endif
                        </div>
                    </div>
                </div>

                <div class="mt-4">
                    @if ($isCurrent)
                        <x-filament::button disabled color="gray">当前正在使用</x-filament::button>
                    @else
                        <x-filament::button
                            wire:click="switchTo({{ $provider->id }})"
                            wire:confirm="切换后：新流量按新服务商价格计费，旧流量仍归原服务商。确认切换？"
                            icon="heroicon-m-arrow-path"
                        >
                            切换到此服务商
                        </x-filament::button>
                    @endif
                </div>
            </x-filament::section>
        @empty
            <x-filament::section>
                <div class="py-6 text-center text-sm text-gray-400">暂时没有可用的服务商</div>
            </x-filament::section>
        @endforelse
    </div>

    <x-filament::section heading="切换规则">
        <ul class="list-disc space-y-1 pl-5 text-sm text-gray-600 dark:text-gray-300">
            <li>切换在事务内完成：先关闭旧绑定（写入 <code>effective_to</code>），再新建绑定，任何时刻只有一个有效绑定。</li>
            <li>新流量按新服务商的价格计费；已产生的旧流量仍然归原服务商结算，不会追溯改价。</li>
            <li>历史绑定与切换记录可在「我的绑定」中查看。</li>
        </ul>
    </x-filament::section>
</x-filament-panels::page>
