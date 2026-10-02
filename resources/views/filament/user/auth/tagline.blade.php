{{-- 登录 / 注册 / 重置密码页的品牌文案（中文），通过 RenderHook 注入，不覆盖 Filament 页面 --}}
<div class="text-center text-sm leading-relaxed text-gray-500 dark:text-gray-400">
    <p class="font-medium text-gray-700 dark:text-gray-200">
        {{ config('app.name', 'Hysteria VPN') }} · 用户中心
    </p>
    <p class="mt-1">
        平台只发行 <span class="font-semibold text-primary-600 dark:text-primary-400">Hyper Points</span>，
        按 Hysteria 实际流量扣费；
        <br class="hidden sm:inline" />
        各家服务商自主定价（多少 Points = 1GB），切换后新流量按新服务商计费，旧流量仍归原服务商。
    </p>
    <p class="mt-2 text-xs text-gray-400">
        1 GB = 1024³ bytes · 所有金额与 Points 均按定点十进制记账，不产生浮点误差
    </p>
</div>
