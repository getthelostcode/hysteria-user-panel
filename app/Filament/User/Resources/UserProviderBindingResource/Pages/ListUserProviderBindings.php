<?php

namespace App\Filament\User\Resources\UserProviderBindingResource\Pages;

use App\Filament\User\Resources\UserProviderBindingResource;
use Filament\Resources\Pages\ListRecords;

class ListUserProviderBindings extends ListRecords
{
    protected static string $resource = UserProviderBindingResource::class;

    protected function getHeaderActions(): array
    {
        return [];   // 绑定只能由 SwitchProviderAction 在事务里产生
    }

    public function getTitle(): string
    {
        return '我的绑定';
    }

    public function getSubheading(): ?string
    {
        return '绑定按 [生效起, 生效止) 版本化：切换服务商后旧绑定被关闭，历史流量仍归旧服务商。';
    }
}
