<?php

namespace App\Support;

/**
 * 流量单位换算助手。
 *
 * 平台固定口径：1 GB = 1024^3 = 1073741824 bytes（与计费 SQL 中的除数完全一致），
 * 不允许出现 1000^3 的混用，否则用户看到的用量与账单会对不上。
 */
final class Bytes
{
    public const KB = 1024;
    public const MB = 1048576;
    public const GB = 1073741824;   // 1024^3

    /** 字节 → GB（字符串，8 位小数，供计费使用） */
    public static function toGb(int|string|null $bytes): string
    {
        return bcdiv((string) ($bytes ?? 0), (string) self::GB, Decimal::SCALE);
    }

    /** 字节 → 人类可读（1.50 GB / 800.00 MB / 12.00 KB / 512 B） */
    public static function human(int|string|null $bytes): string
    {
        $bytes = (float) ($bytes ?? 0);

        if ($bytes >= self::GB) {
            return number_format($bytes / self::GB, 2).' GB';
        }

        if ($bytes >= self::MB) {
            return number_format($bytes / self::MB, 2).' MB';
        }

        if ($bytes >= self::KB) {
            return number_format($bytes / self::KB, 2).' KB';
        }

        return number_format($bytes, 0).' B';
    }
}
