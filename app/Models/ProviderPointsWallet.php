<?php

namespace App\Models;

use App\Models\Concerns\HasMicrosecondTimestamps;

use App\Support\Decimal;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 服务商 Points 钱包（缓存层，权威值来自 provider_points_ledger）。
 * 用户面板只读展示（用户可看到自己为此服务商贡献了多少），不做任何写入。
 */
class ProviderPointsWallet extends Model
{
    // DATETIME(6) 微秒精度：与架构师 DDL 对齐，避免时间被截断
    use HasMicrosecondTimestamps;

    protected $table = 'provider_points_wallets';

    protected $primaryKey = 'provider_id';

    public $incrementing = false;

    protected $keyType = 'int';

    /** 该表只有 updated_at */
    public const CREATED_AT = null;

    protected $fillable = [
        'provider_id',
        'balance',
        'total_earned',
        'total_settled',
        'frozen',
        'last_ledger_id',
        'version',
    ];

    protected function casts(): array
    {
        return [
            'balance' => 'decimal:8',
            'total_earned' => 'decimal:8',
            'total_settled' => 'decimal:8',
            'frozen' => 'decimal:8',
        ];
    }

    public function provider(): BelongsTo
    {
        return $this->belongsTo(Provider::class, 'provider_id');
    }

    public function available(): string
    {
        return Decimal::sub($this->balance, $this->frozen);
    }
}
