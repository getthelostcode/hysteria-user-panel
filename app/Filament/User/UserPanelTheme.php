<?php

namespace App\Filament\User;

use Filament\Support\Colors\Color;

/**
 * user 面板的视觉令牌集中管理。
 *
 * 只被 App\Providers\Filament\UserPanelProvider 使用，
 * 平台后台 / 服务商后台有自己的 Panel，互不影响。
 */
final class UserPanelTheme
{
    /** 品牌名（中文） */
    public const BRAND_NAME = 'Hysteria VPN 用户中心';

    /** 品牌 Logo（浅色 / 暗色）与 favicon —— 占位路径，换成正式资源即可 */
    public const LOGO = '/images/brand/user-logo.svg';
    public const LOGO_DARK = '/images/brand/user-logo-dark.svg';
    public const FAVICON = '/images/brand/user-favicon.svg';
    public const LOGO_HEIGHT = '2rem';

    /** 构建产物目录（vite build 输出） */
    public const VITE_THEME = 'resources/css/filament/user/theme.css';

    /**
     * 语义色板：全部使用 Filament 内置语义色，不写死十六进制。
     * primary 品牌主色 / success 正常 / warning 待处理 / danger 失败 / info 信息与流量
     */
    public static function colors(): array
    {
        return [
            'primary' => Color::Indigo,   // 品牌主色
            'success' => Color::Emerald,  // 正常 / 成功 / 当前服务商
            'warning' => Color::Amber,    // 待处理 / 消费
            'danger' => Color::Rose,      // 失败 / 扣费
            'info' => Color::Sky,         // 信息 / 流量
            'gray' => Color::Slate,
        ];
    }
}
