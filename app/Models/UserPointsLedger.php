<?php

namespace App\Models;

use App\Models\Concerns\HasMicrosecondTimestamps;

use App\Support\Decimal;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 用户 Points 账本（**不可变**）。
 *
 * 规则：只允许 INSERT。任何修正都必须写"红冲"分录（reversal_of_id 指向原分录），
 * 绝不 UPDATE 金额。signed_amount 是数据库生成列，SUM(signed_amount) 即权威余额。
 */
class UserPointsLedger extends Model
{
    // DATETIME(6) 微秒精度：与架构师 DDL 对齐，避免时间被截断
    use HasMicrosecondTimestamps;

    public const BIZ_RECHARGE = 'recharge';

    public const BIZ_BONUS = 'bonus';

    public const BIZ_USAGE = 'usage';

    public const BIZ_REFUND = 'refund';

    public const BIZ_ADJUST = 'adjust';

    public const BIZ_EXPIRE = 'expire';

    public const BIZ_REVERSAL = 'reversal';

    public const DIRECTION_CREDIT = 'credit';

    public const DIRECTION_DEBIT = 'debit';

    protected $table = 'user_points_ledger';

    /** 该表只有 created_at */
    public const UPDATED_AT = null;

    protected $fillable = [
        'user_id',
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
        // signed_amount 是生成列：绝不能出现在 fillable 里，否则报 ERROR 3105
        return [
            'amount' => 'decimal:8',
            'signed_amount' => 'decimal:8',
            'balance_after' => 'decimal:8',
            'metadata' => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function reversalOf(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reversal_of_id');
    }

    /** 是否入账（加钱） */
    public function isCredit(): bool
    {
        return $this->direction === self::DIRECTION_CREDIT;
    }

    /** 带符号金额（用于展示 + / -） */
    public function signedAmount(): string
    {
        return (string) ($this->signed_amount ?? ($this->isCredit() ? $this->amount : Decimal::sub(0, $this->amount)));
    }

    // ---------------------------------------------------------------------
    // Scopes
    // ---------------------------------------------------------------------

    public function scopeOfUser(Builder $query, int $userId): Builder
    {
        return $query->where('user_id', $userId);
    }

    public function scopeOfType(Builder $query, string $bizType): Builder
    {
        return $query->where('biz_type', $bizType);
    }

    public function scopeBetweenDates(Builder $query, ?string $from, ?string $until): Builder
    {
        return $query
            ->when($from, fn (Builder $q) => $q->where('created_at', '>=', $from.' 00:00:00'))
            ->when($until, fn (Builder $q) => $q->where('created_at', '<', date('Y-m-d', strtotime($until.' +1 day')).' 00:00:00'));
    }

    /** 中文标签，供筛选器与表格复用 */
    public static function bizTypeLabels(): array
    {
        return [
            self::BIZ_RECHARGE => '充值',
            self::BIZ_BONUS => '赠送',
            self::BIZ_USAGE => '流量扣费',
            self::BIZ_REFUND => '退款',
            self::BIZ_ADJUST => '人工调整',
            self::BIZ_EXPIRE => '过期',
            self::BIZ_REVERSAL => '红冲',
        ];
    }

    public function bizTypeLabel(): string
    {
        return self::bizTypeLabels()[$this->biz_type] ?? $this->biz_type;
    }
}
