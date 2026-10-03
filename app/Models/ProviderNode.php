<?php

namespace App\Models;

use App\Models\Concerns\HasMicrosecondTimestamps;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * 服务商节点。
 */
class ProviderNode extends Model
{
    // DATETIME(6) 微秒精度：与架构师 DDL 对齐，避免时间被截断
    use HasMicrosecondTimestamps;

    protected $table = 'provider_nodes';

    protected $fillable = [
        'provider_id',
        'node_code',
        'name',
        'region',
        'country_code',
        'host',
        'port',
        'protocol',
        'capacity_mbps',
        'status',
        'tags',
        'config',
        'last_seen_at',
    ];

    protected function casts(): array
    {
        return [
            'tags' => 'array',
            'config' => 'array',
            'port' => 'integer',
            'capacity_mbps' => 'integer',
            'last_seen_at' => 'datetime',
        ];
    }

    public function provider(): BelongsTo
    {
        return $this->belongsTo(Provider::class, 'provider_id');
    }

    public function scopeOfProvider(Builder $query, int $providerId): Builder
    {
        return $query->where('provider_id', $providerId);
    }

    /** 该节点上的定价规则（节点详情页统计用） */
    public function pricingRules(): HasMany
    {
        return $this->hasMany(ProviderPricingRule::class, 'node_id');
    }

    /** 该节点的小时流量桶 */
    public function usageHourly(): HasMany
    {
        return $this->hasMany(TrafficUsageHourly::class, 'node_id');
    }

    // ---------------------------------------------------------------------
    // 节点密钥（存在 config JSON 里，应用层加密；表结构没有独立列）
    // ---------------------------------------------------------------------

    /** 解密后的节点密钥（仅在需要展示/生成配置时调用，展示前必须脱敏） */
    public function authSecret(): ?string
    {
        $encrypted = $this->config['auth_secret_encrypted'] ?? null;

        if (blank($encrypted)) {
            return null;
        }

        try {
            return \Illuminate\Support\Facades\Crypt::decryptString($encrypted);
        } catch (\Throwable) {
            return null;   // 密钥轮换 / 数据异常时不把异常抛到页面
        }
    }

    /** 写入（加密）节点密钥 */
    public function setAuthSecret(?string $secret): self
    {
        $config = $this->config ?? [];
        $config['auth_secret_encrypted'] = $secret === null
            ? null
            : \Illuminate\Support\Facades\Crypt::encryptString($secret);
        $config['auth_secret_rotated_at'] = now()->toIso8601String();
        $this->config = $config;

        return $this;
    }

    /** 脱敏展示：保留首 4 位与末 4 位，中间固定 8 个星号 */
    public function maskedAuthSecret(): string
    {
        $secret = $this->authSecret();

        if (blank($secret)) {
            return '未生成';
        }

        if (mb_strlen($secret) <= 8) {
            return str_repeat('*', mb_strlen($secret));
        }

        return mb_substr($secret, 0, 4).str_repeat('*', 8).mb_substr($secret, -4);
    }

    public function hasAuthSecret(): bool
    {
        return filled($this->authSecret());
    }

    /** 节点扩展配置里的 SNI（TLS 用），没有就退回 host */
    public function sni(): string
    {
        return (string) ($this->config['sni'] ?? $this->host ?? '');
    }

    /** 最近心跳距今多久（用于列表展示「在线/离线」判断） */
    public function isRecentlySeen(int $withinMinutes = 10): bool
    {
        return $this->last_seen_at !== null
            && $this->last_seen_at->greaterThanOrEqualTo(now()->subMinutes($withinMinutes));
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', 'active');
    }

    public static function statusLabels(): array
    {
        return [
            'active' => '正常',
            'maintenance' => '维护中',
            'offline' => '离线',
            'disabled' => '已停用',
        ];
    }

    public function statusLabel(): string
    {
        return self::statusLabels()[$this->status] ?? $this->status;
    }

    /** 节点地址（用于生成连接配置） */
    public function endpoint(): string
    {
        $host = $this->host ?: '未配置地址';

        return $this->port ? $host.':'.$this->port : (string) $host;
    }
}
