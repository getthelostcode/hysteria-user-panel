<?php

namespace App\Filament\User\Pages;

use App\Actions\GenerateHysteriaConfigAction;
use App\Support\HysteriaConfig;
use Filament\Notifications\Notification;
use Filament\Pages\Page;

/**
 * 我的连接：根据当前 active 绑定生成 Hysteria 2 客户端配置。
 *
 * 敏感信息默认脱敏；用户点「显示明文密钥」才输出完整密钥（页面级状态，不落库）。
 */
class MyConnection extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-bolt';

    protected static ?string $navigationGroup = '服务';

    protected static ?string $navigationLabel = '我的连接';

    protected static ?string $title = '我的连接';

    protected static ?int $navigationSort = 4;

    protected static string $view = 'filament.user.pages.my-connection';

    /** 是否显示明文密钥（Livewire 公共属性，随请求重建，不持久化） */
    public bool $revealSecret = false;

    public function toggleSecret(): void
    {
        $this->revealSecret = ! $this->revealSecret;
    }

    public function config(): HysteriaConfig
    {
        return app(GenerateHysteriaConfigAction::class)
            ->execute(auth()->user(), $this->revealSecret);
    }

    /** 复制到剪贴板（前端调用 navigator.clipboard，这里只做提示） */
    public function notifyCopied(string $what = '配置'): void
    {
        Notification::make()
            ->title($what.'已复制到剪贴板')
            ->success()
            ->send();
    }
}
