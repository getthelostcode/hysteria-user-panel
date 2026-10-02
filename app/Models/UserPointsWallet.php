<?php

namespace App\Models;

use App\Models\Concerns\HasMicrosecondTimestamps;

use App\Support\Decimal;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 用户 Points 钱包（**缓存层**）。
 *
 * 权威余额永远是 user_points_ledger.signed_amount 的求和；
 * 本表只为「快点查余额」存在，任何一次写入都必须与账本在同一个事务里完成。
 * 对账 SQL 见 hysteria-node-agent/sql/09_reconciliation_queries.sql §2.2。
 */
class UserPointsWallet extends Model
{
    // DATETIME(6) 微秒精度：与架构师 DDL 对齐，避免时间被截断
    use HasMicrosecondTimestamps;

    protected $table = 'user_points_wallets';

    protected $primaryKey = 'user_id';

    public $incrementing = false;

    protected $keyType = 'int';

    /** 该表只有 updated_at，没有 created_at */
    public const CREATED_AT = null;

    protected $fillable = [
        'user_id',
        'balance',
        'frozen',
        'total_recharged',
        'total_consumed',
        'last_ledger_id',
        'version',
    ];

    protected function casts(): array
    {
        // decimal:8 返回字符串，避免任何 float 参与业务运算
        return [
            'balance' => 'decimal:8',
            'frozen' => 'decimal:8',
            'total_recharged' => 'decimal:8',
            'total_consumed' => 'decimal:8',
            'version' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /** 可用余额 = 余额 - 冻结 */
    public function available(): string
    {
        return Decimal::sub($this->balance, $this->frozen);
    }
}
