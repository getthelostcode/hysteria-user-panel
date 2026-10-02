<?php

namespace App\Models;

use App\Models\Concerns\HasMicrosecondTimestamps;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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
