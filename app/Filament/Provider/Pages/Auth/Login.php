<?php

namespace App\Filament\Provider\Pages\Auth;

use Filament\Facades\Filament;
use Filament\Http\Responses\Auth\Contracts\LoginResponse;
use Filament\Pages\Auth\Login as BaseLogin;

/**
 * 服务商后台登录页（只覆盖一件事：登录后的跳转目标）。
 *
 * 【为什么必须自己接一手】
 * Filament 的登录响应是 `redirect()->intended(Filament::getUrl())`，
 * 而 `url.intended` 存在**整个 session 里**，两个面板（用户中心 / 服务商后台）共用同一个 session。
 * 于是出现这个真实 bug（已复现）：
 *   1) 浏览器先碰过用户面板：GET /user（未登录）→ 用户面板的 Authenticate 把
 *      `url.intended` 记成 /user；
 *   2) 同一浏览器改去 /provider/xinglian 登录服务商后台，登录成功后
 *      `intended()` 命中了残留的 /user → 浏览器被送到 /user；
 *   3) /user 需要用户端登录 → 又被弹到 /user/login。
 *   现象就是「服务商登录后被错误跳到 user/login」，数据其实一行没混。
 *
 * 处理方式：登录成功后，只保留**落在本面板路径下**的 intended（本面板内部的深链接仍然生效），
 * 其它面板残留的一律清掉，回落到本面板首页（带租户）。
 */
class Login extends BaseLogin
{
    public function authenticate(): ?LoginResponse
    {
        $response = parent::authenticate();

        if ($response === null) {
            return null;   // 限流等场景，交给父类行为
        }

        $intended = session()->get('url.intended');
        $panelPath = '/'.trim((string) Filament::getCurrentPanel()->getPath(), '/');

        $intendedPath = is_string($intended) ? (string) parse_url($intended, PHP_URL_PATH) : '';

        if ($intendedPath === '' || ! str_starts_with($intendedPath, $panelPath)) {
            // 空 / 外面板的 intended：清掉，让 redirect()->intended() 回落到 Filament::getUrl()
            session()->forget('url.intended');
        }

        return $response;
    }
}
