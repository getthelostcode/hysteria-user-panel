import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';

export default defineConfig({
    plugins: [
        laravel({
            input: [
                'resources/css/app.css',
                'resources/js/app.js',
                // 用户 面板独立主题：
                // 只被 App\Providers\Filament\UserPanelProvider::viteTheme() 引用，
                // 因此平台后台 / 服务商后台不会加载它（主题互不影响）。
                'resources/css/filament/user/theme.css',
                // 服务商后台独立主题（provider 面板专用，含顶栏标识与更密集的表格样式）：
                'resources/css/filament/provider/theme.css',
            ],
            refresh: true,
        }),
    ],
});
