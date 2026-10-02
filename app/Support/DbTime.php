<?php

namespace App\Support;

use DateTimeInterface;

/**
 * DATETIME(6) 时间助手。
 *
 * 为什么需要它：
 *  Laravel 的 MySqlGrammar 默认把时间绑定格式化为 'Y-m-d H:i:s'（**截断微秒**），
 *  而架构师的 DDL 用的是 DATETIME(6)。把截断后的值拿去做左闭右开的时间区间比较
 *  （effective_from <= t < effective_to），就会在同 1 秒内判错：
 *  例：绑定 effective_from = 11:00:06.178838，查询参数被截断成 11:00:06
 *      → 11:00:06.178838 <= 11:00:06 为 false → 明明生效却查不到绑定。
 *
 *  应用层已经在 AppServiceProvider 里把语法器的日期格式提升到微秒，
 *  这里再多做一道显式格式化，确保计费链路任何情况下都不会丢精度。
 */
final class DbTime
{
    /** 与 MySQL DATETIME(6) 完全对齐的格式 */
    public const FORMAT = 'Y-m-d H:i:s.u';

    /** 把 DateTime 实例或字符串统一成带微秒的 SQL 字面量 */
    public static function sql(DateTimeInterface|string $value): string
    {
        return $value instanceof DateTimeInterface ? $value->format(self::FORMAT) : $value;
    }

    /** 当天 00:00:00.000000 */
    public static function startOfDay(DateTimeInterface|string $value): string
    {
        $date = is_string($value) ? new \DateTimeImmutable($value) : $value;

        return $date->format('Y-m-d').' 00:00:00.000000';
    }

    /** 次日 00:00:00.000000（用于 < 上界） */
    public static function endOfDayExclusive(DateTimeInterface|string $value): string
    {
        $date = is_string($value) ? new \DateTimeImmutable($value) : $value;

        return $date->modify('+1 day')->format('Y-m-d').' 00:00:00.000000';
    }
}
