<?php

namespace App\Filament\Provider\Pages\Settings;

use Filament\Facades\Filament;
use Filament\Forms;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/**
 * 修改密码。
 *
 * 防「双重哈希」：这里的 password 字段**不做** dehydrateStateUsing(Hash::make)，
 * 表单拿到的就是明文，由本页统一 Hash::make 后落库。
 * （Filament 自带的注册/资料页会给密码字段加哈希 dehydrate，
 *   若模型上再挂一个 hashed cast 就会哈希两次，导致永远登录失败。）
 */
class ChangePassword extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-lock-closed';

    protected static ?string $navigationGroup = '设置';

    protected static ?string $navigationLabel = '修改密码';

    protected static ?string $title = '修改密码';

    protected static ?int $navigationSort = 3;

    protected static string $view = 'filament.provider.pages.settings.change-password';

    public ?array $data = [];

    public function mount(): void
    {
        $this->form->fill();
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('登录密码')
                    ->description('为了账号安全，请使用至少 8 位、包含字母与数字的密码。')
                    ->schema([
                        Forms\Components\TextInput::make('current_password')
                            ->label('当前密码')
                            ->password()
                            ->revealable()
                            ->required()
                            ->rule(function () {
                                return function (string $attribute, $value, \Closure $fail): void {
                                    $user = Filament::auth()->user();

                                    if (! $user || ! Hash::check((string) $value, (string) $user->password)) {
                                        $fail('当前密码不正确。');
                                    }
                                };
                            }),

                        Forms\Components\TextInput::make('password')
                            ->label('新密码')
                            ->password()
                            ->revealable()
                            ->required()
                            ->minLength(8)
                            ->rule('confirmed'),

                        Forms\Components\TextInput::make('password_confirmation')
                            ->label('确认新密码')
                            ->password()
                            ->revealable()
                            ->required()
                            ->dehydrated(false),
                    ])
                    ->columns(2),
            ])
            ->statePath('data');
    }

    public function save(): void
    {
        $data = $this->form->getState();

        /** @var \App\Models\ProviderUser|null $user */
        $user = Filament::auth()->user();

        if (! $user) {
            throw ValidationException::withMessages(['data.current_password' => '登录状态已失效，请重新登录。']);
        }

        if (! Hash::check((string) $data['current_password'], (string) $user->password)) {
            throw ValidationException::withMessages(['data.current_password' => '当前密码不正确。']);
        }

        // 统一在这里哈希（表单不做哈希），避免双重哈希
        $user->update(['password' => Hash::make((string) $data['password'])]);

        $this->form->fill();

        Notification::make()
            ->title('密码已更新')
            ->body('下次登录请使用新密码。')
            ->success()
            ->send();
    }

    protected function getFormActions(): array
    {
        return [
            \Filament\Actions\Action::make('save')->label('保存新密码')->submit('save'),
        ];
    }
}
