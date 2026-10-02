<?php

namespace App\Models;

use App\Models\Concerns\HasMicrosecondTimestamps;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 服务商 Points 账本（不可变）。计费时给服务商记 usage_earning(credit)，
 * 结算时 settlement(debit)。用户面板只读。
 */
class ProviderPointsLedger extends Model
{
    // DATETIME(6) 微秒精度：与架构师 DDL 对齐，避免时间被截断
    use HasMicrosecondTimestamps;

    protected $table = 'provider_points_ledger';

    /** 该表只有 created_at */
    public const UPDATED_AT = null;

    protected $fillable = [
        'provider_id',
        'biz_type',
        'direction',
        'amount',
        'balance_after',
        'biz_ref_type',
        'biz_ref_id',
        'idempotency_key',
        'reversal_of_id',
        'remark',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:8',
            'signed_amount' => 'decimal:8',
            'balance_after' => 'decimal:8',
            'metadata' => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function provider(): BelongsTo
    {
        return $this->belongsTo(Provider::class, 'provider_id');
    }

    public function scopeOfProvider(Builder $query, int $providerId): Builder
    {
        return $query->where('provider_id', $providerId);
    }

    public function scopeOfType(Builder $query, string $bizType): Builder
    {
        return $query->where('biz_type', $bizType);
    }
}
