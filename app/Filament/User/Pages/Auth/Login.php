<?php

namespace App\Filament\User\Pages\Auth;

use Filament\Facades\Filament;
use Filament\Http\Responses\Auth\Contracts\LoginResponse;
use Filament\Pages\Auth\Login as BaseLogin;

/**
 * 用户中心登录页（与服务商后台是同一个坑的另一半）。
 *
 * `url.intended` 是**跨面板共享**的 session 键：
 * 服务商后台留下的 intended（例如 /provider/xinglian）会把刚登录的用户带去 /provider/...，
 * 再被服务商面板弹到 /provider/login。这里做对称处理：
 * 只保留落在 /user 下的 intended，其它一律清掉，回落到 /user。
 */
class Login extends BaseLogin
{
    public function authenticate(): ?LoginResponse
    {
        $response = parent::authenticate();

        if ($response === null) {
            return null;
        }

        $intended = session()->get('url.intended');
        $panelPath = '/'.trim((string) Filament::getCurrentPanel()->getPath(), '/');

        $intendedPath = is_string($intended) ? (string) parse_url($intended, PHP_URL_PATH) : '';

        if ($intendedPath === '' || ! str_starts_with($intendedPath, $panelPath)) {
            session()->forget('url.intended');
        }

        return $response;
    }
}
