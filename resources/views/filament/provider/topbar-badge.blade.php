{{--
    服务商后台顶栏标识（注入 PanelsRenderHook::TOPBAR_START）。
    目的：服务商后台与用户中心功能相似但后果完全不同（一个动流量，一个动钱），
    顶栏常驻一个「服务商后台」标记，避免误把后台当成用户中心操作。
    样式在 resources/css/filament/provider/theme.css 的 .hv-ops-badge。
--}}
<span class="hv-ops-badge" title="当前处于服务商后台（provider 面板）">
    <x-filament::icon icon="heroicon-m-server-stack" />
    <span class="hv-ops-badge-label">服务商后台</span>
</span>
