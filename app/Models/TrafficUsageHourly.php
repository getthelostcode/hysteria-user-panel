<?php

namespace App\Models;

use App\Models\Concerns\HasMicrosecondTimestamps;

use App\Support\Bytes;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 流量小时聚合桶。
 *
 * 唯一键 (binding_id, node_id, period_start) 是 UPSERT 落点，保证聚合幂等；
 * total_bytes 是数据库生成列（upload + download），不可写入。
 */
class TrafficUsageHourly extends Model
{
    // DATETIME(6) 微秒精度：与架构师 DDL 对齐，避免时间被截断
    use HasMicrosecondTimestamps;

    public const STATUS_PENDING = 'pending';

    public const STATUS_BILLED = 'billed';

    public const STATUS_SKIPPED = 'skipped';

    public const STATUS_FAILED = 'failed';

    protected $table = 'traffic_usage_hourly';

    /** 不含 total_bytes（生成列） */
    protected $fillable = [
        'user_id',
        'provider_id',
        'node_id',
        'binding_id',
        'period_start',
        'period_end',
        'upload_bytes',
        'download_bytes',
        'raw_record_count',
        'billed_status',
        'usage_ledger_id',
        'billed_at',
    ];

    protected function casts(): array
    {
        return [
            'period_start' => 'datetime',
            'period_end' => 'datetime',
            'upload_bytes' => 'integer',
            'download_bytes' => 'integer',
            'total_bytes' => 'integer',
            'raw_record_count' => 'integer',
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

    public function usageLedger(): BelongsTo
    {
        return $this->belongsTo(UsageLedger::class, 'usage_ledger_id');
    }

    public function scopeOfUser(Builder $query, int $userId): Builder
    {
        return $query->where('user_id', $userId);
    }

    public function scopePending(Builder $query): Builder
    {
        return $query->where('billed_status', self::STATUS_PENDING);
    }

    /** 按时间窗口筛选（含起点、不含终点） */
    public function scopePeriodBetween(Builder $query, \DateTimeInterface|string $from, \DateTimeInterface|string $to): Builder
    {
        return $query
            ->where('period_start', '>=', \App\Support\DbTime::sql($from))
            ->where('period_start', '<', \App\Support\DbTime::sql($to));
    }

    public function uploadHuman(): string
    {
        return Bytes::human($this->upload_bytes);
    }

    public function downloadHuman(): string
    {
        return Bytes::human($this->download_bytes);
    }

    public function totalHuman(): string
    {
        return Bytes::human($this->total_bytes);
    }
}
