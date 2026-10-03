<?php

namespace App\Filament\Provider\Pages\Settings;

use Filament\Facades\Filament;
use Filament\Forms;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Gate;

/**
 * 服务商资料（可编辑）。
 *
 * 可改：名称、联系邮箱、Telegram、结算周期。
 * 不可改（平台控制）：code（URL 用的租户 slug）、status、抽成、api_endpoint（在「API 凭证」页）。
 * 落库前用 ProviderPolicy::update 再判一次归属，绝不只依赖"当前租户"。
 */
class ManageProviderProfile extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-building-storefront';

    protected static ?string $navigationGroup = '设置';

    protected static ?string $navigationLabel = '服务商资料';

    protected static ?string $title = '服务商资料';

    protected static ?int $navigationSort = 1;

    protected static string $view = 'filament.provider.pages.settings.manage-provider-profile';

    public ?array $data = [];

    public function mount(): void
    {
        $provider = Filament::getTenant();

        $this->form->fill(Arr::only($provider->only([
            'name',
            'contact_email',
            'contact_telegram',
            'settlement_cycle',
        ]), ['name', 'contact_email', 'contact_telegram', 'settlement_cycle']));
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('基本信息')
                    ->description('服务商编码用于后台地址，由平台分配，不可自行修改。')
                    ->schema([
                        Forms\Components\TextInput::make('code')
                            ->label('服务商编码')
                            ->disabled()
                            ->dehydrated(false)
                            ->default(fn () => Filament::getTenant()->code),

                        Forms\Components\Select::make('status')
                            ->label('合作状态')
                            ->disabled()
                            ->dehydrated(false)
                            ->options(\App\Models\Provider::statusLabels())
                            ->default(fn () => Filament::getTenant()->status),

                        Forms\Components\TextInput::make('name')
                            ->label('服务商名称')
                            ->required()
                            ->maxLength(128),

                        Forms\Components\Select::make('settlement_cycle')
                            ->label('结算周期')
                            ->required()
                            ->options([
                                'weekly' => '每周',
                                'biweekly' => '每两周',
                                'monthly' => '每月',
                                'manual' => '手动',
                            ])
                            ->helperText('仅表达结算意愿，实际结算仍以平台条款为准。'),
                    ])
                    ->columns(2),

                Forms\Components\Section::make('联系方式')
                    ->description('用于平台侧对账、异常流量联络。')
                    ->schema([
                        Forms\Components\TextInput::make('contact_email')
                            ->label('联系邮箱')
                            ->email()
                            ->maxLength(190),

                        Forms\Components\TextInput::make('contact_telegram')
                            ->label('Telegram')
                            ->maxLength(64)
                            ->prefix('@'),
                    ])
                    ->columns(2),
            ])
            ->statePath('data');
    }

    public function save(): void
    {
        $provider = Filament::getTenant();

        // 第二道授权：Policy 明确判定「这个服务商账号能否改这条记录」
        Gate::authorize('update', $provider);

        $data = Arr::only($this->form->getState(), [
            'name',
            'contact_email',
            'contact_telegram',
            'settlement_cycle',
        ]);

        $provider->update($data);

        Notification::make()->title('资料已保存')->success()->send();
    }

    protected function getFormActions(): array
    {
        return [
            \Filament\Actions\Action::make('save')
                ->label('保存')
                ->submit('save'),
        ];
    }
}
