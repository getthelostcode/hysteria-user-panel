<?php

namespace App\Models;

use App\Models\Concerns\HasMicrosecondTimestamps;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 服务商切换审计日志。
 */
class ProviderSwitchLog extends Model
{
    // DATETIME(6) 微秒精度：与架构师 DDL 对齐，避免时间被截断
    use HasMicrosecondTimestamps;

    protected $table = 'provider_switch_logs';

    /** 该表只有 created_at */
    public const UPDATED_AT = null;

    protected $fillable = [
        'user_id',
        'from_binding_id',
        'to_binding_id',
        'from_provider_id',
        'to_provider_id',
        'effective_at',
        'status',
        'operator_type',
        'operator_id',
        'reason',
        'detail',
    ];

    protected function casts(): array
    {
        return [
            'effective_at' => 'datetime',
            'detail' => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function fromProvider(): BelongsTo
    {
        return $this->belongsTo(Provider::class, 'from_provider_id');
    }

    public function toProvider(): BelongsTo
    {
        return $this->belongsTo(Provider::class, 'to_provider_id');
    }

    public function fromBinding(): BelongsTo
    {
        return $this->belongsTo(UserProviderBinding::class, 'from_binding_id');
    }

    public function toBinding(): BelongsTo
    {
        return $this->belongsTo(UserProviderBinding::class, 'to_binding_id');
    }

    public function scopeOfUser(Builder $query, int $userId): Builder
    {
        return $query->where('user_id', $userId);
    }
}
