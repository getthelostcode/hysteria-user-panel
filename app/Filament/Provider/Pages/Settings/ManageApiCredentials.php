<?php

namespace App\Filament\Provider\Pages\Settings;

use Filament\Actions;
use Filament\Facades\Filament;
use Filament\Forms;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

/**
 * API 凭证。
 *
 * - api_endpoint：节点侧上报流量的基址，服务商可自行修改；
 * - api_secret：**只展示脱敏串**，明文只在「重新生成」那一刻返回一次；
 *   数据库里存的是应用层加密（Crypt）后的密文（VARBINARY 列）。
 *
 * 为什么要能重新生成：密钥一旦泄漏（贴进公开仓库/群聊），必须能立刻作废换新。
 */
class ManageApiCredentials extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-key';

    protected static ?string $navigationGroup = '设置';

    protected static ?string $navigationLabel = 'API 凭证';

    protected static ?string $title = 'API 凭证';

    protected static ?int $navigationSort = 2;

    protected static string $view = 'filament.provider.pages.settings.manage-api-credentials';

    public ?array $data = [];

    public function mount(): void
    {
        $this->form->fill([
            'api_endpoint' => Filament::getTenant()->api_endpoint,
        ]);
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('上报地址')
                    ->description('服务商节点上报流量与心跳的 API 基址，例如 https://node.example.com/api。')
                    ->schema([
                        Forms\Components\TextInput::make('api_endpoint')
                            ->label('API 基址')
                            ->url()
                            ->maxLength(255)
                            ->placeholder('https://node.example.com/api'),
                    ]),

                Forms\Components\Section::make('API 密钥')
                    ->description('密钥用于节点侧签名上报；数据库中以密文存储，页面永远只显示脱敏串。')
                    ->schema([
                        Forms\Components\Placeholder::make('api_secret')
                            ->label('当前密钥（脱敏）')
                            ->content(fn (): string => Filament::getTenant()->maskedApiSecret()
                                .(Filament::getTenant()->apiSecret() === null ? '' : '（已配置）')),
                    ]),
            ])
            ->statePath('data');
    }

    public function save(): void
    {
        $provider = Filament::getTenant();

        Gate::authorize('update', $provider);

        $provider->update([
            'api_endpoint' => $this->form->getState()['api_endpoint'] ?? null,
        ]);

        Notification::make()->title('上报地址已保存')->success()->send();
    }

    protected function getFormActions(): array
    {
        return [
            Actions\Action::make('save')->label('保存')->submit('save'),
        ];
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('rotate_api_secret')
                ->label('重新生成 API 密钥')
                ->icon('heroicon-o-arrow-path')
                ->color('danger')
                ->requiresConfirmation()
                ->modalHeading('重新生成 API 密钥')
                ->modalDescription('旧密钥立即失效，节点侧需要更新配置后才能继续上报。新密钥只显示这一次。')
                ->action(function (): void {
                    $provider = Filament::getTenant();

                    Gate::authorize('update', $provider);

                    $secret = Str::random(48);
                    $provider->setApiSecret($secret)->save();

                    Notification::make()
                        ->title('新 API 密钥（仅此一次显示）')
                        ->body($secret)
                        ->persistent()
                        ->warning()
                        ->send();
                }),
        ];
    }
}
