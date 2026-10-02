<?php

namespace App\Models;

use App\Models\Concerns\HasMicrosecondTimestamps;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Hysteria 服务商。
 */
class Provider extends Model
{
    // DATETIME(6) 微秒精度：与架构师 DDL 对齐，避免时间被截断
    use HasMicrosecondTimestamps;

    protected $table = 'providers';

    protected $fillable = [
        'code',
        'name',
        'contact_email',
        'contact_telegram',
        'status',
        'api_endpoint',
        'default_commission_rate',
        'settlement_cycle',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'default_commission_rate' => 'decimal:6',
            'metadata' => 'array',
        ];
    }

    public function nodes(): HasMany
    {
        return $this->hasMany(ProviderNode::class, 'provider_id');
    }

    public function pricingRules(): HasMany
    {
        return $this->hasMany(ProviderPricingRule::class, 'provider_id');
    }

    public function bindings(): HasMany
    {
        return $this->hasMany(UserProviderBinding::class, 'provider_id');
    }

    public function settlementTerms(): HasMany
    {
        return $this->hasMany(ProviderSettlementTerm::class, 'provider_id');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', 'active');
    }

    public static function statusLabels(): array
    {
        return [
            'pending' => '待入驻',
            'active' => '正常',
            'suspended' => '已暂停',
            'terminated' => '已终止',
        ];
    }

    public function statusLabel(): string
    {
        return self::statusLabels()[$this->status] ?? $this->status;
    }

    /** 该服务商当前可用的最便宜价（默认价与节点价取最小），用于列表展示「X Points/GB 起」 */
    public function minPointsPerGb(): ?string
    {
        return $this->pricingRules()
            ->active()->effectiveAt(now())
            ->min('points_per_gb');
    }

    /** 面向用户的「起价」文案 */
    public function priceHint(): string
    {
        $min = $this->minPointsPerGb();

        return $min === null ? '暂未定价' : rtrim(rtrim((string) $min, '0'), '.').' Points/GB 起';
    }
}
