<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 流量原始明细（**只读**，按月分区表）。
 *
 * 分区表的两个硬性限制决定了本模型的写法，改错任何一条都会在运行时炸：
 *  1. 分区表不能建外键，所以 provider_id / node_id / user_id / binding_id 全部由
 *     应用层保证一致性；本模型在服务商后台只读，不提供写入口。
 *  2. 主键是 (id, occurred_at) 复合主键（分区表要求分区列进主键），Eloquent 没法用它
 *     当主键，因此这里把主键声明为 id 且 incrementing=false：既能拿到稳定的
 *     「行标识」（Filament 表格 wire:key 需要唯一值），又不会误以为可以按 id 更新
 *     （分区列的更新在 MySQL 里本身就是重写分区，业务上不允许）。
 *  3. total_bytes 是数据库生成列 ⇒ 绝不能出现在 fillable 里，否则 INSERT 报 3105。
 */
class TrafficRaw extends Model
{
    /** 该表只有 created_at */
    public const UPDATED_AT = null;

    protected $table = 'traffic_raw';

    /** 复合主键 (id, occurred_at)，这里只用 id 作为行标识 */
    protected $primaryKey = 'id';

    public $incrementing = false;

    protected $keyType = 'int';

    protected $fillable = [
        'provider_id',
        'node_id',
        'user_id',
        'binding_id',
        'session_id',
        'external_user_id',
        'occurred_at',
        'period_start',
        'period_end',
        'upload_bytes',
        'download_bytes',
        'idempotency_key',
        'source',
        'raw_payload',
    ];

    protected function casts(): array
    {
        return [
            'occurred_at' => 'datetime',
            'period_start' => 'datetime',
            'period_end' => 'datetime',
            'upload_bytes' => 'integer',
            'download_bytes' => 'integer',
            'total_bytes' => 'integer',
            'raw_payload' => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function provider(): BelongsTo
    {
        return $this->belongsTo(Provider::class, 'provider_id');
    }

    public function node(): BelongsTo
    {
        return $this->belongsTo(ProviderNode::class, 'node_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function binding(): BelongsTo
    {
        return $this->belongsTo(UserProviderBinding::class, 'binding_id');
    }

    /** 按时间窗口筛选：必须直接比较分区列 occurred_at，否则会全分区扫描 */
    public function scopeOccurredBetween(Builder $query, \DateTimeInterface|string $from, \DateTimeInterface|string $to): Builder
    {
        return $query
            ->where('occurred_at', '>=', \App\Support\DbTime::sql($from))
            ->where('occurred_at', '<', \App\Support\DbTime::sql($to));
    }

    public static function sourceLabels(): array
    {
        return [
            'node_push' => '节点推送',
            'node_pull' => '平台拉取',
            'reconcile' => '对账补录',
            'manual' => '手工导入',
        ];
    }
}
