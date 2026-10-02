@php
    use App\Support\Decimal;

    $wallet = $this->wallet();
    $ledgerBalance = $this->ledgerBalance();
    $consistent = bccomp($wallet['balance'], $ledgerBalance, 8) === 0;
@endphp

<x-filament-panels::page>
    {{-- 钱包快照：颜色 class 必须写字面量，Tailwind 不认运行时拼接的类名 --}}
    <div class="hv-grid hv-grid-4">
        @foreach ([
            ['label' => '当前余额', 'value' => $wallet['balance'], 'class' => 'text-primary-600 dark:text-primary-400', 'icon' => 'heroicon-m-wallet'],
            ['label' => '冻结中', 'value' => $wallet['frozen'], 'class' => 'text-gray-600 dark:text-gray-300', 'icon' => 'heroicon-m-lock-closed'],
            ['label' => '累计充值', 'value' => $wallet['total_recharged'], 'class' => 'text-success-600 dark:text-success-400', 'icon' => 'heroicon-m-arrow-down-tray'],
            ['label' => '累计消费', 'value' => $wallet['total_consumed'], 'class' => 'text-warning-600 dark:text-warning-400', 'icon' => 'heroicon-m-arrow-up-tray'],
        ] as $card)
            <x-filament::section>
                <div class="flex items-center gap-2">
                    <x-filament::icon :icon="$card['icon']" class="h-5 w-5 text-gray-400" />
                    <span class="hv-metric-label">{{ $card['label'] }}</span>
                </div>
                <div class="hv-metric mt-2 text-2xl {{ $card['class'] }}">
                    {{ Decimal::points($card['value'], 2, '') }}
                </div>
                <div class="hv-metric-unit">Points</div>
            </x-filament::section>
        @endforeach
    </div>

    {{-- 账本自证：钱包是缓存，账本才是权威 --}}
    <x-filament::section>
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div>
                <div class="text-sm text-gray-500 dark:text-gray-400">按账本聚合的权威余额</div>
                <div class="hv-metric mt-1 font-mono text-lg">
                    {{ Decimal::points($ledgerBalance, 2) }}
                </div>
            </div>

            @if ($consistent)
                <x-filament::badge color="success">钱包缓存与账本一致</x-filament::badge>
            @else
                <x-filament::badge color="danger">检测到不一致，请联系平台核查</x-filament::badge>
            @endif

            <x-filament::button
                tag="a"
                :href="\App\Filament\User\Resources\PointsPackageResource::getUrl('index')"
                icon="heroicon-m-shopping-cart"
            >
                购买积分
            </x-filament::button>
        </div>
    </x-filament::section>

    {{-- 最近流水 --}}
    <x-filament::section heading="最近流水">
        <div class="divide-y divide-gray-100 dark:divide-white/5">
            @forelse ($this->recentLedger() as $entry)
                <div class="flex items-center justify-between py-2 text-sm">
                    <div>
                        <span class="font-medium">{{ $entry->bizTypeLabel() }}</span>
                        <span class="ml-2 text-gray-400">{{ $entry->created_at?->format('Y-m-d H:i:s') }}</span>
                        @if ($entry->remark)
                            <div class="text-xs text-gray-400">{{ $entry->remark }}</div>
                        @endif
                    </div>
                    <div class="font-mono tabular-nums {{ $entry->isCredit() ? 'text-success-600 dark:text-success-400' : 'text-danger-600 dark:text-danger-400' }}">
                        {{ $entry->isCredit() ? '+' : '-' }}{{ Decimal::points(ltrim($entry->signedAmount(), '-'), 8, '') }} Points
                    </div>
                </div>
            @empty
                <div class="py-6 text-center text-sm text-gray-400">暂无流水</div>
            @endforelse
        </div>

        <div class="mt-4">
            <x-filament::button
                tag="a"
                color="gray"
                :href="\App\Filament\User\Resources\PointsLedgerResource::getUrl('index')"
            >
                查看全部流水
            </x-filament::button>
        </div>
    </x-filament::section>
</x-filament-panels::page>
