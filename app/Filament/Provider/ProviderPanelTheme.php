<?php

namespace App\Filament\Provider;

use Filament\Support\Colors\Color;

/**
 * provider（服务商后台）面板的视觉令牌。
 *
 * 与 user 面板刻意区分：
 *  - 主色用 Teal（用户面板是 Indigo），一眼能看出"我现在在服务商后台"，
 *    避免服务商误操作到用户侧的功能；
 *  - 不引入第二份 vite 主题产物：服务商后台用 Filament 默认构建 + 语义色即可，
 *    少一个构建产物就少一处上线时会漏掉的坑。
 */
final class ProviderPanelTheme
{
    public const BRAND_NAME = 'Hysteria VPN 服务商后台';

    /** 占位资源：换成正式品牌资源只改这里 */
    public const LOGO = '/images/brand/user-logo.svg';
    public const LOGO_DARK = '/images/brand/user-logo-dark.svg';
    public const FAVICON = '/images/brand/user-favicon.svg';
    public const LOGO_HEIGHT = '2rem';

    /** 语义色板（全部用 Filament 内置语义色，不写死十六进制） */
    public static function colors(): array
    {
        return [
            'primary' => Color::Teal,      // 服务商侧品牌主色
            'success' => Color::Emerald,   // 已计费 / 正常
            'warning' => Color::Amber,     // 待处理 / 结算转出
            'danger' => Color::Rose,       // 失败 / 红冲
            'info' => Color::Sky,          // 流量 / 信息
            'gray' => Color::Slate,
        ];
    }
}
