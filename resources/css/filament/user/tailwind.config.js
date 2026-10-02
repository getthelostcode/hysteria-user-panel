import preset from '../../../../vendor/filament/filament/tailwind.config.preset'

/**
 * user 面板主题的 Tailwind 配置。
 *
 * content 决定哪些 class 会被编译进 theme.css —— 必须包含：
 *   1) 本面板的 PHP 资源与自定义 Blade（否则页面里写的 Tailwind 类不会生成）
 *   2) Filament 自身的 Blade（否则组件样式会缺失）
 */
export default {
    presets: [preset],
    content: [
        './app/Filament/User/**/*.php',
        './resources/views/filament/user/**/*.blade.php',
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
