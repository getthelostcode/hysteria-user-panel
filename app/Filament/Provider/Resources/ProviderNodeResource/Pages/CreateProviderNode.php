<?php

namespace App\Filament\Provider\Resources\ProviderNodeResource\Pages;

use App\Actions\CreateNodeAction;
use App\Filament\Provider\Resources\ProviderNodeResource;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

/**
 * 新增节点 = 走 CreateNodeAction。
 *
 * 为什么覆盖 handleRecordCreation 而不是直接用表单 create()：
 *  节点必须由服务端生成密钥并加密存储，还要做 host+port 去重；
 *  这些规则只能有一个实现入口，不能散落在表单里。
 * 新密钥只在此刻返回一次，之后任何页面都只显示脱敏串。
 */
class CreateProviderNode extends CreateRecord
{
    protected static string $resource = ProviderNodeResource::class;

    /** 本次创建生成的一次性明文密钥（只用于弹一次通知） */
    protected ?string $generatedSecret = null;

    protected function handleRecordCreation(array $data): Model
    {
        $result = app(CreateNodeAction::class)->execute(
            providerId: (int) Filament::getTenant()->getKey(),
            data: $data,
            operatorId: Filament::auth()->id(),
        );

        $this->generatedSecret = $result['secret'];

        return $result['node'];
    }

    protected function getCreatedNotification(): ?Notification
    {
        return Notification::make()
            ->title('节点已创建')
            ->body($this->generatedSecret
                ? '节点密钥（仅此一次显示，请立即保存）：'.$this->generatedSecret
                : '节点已创建。')
            ->success()
            ->persistent()
            ->send();
    }

    protected function getRedirectUrl(): string
    {
        return static::getResource()::getUrl('index');
    }
}
