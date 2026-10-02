<?php

namespace App\Models;

use App\Models\Concerns\HasMicrosecondTimestamps;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Points 售卖套餐（平台只卖 Hyper Points，不卖流量套餐）。
 */
class PointsPackage extends Model
{
    // DATETIME(6) 微秒精度：与架构师 DDL 对齐，避免时间被截断
    use HasMicrosecondTimestamps;

    protected $table = 'points_packages';

    protected $fillable = [
        'code',
        'name',
        'base_points',
        'bonus_points',
        'price_amount',
        'currency',
        'status',
        'sort_order',
        'effective_from',
        'effective_to',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'base_points' => 'decimal:8',
            'bonus_points' => 'decimal:8',
            'price_amount' => 'decimal:8',
            'metadata' => 'array',
            'effective_from' => 'datetime',
            'effective_to' => 'datetime',
            'sort_order' => 'integer',
        ];
    }

    public function orders(): HasMany
    {
        return $this->hasMany(PointsOrder::class, 'package_id');
    }

    /** 只展示当前上架且落在有效期的套餐 */
    public function scopeActive(Builder $query): Builder
    {
        return $query
            ->where('status', 'active')
            ->where('effective_from', '<=', now())
            ->where(fn (Builder $q) => $q->whereNull('effective_to')->orWhere('effective_to', '>', now()));
    }

    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('sort_order')->orderBy('price_amount');
    }

    /** 到账总量 = 基础 + 赠送（与数据库 CHECK ck_po_sum 口径一致） */
    public function totalPoints(): string
    {
        return bcadd((string) $this->base_points, (string) $this->bonus_points, 8);
    }

    /** 单价：每 1 元法币换多少 Points（仅用于展示性价比） */
    public function pointsPerCurrency(): string
    {
        if (bccomp((string) $this->price_amount, '0', 8) === 0) {
            return '0';
        }

        return bcdiv($this->totalPoints(), (string) $this->price_amount, 4);
    }
}
