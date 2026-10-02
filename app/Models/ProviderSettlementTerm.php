<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use App\Models\Concerns\HasMicrosecondTimestamps;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 服务商结算条款（版本化：汇率 / 抽成 / 手续费 / 税 / 冻结期）。
 *
 * 计费时必须用「流量发生时刻」的条款取抽成比例，而不是最新条款。
 */
class ProviderSettlementTerm extends Model
{
    // DATETIME(6) 微秒精度：与 ck_pst_win 约束对齐
    use HasMicrosecondTimestamps;

    protected $table = 'provider_settlement_terms';

    protected $fillable = [
        'provider_id',
        'currency',
        'points_to_fiat_rate',
        'commission_rate',
        'payout_fee_rate',
        'tax_rate',
        'min_payout_points',
        'hold_days',
        'settlement_cycle',
        'priority',
        'status',
        'effective_from',
        'effective_to',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'points_to_fiat_rate' => 'decimal:8',
            'commission_rate' => 'decimal:6',
            'payout_fee_rate' => 'decimal:6',
            'tax_rate' => 'decimal:6',
            'min_payout_points' => 'decimal:8',
            'hold_days' => 'integer',
            'priority' => 'integer',
            'effective_from' => 'datetime',
            'effective_to' => 'datetime',
            'metadata' => 'array',
        ];
    }

    public function provider(): BelongsTo
    {
        return $this->belongsTo(Provider::class, 'provider_id');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', 'active');
    }

    public function scopeEffectiveAt(Builder $query, \DateTimeInterface|string $at): Builder
    {
        // 显式保留微秒：避免同秒内比较被判错（详见 App\Support\DbTime）
        $at = \App\Support\DbTime::sql($at);

        return $query
            ->where('effective_from', '<=', $at)
            ->where(fn (Builder $q) => $q->whereNull('effective_to')->orWhere('effective_to', '>', $at));
    }

    public function scopeBestMatch(Builder $query): Builder
    {
        return $query->orderByDesc('priority')->orderByDesc('effective_from')->orderByDesc('id');
    }
}
