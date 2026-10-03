<?php

namespace App\Filament\Provider\Resources\ProviderNodeResource\Pages;

use App\Filament\Provider\Resources\ProviderNodeResource;
use App\Models\ProviderNode;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Validation\ValidationException;

class EditProviderNode extends EditRecord
{
    protected static string $resource = ProviderNodeResource::class;

    protected function getHeaderActions(): array
    {
        return [
            // 不提供删除：节点承载历史账目，只能停用/下线
            Actions\ViewAction::make()->label('详情'),
        ];
    }

    /**
     * 归属 + host/port 去重的二次校验。
     *
     * 表单规则看不全「同一服务商内 host+port 不重复」这件事（跨两列 + 需要排除自身），
     * 所以在落库前用一个明确的校验挡住，错误直接挂到字段上提示。
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        if ((int) $this->record->provider_id !== ProviderNodeResource::currentProviderId()) {
            throw ValidationException::withMessages(['data.host' => '无权修改该节点。']);
        }

        $duplicated = ProviderNode::query()
            ->ofProvider((int) $this->record->provider_id)
            ->where('host', $data['host'])
            ->where('port', $data['port'])
            ->whereKeyNot($this->record->getKey())
            ->exists();

        if ($duplicated) {
            throw ValidationException::withMessages([
                'data.host' => sprintf('节点地址 %s:%s 已存在，请更换 host 或端口。', $data['host'], $data['port']),
            ]);
        }

        return $data;
    }

    protected function getSavedNotification(): ?\Filament\Notifications\Notification
    {
        return \Filament\Notifications\Notification::make()->title('节点已更新')->success();
    }
}
