<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use App\Models\Concerns\HasMicrosecondTimestamps;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Crypt;

/**
 * 用户-服务商绑定（**版本化**）。
 *
 * 库层如何保证「一个用户同时只有一个 active 绑定」：
 *   active_binding_key = CASE WHEN status='active' THEN user_id ELSE NULL END (STORED 生成列)
 *   + UNIQUE KEY uk_user_active_binding(active_binding_key)
 * MySQL 唯一索引允许多个 NULL，所以非 active 的行不会互斥，而 active 行每人最多一条。
 *
 * 因此：active_binding_key 是**生成列**，绝不能出现在 fillable / INSERT 列清单里（否则 ERROR 3105）。
 */
class UserProviderBinding extends Model
{
    // DATETIME(6) 微秒精度：避免"同一秒内关旧建新"触发 ck_upb_win
    use HasMicrosecondTimestamps;

    public const STATUS_PENDING = 'pending';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_SUSPENDED = 'suspended';

    public const STATUS_CLOSED = 'closed';

    protected $table = 'user_provider_bindings';

    /** 注意：不包含 active_binding_key（数据库生成列） */
    protected $fillable = [
        'user_id',
        'provider_id',
        'external_user_id',
        'auth_secret_encrypted',
        'status',
        'effective_from',
        'effective_to',
        'switch_from_binding_id',
        'metadata',
    ];

    protected $hidden = [
        'auth_secret_encrypted',
    ];

    protected function casts(): array
    {
        return [
            'effective_from' => 'datetime',
            'effective_to' => 'datetime',
            'metadata' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function provider(): BelongsTo
    {
        return $this->belongsTo(Provider::class, 'provider_id');
    }

    public function switchFrom(): BelongsTo
    {
        return $this->belongsTo(self::class, 'switch_from_binding_id');
    }

    // ---------------------------------------------------------------------
    // Scopes
    // ---------------------------------------------------------------------

    public function scopeOfUser(Builder $query, int $userId): Builder
    {
        return $query->where('user_id', $userId);
    }

    public function scopeActiveStatus(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_ACTIVE);
    }

    /** 时间落在 [effective_from, effective_to) */
    public function scopeEffectiveAt(Builder $query, \DateTimeInterface|string $at): Builder
    {
        // 显式保留微秒：避免同秒内比较被判错（详见 App\Support\DbTime）
        $at = \App\Support\DbTime::sql($at);

        return $query
            ->where('effective_from', '<=', $at)
            ->where(fn (Builder $q) => $q->whereNull('effective_to')->orWhere('effective_to', '>', $at));
    }

    // ---------------------------------------------------------------------
    // 辅助
    // ---------------------------------------------------------------------

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    /** 指定时刻是否落在本绑定的生效区间内 */
    public function isEffectiveAt(\DateTimeInterface|string $at): bool
    {
        $at = $at instanceof \DateTimeInterface ? $at->format('Y-m-d H:i:s.u') : $at;

        if ($this->effective_from > $at) {
            return false;
        }

        return $this->effective_to === null || $this->effective_to > $at;
    }

    /** 解密后的服务商侧密钥（仅连接配置页使用，展示时再脱敏） */
    public function authSecret(): ?string
    {
        if (blank($this->auth_secret_encrypted)) {
            return null;
        }

        try {
            return Crypt::decryptString($this->auth_secret_encrypted);
        } catch (\Throwable) {
            // 密钥轮换或数据异常时不把错误抛到页面
            return null;
        }
    }

    /** 写入加密密钥 */
    public function setAuthSecret(?string $secret): self
    {
        $this->auth_secret_encrypted = $secret === null ? null : Crypt::encryptString($secret);

        return $this;
    }

    public static function statusLabels(): array
    {
        return [
            self::STATUS_PENDING => '待开通',
            self::STATUS_ACTIVE => '使用中',
            self::STATUS_SUSPENDED => '已暂停',
            self::STATUS_CLOSED => '已结束',
        ];
    }

    public function statusLabel(): string
    {
        return self::statusLabels()[$this->status] ?? $this->status;
    }
}
