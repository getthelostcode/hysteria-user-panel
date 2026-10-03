<?php

namespace App\Models;

use Filament\Models\Contracts\FilamentUser;
use Filament\Models\Contracts\HasName;
use Filament\Models\Contracts\HasTenants;
use Filament\Panel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Collection;

/**
 * 服务商后台登录账号（provider_users）。
 *
 * 设计要点：
 *  1. **独立表独立模型**，不复用 users，也不把「当前服务商」写死进用户表 —— 账号
 *     通过 provider_id 硬绑定到唯一服务商，这就是数据隔离的根。
 *  2. 实现 Filament 的 HasTenants：Filament 的 tenancy 会在每次请求里把租户解析出来，
 *     并调用 canAccessTenant() 做二次校验，所以「改 URL 里的 tenant code 去访问别家」
 *     会直接被拦（403/404），不会泄露数据。
 *  3. status=disabled 的账号保留记录但无法登录（canAccessPanel 返回 false）。
 */
class ProviderUser extends Authenticatable implements FilamentUser, HasName, HasTenants
{
    use Notifiable;

    protected $table = 'provider_users';

    protected $fillable = [
        'provider_id',
        'name',
        'email',
        'password',
        'status',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'last_login_at' => 'datetime',
        ];
    }

    // ---------------------------------------------------------------------
    // 关系
    // ---------------------------------------------------------------------

    public function provider(): BelongsTo
    {
        return $this->belongsTo(Provider::class, 'provider_id');
    }

    // ---------------------------------------------------------------------
    // 认证 / 授权
    // ---------------------------------------------------------------------

    /** 只有「本面板 + active 账号」能进服务商后台 */
    public function canAccessPanel(Panel $panel): bool
    {
        return $panel->getId() === 'provider' && $this->status === 'active';
    }

    /**
     * 该账号可以进入哪些租户。
     *
     * 本平台一个账号只属于一个服务商（provider_users.provider_id），
     * 但契约要求返回集合，所以统一返回单元素集合：Filament 会自动选中它并
     * 把 /provider 重定向到 /provider/{code}。
     */
    public function getTenants(Panel $panel): Collection|array
    {
        return Provider::query()->whereKey($this->provider_id)->get();
    }

    /** 二次校验：租户必须就是自己的服务商（防止 URL 换 code 越权） */
    public function canAccessTenant(Model $tenant): bool
    {
        return $tenant instanceof Provider && (int) $tenant->getKey() === (int) $this->provider_id;
    }

    /** Filament 顶栏显示名 */
    public function getFilamentName(): string
    {
        return (string) ($this->name ?: $this->email);
    }

    /** 便捷访问器：当前账号所属服务商 id */
    public function providerId(): int
    {
        return (int) $this->provider_id;
    }

    /** 记录最近登录时间（不触发 updated_at 之外的副作用） */
    public function touchLastLogin(): void
    {
        $this->forceFill(['last_login_at' => now()])->saveQuietly();
    }
}
