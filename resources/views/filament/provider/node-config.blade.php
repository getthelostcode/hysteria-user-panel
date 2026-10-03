{{--
    节点服务端配置展示（服务商后台节点详情页）。
    $yaml      : 已生成的 Hysteria 2 服务端配置文本
    $revealed  : 是否包含明文密钥（true 时高亮警示）
--}}
<div class="space-y-3">
    @if ($revealed)
        <div class="rounded-lg bg-danger-50 p-3 text-sm text-danger-700 ring-1 ring-danger-600/20 dark:bg-danger-500/10 dark:text-danger-400">
            当前显示的是<strong>明文密钥</strong>：请勿截图分享，部署完成后建议在列表页「重新生成密钥」轮换一次。
        </div>
    @else
        <div class="rounded-lg bg-gray-50 p-3 text-sm text-gray-600 ring-1 ring-gray-950/5 dark:bg-white/5 dark:text-gray-300">
            密钥已脱敏（保留首尾各 4 位）。需要部署用的明文请使用「查看明文配置」。
        </div>
    @endif

    <pre
        class="max-h-96 overflow-auto rounded-lg bg-gray-950 p-4 text-xs leading-relaxed text-gray-100"
        style="white-space: pre;"
    >{{ $yaml }}</pre>
</div>
