<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 结算明细（usage 级，只读）。
 *
 * uk_psi_usage 保证「一条 usage_ledger 只能被结算一次」，这是防止服务商被重复结算的
 * 关键约束；本模型在服务商后台只做展示，不提供任何写入口。
 */
class ProviderSettlementItem extends Model
{
    /** 该表只有 created_at */
    public const UPDATED_AT = null;

    protected $table = 'provider_settlement_items';

    protected $fillable = [
        'settlement_id',
        'usage_ledger_id',
        'provider_id',
        'user_id',
        'node_id',
        'period_start',
        'period_end',
        'billable_gb',
        'user_points_amount',
        'platform_points_amount',
        'provider_points_amount',
    ];

    protected function casts(): array
    {
        return [
            'period_start' => 'datetime',
            'period_end' => 'datetime',
            'billable_gb' => 'decimal:8',
            'user_points_amount' => 'decimal:8',
            'platform_points_amount' => 'decimal:8',
            'provider_points_amount' => 'decimal:8',
            'created_at' => 'datetime',
        ];
    }

    public function settlement(): BelongsTo
    {
        return $this->belongsTo(ProviderSettlement::class, 'settlement_id');
    }

    public function usageLedger(): BelongsTo
    {
        return $this->belongsTo(UsageLedger::class, 'usage_ledger_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function node(): BelongsTo
    {
        return $this->belongsTo(ProviderNode::class, 'node_id');
    }
}
