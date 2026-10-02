<?php

namespace App\Filament\User\Pages\Auth;

use Filament\Forms\Components\Component;
use Filament\Forms\Components\TextInput;
use Filament\Pages\Auth\EditProfile as BaseEditProfile;

/**
 * 个人资料（自定义）。
 *
 * 与 Filament 默认资料页的差异：
 *  - users 表没有 `name` 列 → 换成 username
 *  - 增加 phone（users.phone 唯一、可空）
 *  - email / password / passwordConfirmation 复用父类组件
 *
 * 改密码：父类会把新密码 bcrypt 后写入 $data['password']，
 * 最终由 USER 模型的 password Attribute 落到 password_hash（已哈希则原样保留，不会二次哈希）。
 */
class EditProfile extends BaseEditProfile
{
    protected function getForms(): array
    {
        return [
            'form' => $this->form(
                $this->makeForm()
                    ->schema([
                        $this->getUsernameFormComponent(),
                        $this->getEmailFormComponent(),
                        $this->getPhoneFormComponent(),
                        $this->getPasswordFormComponent(),
                        $this->getPasswordConfirmationFormComponent(),
                    ])
                    ->operation('edit')
                    ->model($this->getUser())
                    ->statePath('data')
                    ->inlineLabel(! static::isSimple()),
            ),
        ];
    }

    protected function getUsernameFormComponent(): Component
    {
        return TextInput::make('username')
            ->label('用户名')
            ->required()
            ->maxLength(64)
            ->rules(['alpha_dash'])
            ->unique('users', 'username', ignoreRecord: true);
    }

    protected function getPhoneFormComponent(): Component
    {
        return TextInput::make('phone')
            ->label('手机号')
            ->tel()
            ->maxLength(32)
            ->unique('users', 'phone', ignoreRecord: true);
    }

    public static function getLabel(): string
    {
        return '个人资料';
    }
}
