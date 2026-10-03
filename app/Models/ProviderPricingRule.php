<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use App\Models\Concerns\HasMicrosecondTimestamps;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 服务商定价规则（**版本化**）。
 *
 * 铁律：
 *  - 改价只能**新增**一条记录，并把旧记录的 effective_to 截断；
 *  - 生效区间是左闭右开 [effective_from, effective_to)，effective_to = NULL 表示长期；
 *  - node_id = NULL 表示「服务商级默认价」，非空表示「节点覆盖价」，节点价优先。
 */
class ProviderPricingRule extends Model
{
    // DATETIME(6) 微秒精度：避免同一秒内截断旧价触发 ck_ppr_win
    use HasMicrosecondTimestamps;

    protected $table = 'provider_pricing_rules';

    protected $fillable = [
        'provider_id',
        'node_id',
        'points_per_gb',
        'upload_ratio',
        'download_ratio',
        'min_charge_points',
        'priority',
        'status',
        'effective_from',
        'effective_to',
        'created_by',
        'remark',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'points_per_gb' => 'decimal:8',
            'upload_ratio' => 'decimal:6',
            'download_ratio' => 'decimal:6',
            'min_charge_points' => 'decimal:8',
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

    public function node(): BelongsTo
    {
        return $this->belongsTo(ProviderNode::class, 'node_id');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', 'active');
    }

    /** 限定服务商（服务商后台的所有查询都必须带上它） */
    public function scopeOfProvider(Builder $query, int $providerId): Builder
    {
        return $query->where('provider_id', $providerId);
    }

    /** 生效时间落在 [effective_from, effective_to) */
    public function scopeEffectiveAt(Builder $query, \DateTimeInterface|string $at): Builder
    {
        // 显式保留微秒：避免同秒内比较被判错（详见 App\Support\DbTime）
        $at = \App\Support\DbTime::sql($at);

        return $query
            ->where('effective_from', '<=', $at)
            ->where(fn (Builder $q) => $q->whereNull('effective_to')->orWhere('effective_to', '>', $at));
    }

    /**
     * 定价匹配的排序规则（与 MySQL 架构师的查询完全一致）：
     * ① 节点价优先 ② priority 大者优先 ③ effective_from 新者优先 ④ id 大者兜底。
     * 用 orderByRaw 直接下推到 MySQL，避免把候选集拉到 PHP 里排序。
     */
    public function scopeBestMatch(Builder $query): Builder
    {
        return $query
            ->orderByRaw('(node_id IS NOT NULL) DESC')   // TRUE 排在前面 = 节点覆盖价优先
            ->orderByDesc('priority')
            ->orderByDesc('effective_from')
            ->orderByDesc('id');
    }

    public function isNodeSpecific(): bool
    {
        return $this->node_id !== null;
    }
}
