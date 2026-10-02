<?php

namespace App\Models;

use App\Models\Concerns\HasMicrosecondTimestamps;

use Filament\Models\Contracts\FilamentUser;
use Filament\Models\Contracts\HasName;
use Filament\Panel;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Http\Request;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * 用户模型。
 *
 * 架构师 DDL 的关键约定（务必保持）：
 *  1. users 表**不存 current_provider_id**：当前服务商只能由
 *     user_provider_bindings 中 status='active' 的记录推导（见 currentProvider()）。
 *  2. 密码列名是 password_hash（不是 password）：通过 getAuthPassword() 对齐 Laravel 认证。
 *  3. 时间列统一 DATETIME(6) + UTC。
 *
 * 关于邮箱验证（默认关闭，两步即可开启）：
 *  1) 让本类实现 Illuminate\Contracts\Auth\MustVerifyEmail；
 *  2) 在 UserPanelProvider 里打开 ->emailVerification()，并配置好 MAIL_* 。
 *  注意：只做第 1 步会让注册流程去找 email-verification 路由而报
 *  RouteNotFoundException —— 因为 Filament 只在开启 emailVerification 时才注册该路由。
 *  users.email_verified_at 字段已由补充迁移创建，随时可用。
 */
class User extends Authenticatable implements FilamentUser, HasName
{
    // DATETIME(6) 微秒精度：与架构师 DDL 对齐，避免时间被截断
    use HasMicrosecondTimestamps;

    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasFactory;
    use Notifiable;

    protected $table = 'users';

    /**
     * 允许批量赋值。
     * 注意：password 不是数据库列，而是落到 password_hash 的写入口（由 password() 这个
     * Attribute 完成），这样 Filament 默认的注册页 / 资料页无需改造即可工作。
     */
    protected $fillable = [
        'uuid',
        'username',
        'email',
        'phone',
        'password',
        'status',
        'locale',
    ];

    protected $hidden = [
        'password_hash',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'password_hash' => 'string',
            'email_verified_at' => 'datetime',
            'registered_at' => 'datetime',
            'last_login_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        // 自动补齐 uuid / username（username 是 NOT NULL UNIQUE，注册页没填也不能炸）
        static::creating(function (self $user): void {
            if (blank($user->uuid)) {
                $user->uuid = (string) Str::uuid();
            }

            if (blank($user->username)) {
                $user->username = self::generateUsername($user->email);
            }

            if (blank($user->registered_at)) {
                $user->registered_at = now();
            }
        });

        // 新用户自动开钱包，保证「我的积分」永远有记录可查
        static::created(function (self $user): void {
            UserPointsWallet::firstOrCreate(
                ['user_id' => $user->id],
                ['balance' => 0, 'frozen' => 0, 'total_recharged' => 0, 'total_consumed' => 0]
            );
        });
    }

    // ---------------------------------------------------------------------
    // 认证
    // ---------------------------------------------------------------------

    /** 密码列是 password_hash，覆盖 Laravel 默认的 password 列 */
    public function getAuthPassword(): string
    {
        return (string) $this->password_hash;
    }

    /** 登录校验读取的列名（EloquentUserProvider 用它取密码哈希） */
    public function getAuthPasswordName(): string
    {
        return 'password_hash';
    }

    /**
     * 写 password 属性 → 落到 password_hash。
     * 用 needsRehash 判断：已经是 bcrypt 串（例如 Filament 资料页先 bcrypt() 过）就原样存，
     * 避免「双重哈希」导致登录永远失败。
     */
    protected function password(): Attribute
    {
        return Attribute::make(
            set: function (?string $value): array {
                if ($value === null || $value === '') {
                    return [];
                }

                return [
                    'password_hash' => Hash::needsRehash($value) ? Hash::make($value) : $value,
                ];
            },
        );
    }

    /** 只有 status=active 的用户能登录用户面板，且只能进 id=user 这个面板 */
    public function canAccessPanel(Panel $panel): bool
    {
        return $panel->getId() === 'user' && $this->status === 'active';
    }

    /**
     * Filament 顶部头像/用户名展示。
     * 用户的表**没有 name 列**，所以必须显式告诉 Filament 用 username，
     * 否则会抛 "getUserName(): Return value must be of type string, null returned"。
     */
    public function getFilamentName(): string
    {
        return (string) ($this->username ?: $this->email);
    }

    /** 记录登录时间 */
    public function touchLastLogin(?Request $request = null): void
    {
        $this->forceFill(['last_login_at' => now()])->saveQuietly();
    }

    // ---------------------------------------------------------------------
    // 关系
    // ---------------------------------------------------------------------

    public function wallet(): HasOne
    {
        return $this->hasOne(UserPointsWallet::class, 'user_id');
    }

    public function pointsLedger(): HasMany
    {
        return $this->hasMany(UserPointsLedger::class, 'user_id');
    }

    public function pointsOrders(): HasMany
    {
        return $this->hasMany(PointsOrder::class, 'user_id');
    }

    /** 全部绑定（含历史），按生效时间倒序 */
    public function bindings(): HasMany
    {
        return $this->hasMany(UserProviderBinding::class, 'user_id')->latest('effective_from');
    }

    /** 当前 active 绑定（库层由 uk_user_active_binding 保证最多一条） */
    public function activeBinding(): HasOne
    {
        return $this->hasOne(UserProviderBinding::class, 'user_id')
            ->where('status', UserProviderBinding::STATUS_ACTIVE)
            ->latest('effective_from');
    }

    public function usageHourly(): HasMany
    {
        return $this->hasMany(TrafficUsageHourly::class, 'user_id');
    }

    public function usageLedgers(): HasMany
    {
        return $this->hasMany(UsageLedger::class, 'user_id');
    }

    // ---------------------------------------------------------------------
    // 便捷访问器
    // ---------------------------------------------------------------------

    /** 当前生效绑定（还要求时间落在 [effective_from, effective_to) 内） */
    public function currentBinding(): ?UserProviderBinding
    {
        $binding = $this->activeBinding()->first();

        if (! $binding) {
            return null;
        }

        return $binding->isEffectiveAt(now()) ? $binding : null;
    }

    /** 当前服务商：由绑定推导，绝不从 users 表取 */
    public function currentProvider(): ?Provider
    {
        return $this->currentBinding()?->provider;
    }

    /** 积分余额（字符串十进制，缺失钱包时返回 '0'） */
    public function pointsBalance(): string
    {
        return (string) ($this->wallet()->value('balance') ?? '0');
    }

    /** 生成唯一用户名（邮箱前缀；冲突时补随机后缀） */
    public static function generateUsername(?string $email): string
    {
        $base = Str::of((string) $email)
            ->before('@')
            ->lower()
            ->replaceMatches('/[^a-z0-9_\-]/', '')
            ->limit(24, '')
            ->value();

        $base = $base !== '' ? $base : 'user';
        $candidate = $base;

        while (static::where('username', $candidate)->exists()) {
            $candidate = $base.'_'.Str::lower(Str::random(4));
        }

        return $candidate;
    }
}
