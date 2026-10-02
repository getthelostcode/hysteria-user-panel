<?php

namespace App\Filament\User\Pages\Auth;

use Filament\Forms\Components\Component;
use Filament\Forms\Components\TextInput;
use Filament\Pages\Auth\Register as BaseRegister;

/**
 * 注册页（自定义）。
 *
 * 与 Filament 默认注册页的差异：
 *  - 把模板里不存在的 `name` 字段换成 `username`（对应 users.username NOT NULL UNIQUE）
 *  - email / password / passwordConfirmation 直接复用父类组件，行为一致
 *
 * 数据落库：handleRegistration() 会把表单数据交给 App\Models\User::create()，
 * 其中 password 由模型的 password Attribute 自动哈希进 password_hash，
 * username / uuid 若为空则由模型的 creating 钩子补齐。
 */
class Register extends BaseRegister
{
    protected function getForms(): array
    {
        return [
            'form' => $this->form(
                $this->makeForm()
                    ->schema([
                        $this->getUsernameFormComponent(),
                        $this->getEmailFormComponent(),
                        $this->getPasswordFormComponent(),
                        $this->getPasswordConfirmationFormComponent(),
                    ])
                    ->statePath('data'),
            ),
        ];
    }

    protected function getUsernameFormComponent(): Component
    {
        return TextInput::make('username')
            ->label('用户名')
            ->helperText('登录名，同时作为服务商侧的标识前缀')
            ->required()
            ->maxLength(64)
            ->rules(['alpha_dash'])
            ->unique('users', 'username')
            ->autofocus();
    }
}
