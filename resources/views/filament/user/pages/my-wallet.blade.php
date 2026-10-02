@php
    use App\Support\Decimal;

    $wallet = $this->wallet();
    $ledgerBalance = $this->ledgerBalance();
    $consistent = bccomp($wallet['balance'], $ledgerBalance, 8) === 0;
@endphp

<x-filament-panels::page>
    {{-- 钱包快照 --}}
    <div class="grid gap-4 md:grid-cols-4">
        @foreach ([
            ['当前余额', $wallet['balance'], 'primary'],
            ['冻结', $wallet['frozen'], 'gray'],
            ['累计充值', $wallet['total_recharged'], 'success'],
            ['累计消费', $wallet['total_consumed'], 'warning'],
        ] as [$label, $value, $color])
            <x-filament::section>
                <div class="text-sm text-gray-500 dark:text-gray-400">{{ $label }}</div>
                <div class="mt-1 text-2xl font-bold text-{{ $color }}-600 dark:text-{{ $color }}-400">
                    {{ Decimal::group($value, 8) }}
                </div>
                <div class="mt-1 text-xs text-gray-400">Points</div>
            </x-filament::section>
        @endforeach
    </div>

    {{-- 账本自证：钱包是缓存，账本才是权威 --}}
    <x-filament::section>
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div>
                <div class="text-sm text-gray-500 dark:text-gray-400">按账本聚合的权威余额</div>
                <div class="mt-1 font-mono text-lg font-semibold">
                    {{ Decimal::group($ledgerBalance, 8) }} Points
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
                    <div class="font-mono {{ $entry->isCredit() ? 'text-success-600' : 'text-danger-600' }}">
                        {{ $entry->isCredit() ? '+' : '-' }}{{ Decimal::group(ltrim($entry->signedAmount(), '-'), 8) }}
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
