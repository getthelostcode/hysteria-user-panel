<?php

namespace App\Models;

use App\Models\Concerns\HasMicrosecondTimestamps;

use Filament\Models\Contracts\HasName;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Hysteria 服务商。
 */
class Provider extends Model implements HasName
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

    /** 服务商后台操作员账号 */
    public function providerUsers(): HasMany
    {
        return $this->hasMany(ProviderUser::class, 'provider_id');
    }

    /** Points 钱包（缓存层，一行） */
    public function wallet(): HasOne
    {
        return $this->hasOne(ProviderPointsWallet::class, 'provider_id');
    }

    /** Points 账本（不可变） */
    public function pointsLedger(): HasMany
    {
        return $this->hasMany(ProviderPointsLedger::class, 'provider_id');
    }

    /** 计费明细 */
    public function usageLedgers(): HasMany
    {
        return $this->hasMany(UsageLedger::class, 'provider_id');
    }

    /** 小时流量桶 */
    public function usageHourly(): HasMany
    {
        return $this->hasMany(TrafficUsageHourly::class, 'provider_id');
    }

    /** 结算单 */
    public function settlements(): HasMany
    {
        return $this->hasMany(ProviderSettlement::class, 'provider_id');
    }

    /** 结算明细 */
    public function settlementItems(): HasMany
    {
        return $this->hasMany(ProviderSettlementItem::class, 'provider_id');
    }

    /**
     * Filament 的租户展示名。
     * providers 表有 name 列，但实现 HasName 可以保证租户切换菜单里永远是「中文名」
     * 而不是 code，避免菜单上出现 suyun-hysteria 这类内部编码。
     */
    public function getFilamentName(): string
    {
        return (string) $this->name;
    }

    /** 服务商可用 Points（balance - frozen），字符串 bcmath */
    public function availablePoints(): string
    {
        $wallet = $this->wallet;

        return $wallet ? $wallet->available() : '0';
    }

    // ---------------------------------------------------------------------
    // 服务商级 API 密钥（api_secret_encrypted，应用层加密）
    // ---------------------------------------------------------------------

    /** 解密后的 API 密钥（展示前必须脱敏） */
    public function apiSecret(): ?string
    {
        if (blank($this->api_secret_encrypted)) {
            return null;
        }

        try {
            return \Illuminate\Support\Facades\Crypt::decryptString($this->api_secret_encrypted);
        } catch (\Throwable) {
            return null;
        }
    }

    public function maskedApiSecret(): string
    {
        $secret = $this->apiSecret();

        if (blank($secret)) {
            return '未生成';
        }

        if (mb_strlen($secret) <= 8) {
            return str_repeat('*', mb_strlen($secret));
        }

        return mb_substr($secret, 0, 4).str_repeat('*', 8).mb_substr($secret, -4);
    }

    /** 写入（加密）API 密钥并直接落库 */
    public function setApiSecret(?string $secret): self
    {
        $this->api_secret_encrypted = $secret === null
            ? null
            : \Illuminate\Support\Facades\Crypt::encryptString($secret);

        return $this;
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
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

        return $min === null
            ? '暂未定价'
            : \App\Support\Decimal::points($min, 4, ' Points/GB 起');
    }
}
