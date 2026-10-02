<?php

namespace App\Models\Concerns;

/**
 * 让 Eloquent 按 DATETIME(6) 的精度写入时间。
 *
 * 为什么必须这么做：
 *  架构师的 DDL 用 DATETIME(6) 存 UTC（微秒精度），且时间区间类表带 CHECK
 *  （如 user_provider_bindings 的 ck_upb_win：effective_to > effective_from）。
 *  Eloquent 默认的日期格式是 'Y-m-d H:i:s'，会把微秒**截断**，
 *  于是"同一秒内关旧建新"就会写出 effective_to == effective_from，
 *  直接违反 CHECK 约束（ERROR 3819），切换服务商在极短时间内连续执行就会失败。
 *
 * 只给「时间区间参与业务比较」的模型使用：
 *  user_provider_bindings / provider_pricing_rules / provider_settlement_terms。
 */
trait HasMicrosecondTimestamps
{
    public function getDateFormat(): string
    {
        // MySQL DATETIME(6)：2026-10-02 10:58:52.123456
        return 'Y-m-d H:i:s.u';
    }
}
