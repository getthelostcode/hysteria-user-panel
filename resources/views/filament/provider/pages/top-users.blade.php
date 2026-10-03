{{--
    用户流量排行（Top 20）。
    数据在 App\Filament\Provider\Pages\TopUsers::getRows() 里按当前服务商聚合，
    这里只负责渲染；区间切换用 wire:model.live，避免整页刷新。
--}}
<x-filament-panels::page>
    <div class="mb-4 flex items-center gap-3">
        <label class="text-sm font-medium text-gray-700 dark:text-gray-200" for="range">统计区间</label>
        <select
            id="range"
            wire:model.live="range"
            class="rounded-lg border-gray-300 bg-white text-sm shadow-sm dark:border-white/10 dark:bg-gray-900 dark:text-gray-100"
        >
            <option value="7">最近 7 天</option>
            <option value="30">最近 30 天</option>
            <option value="90">最近 90 天</option>
        </select>
    </div>

    <div class="overflow-hidden rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
        <table class="w-full divide-y divide-gray-200 text-sm dark:divide-white/10">
            <thead class="bg-gray-50 dark:bg-white/5">
                <tr>
                    <th class="px-4 py-3 text-left font-semibold text-gray-700 dark:text-gray-200">#</th>
                    <th class="px-4 py-3 text-left font-semibold text-gray-700 dark:text-gray-200">用户</th>
                    <th class="px-4 py-3 text-right font-semibold text-gray-700 dark:text-gray-200">上行</th>
                    <th class="px-4 py-3 text-right font-semibold text-gray-700 dark:text-gray-200">下行</th>
                    <th class="px-4 py-3 text-right font-semibold text-gray-700 dark:text-gray-200">合计</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100 dark:divide-white/5">
                @forelse ($rows as $i => $row)
                    <tr>
                        <td class="px-4 py-3 text-gray-500 dark:text-gray-400">{{ $i + 1 }}</td>
                        <td class="px-4 py-3 font-medium text-gray-900 dark:text-gray-100">
                            {{ $row->username ?? ('用户 #'.$row->user_id) }}
                        </td>
                        <td class="px-4 py-3 text-right text-gray-600 dark:text-gray-300">{{ \App\Support\Bytes::human($row->up) }}</td>
                        <td class="px-4 py-3 text-right text-gray-600 dark:text-gray-300">{{ \App\Support\Bytes::human($row->down) }}</td>
                        <td class="px-4 py-3 text-right font-semibold text-gray-900 dark:text-gray-100">{{ $row->total_human }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="5" class="px-4 py-10 text-center text-gray-500 dark:text-gray-400">
                            该区间暂无流量数据。
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</x-filament-panels::page>
