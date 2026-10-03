import preset from '../../../../vendor/filament/filament/tailwind.config.preset'

/**
 * 服务商后台主题的 Tailwind 配置。
 *
 * content 决定哪些 class 会被编译进 theme.css —— 必须包含：
 *   1) 本面板的 PHP 资源（Resource / Page / Widget 里写的 Tailwind 类）
 *   2) 本面板的自定义 Blade（节点配置弹窗、流量排行、设置页）
 *   3) Filament 自身的 Blade（否则组件样式会缺失）
 *
 * 注意：App 下所有 "Filament/Provider/**.php" 与 "views/filament/provider/**.blade.php"
 * 都要覆盖到，漏一处就会出现"某个页面样式突然缺失"的诡异现象。
 */
export default {
    presets: [preset],
    content: [
        './app/Filament/Provider/**/*.php',
        './resources/views/filament/provider/**/*.blade.php',
        './vendor/filament/**/*.blade.php',
    ],
    theme: {
        extend: {
            borderRadius: {
                card: 'var(--hv-radius-card)',
            },
            boxShadow: {
                card: 'var(--hv-shadow-card)',
            },
        },
    },
}
