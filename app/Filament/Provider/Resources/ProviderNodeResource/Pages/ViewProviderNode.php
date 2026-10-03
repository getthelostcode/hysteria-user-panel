<?php

namespace App\Filament\Provider\Resources\ProviderNodeResource\Pages;

use App\Actions\GenerateNodeConfigAction;
use App\Filament\Provider\Resources\ProviderNodeResource;
use App\Models\ProviderNode;
use Filament\Actions;
use Filament\Resources\Pages\ViewRecord;

class ViewProviderNode extends ViewRecord
{
    protected static string $resource = ProviderNodeResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\EditAction::make()->label('编辑'),

            // 脱敏配置：可放心截图 / 贴到工单
            Actions\Action::make('config')
                ->label('查看节点配置')
                ->icon('heroicon-o-document-text')
                ->modalHeading(fn () => '节点服务端配置（密钥已脱敏）')
                ->modalSubmitAction(false)
                ->modalCancelActionLabel('关闭')
                ->modalContent(function (): \Illuminate\Contracts\View\View {
                    /** @var ProviderNode $node */
                    $node = $this->record;

                    return view('filament.provider.node-config', [
                        'yaml' => app(GenerateNodeConfigAction::class)->execute($node, revealSecret: false),
                        'revealed' => false,
                    ]);
                }),

            // 明文配置：需要二次确认，避免随手一点就把密钥暴露出来
            Actions\Action::make('reveal_config')
                ->label('查看明文配置')
                ->icon('heroicon-o-eye')
                ->color('danger')
                ->requiresConfirmation()
                ->modalHeading('显示明文密钥')
                ->modalDescription('明文密钥仅用于节点侧部署，请勿截图外传。')
                ->modalSubmitAction(false)
                ->modalCancelActionLabel('关闭')
                ->modalContent(function (): \Illuminate\Contracts\View\View {
                    /** @var ProviderNode $node */
                    $node = $this->record;

                    return view('filament.provider.node-config', [
                        'yaml' => app(GenerateNodeConfigAction::class)->execute($node, revealSecret: true),
                        'revealed' => true,
                    ]);
                }),
        ];
    }
}
