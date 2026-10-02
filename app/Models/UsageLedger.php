<?php

namespace App\Models;

use App\Models\Concerns\HasMicrosecondTimestamps;

use App\Support\Bytes;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 流量计费明细（**不可变**，红冲修正）。
 *
 * 每条记录都保存了「计费当时」的费率快照（points_per_gb / 比率 / 抽成），
 * 所以事后服务商改价绝不会改写历史账单。
 */
class UsageLedger extends Model
{
    // DATETIME(6) 微秒精度：与架构师 DDL 对齐，避免时间被截断
    use HasMicrosecondTimestamps;

    public const STATUS_PENDING = 'pending';

    public const STATUS_CHARGED = 'charged';

    public const STATUS_SKIPPED = 'skipped';

    public const STATUS_FAILED = 'failed';

    public const STATUS_REVERSED = 'reversed';

    protected $table = 'usage_ledger';

    protected $fillable = [
        'ledger_no',
        'user_id',
        'provider_id',
        'node_id',
        'binding_id',
        'pricing_rule_id',
        'period_start',
        'period_end',
        'upload_bytes',
        'download_bytes',
        'billable_bytes',
        'billable_gb',
        'points_per_gb',
        'upload_ratio',
        'download_ratio',
        'raw_points_amount',
        'user_points_amount',
        'platform_commission_rate',
        'platform_points_amount',
        'provider_points_amount',
        'is_reversal',
        'reversal_of_id',
        'user_ledger_id',
        'provider_ledger_id',
        'settlement_id',
        'status',
        'idempotency_key',
        'error_msg',
        'metadata',
        'billed_at',
    ];

    protected function casts(): array
    {
        return [
            'period_start' => 'datetime',
            'period_end' => 'datetime',
            'upload_bytes' => 'integer',
            'download_bytes' => 'integer',
            'billable_bytes' => 'integer',
            'billable_gb' => 'decimal:8',
            'points_per_gb' => 'decimal:8',
            'upload_ratio' => 'decimal:6',
            'download_ratio' => 'decimal:6',
            'raw_points_amount' => 'decimal:8',
            'user_points_amount' => 'decimal:8',
            'platform_commission_rate' => 'decimal:6',
            'platform_points_amount' => 'decimal:8',
            'provider_points_amount' => 'decimal:8',
            'is_reversal' => 'boolean',
            'metadata' => 'array',
            'billed_at' => 'datetime',
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

    public function node(): BelongsTo
    {
        return $this->belongsTo(ProviderNode::class, 'node_id');
    }

    public function binding(): BelongsTo
    {
        return $this->belongsTo(UserProviderBinding::class, 'binding_id');
    }

    public function pricingRule(): BelongsTo
    {
        return $this->belongsTo(ProviderPricingRule::class, 'pricing_rule_id');
    }

    public function scopeOfUser(Builder $query, int $userId): Builder
    {
        return $query->where('user_id', $userId);
    }

    public function scopeCharged(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_CHARGED)->where('is_reversal', 0);
    }

    public function scopePeriodBetween(Builder $query, \DateTimeInterface|string $from, \DateTimeInterface|string $to): Builder
    {
        return $query
            ->where('period_start', '>=', \App\Support\DbTime::sql($from))
            ->where('period_start', '<', \App\Support\DbTime::sql($to));
    }

    public static function statusLabels(): array
    {
        return [
            self::STATUS_PENDING => '待计费',
            self::STATUS_CHARGED => '已计费',
            self::STATUS_SKIPPED => '已跳过',
            self::STATUS_FAILED => '计费失败',
            self::STATUS_REVERSED => '已红冲',
        ];
    }

    public function statusLabel(): string
    {
        return self::statusLabels()[$this->status] ?? $this->status;
    }

    public function billableHuman(): string
    {
        return Bytes::human($this->billable_bytes);
    }
}
