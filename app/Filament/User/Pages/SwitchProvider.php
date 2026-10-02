<?php

namespace App\Filament\User\Pages;

use App\Actions\SwitchProviderAction;
use App\Models\Provider;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * 切换服务商（卡片式选择）。
 *
 * 页面本身不写数据库：点击卡片 → SwitchProviderAction →
 * 事务内「锁定绑定 → 关旧 → 建新 → 写审计」。
 */
class SwitchProvider extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-arrows-right-left';

    protected static ?string $navigationGroup = '服务';

    protected static ?string $navigationLabel = '切换服务商';

    protected static ?string $title = '切换服务商';

    protected static ?int $navigationSort = 2;

    protected static string $view = 'filament.user.pages.switch-provider';

    /** 当前服务商 ID（用于卡片高亮） */
    public function currentProviderId(): ?int
    {
        return auth()->user()?->currentProvider()?->id;
    }

    /** 可选的服务商及其价格信息 */
    public function providers(): Collection
    {
        return Provider::query()
            ->where('status', 'active')
            ->with(['nodes' => fn ($q) => $q->where('status', 'active')->orderBy('id')])
            ->withCount(['nodes' => fn ($q) => $q->where('status', 'active')])
            ->orderBy('name')
            ->get();
    }

    public function switchTo(int $providerId): void
    {
        $provider = Provider::query()->find($providerId);

        if (! $provider) {
            Notification::make()->title('服务商不存在')->danger()->send();

            return;
        }

        try {
            $binding = app(SwitchProviderAction::class)->execute(auth()->user(), $provider);

            Notification::make()
                ->title('已切换到 '.$provider->name)
                ->body('新流量立即按新服务商价格计费；旧流量仍归原服务商。标识：'.$binding->external_user_id)
                ->success()
                ->send();
        } catch (ValidationException $exception) {
            Notification::make()
                ->title('切换失败')
                ->body(collect($exception->errors())->flatten()->first())
                ->danger()
                ->send();
        }
    }
}
