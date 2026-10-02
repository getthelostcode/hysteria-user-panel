<?php

namespace App\Models;

use App\Models\Concerns\HasMicrosecondTimestamps;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 法币购买 Points 订单。
 */
class PointsOrder extends Model
{
    // DATETIME(6) 微秒精度：与架构师 DDL 对齐，避免时间被截断
    use HasMicrosecondTimestamps;

    public const STATUS_PENDING = 'pending';

    public const STATUS_PAID = 'paid';

    public const STATUS_FAILED = 'failed';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUS_REFUNDING = 'refunding';

    public const STATUS_REFUNDED = 'refunded';

    protected $table = 'points_orders';

    protected $fillable = [
        'order_no',
        'user_id',
        'package_id',
        'base_points',
        'bonus_points',
        'points_amount',
        'fiat_amount',
        'fiat_currency',
        'payment_method',
        'payment_channel',
        'payment_ref',
        'status',
        'idempotency_key',
        'ledger_id',
        'raw_payload',
        'expire_at',
        'paid_at',
        'refunded_at',
    ];

    protected function casts(): array
    {
        return [
            'base_points' => 'decimal:8',
            'bonus_points' => 'decimal:8',
            'points_amount' => 'decimal:8',
            'fiat_amount' => 'decimal:8',
            'raw_payload' => 'array',
            'expire_at' => 'datetime',
            'paid_at' => 'datetime',
            'refunded_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function package(): BelongsTo
    {
        return $this->belongsTo(PointsPackage::class, 'package_id');
    }

    public function ledger(): BelongsTo
    {
        return $this->belongsTo(UserPointsLedger::class, 'ledger_id');
    }

    public function scopeOfUser(Builder $query, int $userId): Builder
    {
        return $query->where('user_id', $userId);
    }

    public function scopePaid(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_PAID);
    }

    public static function statusLabels(): array
    {
        return [
            self::STATUS_PENDING => '待支付',
            self::STATUS_PAID => '已支付',
            self::STATUS_FAILED => '支付失败',
            self::STATUS_CANCELLED => '已取消',
            self::STATUS_REFUNDING => '退款中',
            self::STATUS_REFUNDED => '已退款',
        ];
    }

    public function statusLabel(): string
    {
        return self::statusLabels()[$this->status] ?? $this->status;
    }

    public function isPaid(): bool
    {
        return $this->status === self::STATUS_PAID;
    }
}
