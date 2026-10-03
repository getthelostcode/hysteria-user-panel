<?php

namespace App\Models;

use App\Models\Concerns\HasMicrosecondTimestamps;
use App\Support\Decimal;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * 结算单（服务商后台里「可发起、创建后不可改」）。
 *
 * 让服务商看见的都是**架构师 DDL 的真实列名**：
 *   points_amount(毛额) / commission_points(平台抽成) / payout_fee_points(打款手续费)
 *   / tax_points(税费) / net_points(净额) / exchange_rate(汇率快照) / fiat_amount(应付法币)
 *
 * 注意：需求文档里写的 payable_points / payable_fiat / total_points 是概念名，
 * 落库时一律以实际 DDL 为准（points_amount / net_points / fiat_amount）。
 */
class ProviderSettlement extends Model
{
    // DATETIME(6) 微秒精度：与 ck_ps_win 约束对齐
    use HasMicrosecondTimestamps;

    public const STATUS_DRAFT = 'draft';

    public const STATUS_PENDING = 'pending';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_PAID = 'paid';

    public const STATUS_FAILED = 'failed';

    public const STATUS_CANCELLED = 'cancelled';

    protected $table = 'provider_settlements';

    protected $fillable = [
        'settlement_no',
        'provider_id',
        'term_id',
        'period_start',
        'period_end',
        'points_amount',
        'commission_points',
        'payout_fee_points',
        'tax_points',
        'net_points',
        'exchange_rate',
        'fiat_amount',
        'currency',
        'item_count',
        'status',
        'payout_method',
        'payout_ref',
        'metadata',
        'requested_at',
    ];

    protected function casts(): array
    {
        return [
            'period_start' => 'datetime',
            'period_end' => 'datetime',
            'points_amount' => 'decimal:8',
            'commission_points' => 'decimal:8',
            'payout_fee_points' => 'decimal:8',
            'tax_points' => 'decimal:8',
            'net_points' => 'decimal:8',
            'exchange_rate' => 'decimal:8',
            'fiat_amount' => 'decimal:8',
            'item_count' => 'integer',
            'metadata' => 'array',
            'requested_at' => 'datetime',
            'approved_at' => 'datetime',
            'paid_at' => 'datetime',
        ];
    }

    public function provider(): BelongsTo
    {
        return $this->belongsTo(Provider::class, 'provider_id');
    }

    public function term(): BelongsTo
    {
        return $this->belongsTo(ProviderSettlementTerm::class, 'term_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(ProviderSettlementItem::class, 'settlement_id');
    }

    public function scopeOfProvider(Builder $query, int $providerId): Builder
    {
        return $query->where('provider_id', $providerId);
    }

    public static function statusLabels(): array
    {
        return [
            self::STATUS_DRAFT => '草稿',
            self::STATUS_PENDING => '待审核',
            self::STATUS_APPROVED => '已批准',
            self::STATUS_PAID => '已打款',
            self::STATUS_FAILED => '失败',
            self::STATUS_CANCELLED => '已取消',
        ];
    }

    public function statusLabel(): string
    {
        return self::statusLabels()[$this->status] ?? $this->status;
    }

    /** 是否可以取消（只有草稿/待审核可撤销，已批准/已打款由平台侧控制） */
    public function isCancellable(): bool
    {
        return in_array($this->status, [self::STATUS_DRAFT, self::STATUS_PENDING], true);
    }

    /** 应付法币展示（带币种） */
    public function fiatDisplay(): string
    {
        return $this->currency.' '.Decimal::group($this->fiat_amount, 2);
    }
}
