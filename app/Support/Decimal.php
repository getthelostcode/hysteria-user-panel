<?php

namespace App\Support;

use InvalidArgumentException;

/**
 * 定点十进制运算助手（bcmath）。
 *
 * 全库 Points / 金额都是 DECIMAL(30,8)，绝对禁止用 float 参与运算：
 * PHP 的 float 是 IEEE754 双精度，0.1 + 0.2 !== 0.3，
 * 计费场景下会累积成真实的资金差错。所有加减比较都必须走本类。
 */
final class Decimal
{
    /** 全库统一小数位，对应 DECIMAL(30,8) */
    public const SCALE = 8;

    /** 1 GB 的字节数 = 1024^3，全库唯一口径 */
    public const BYTES_PER_GB = 1073741824;

    /** 加法：$a + $b */
    public static function add(string|int|float|null $a, string|int|float|null $b): string
    {
        return bcadd(self::normalize($a), self::normalize($b), self::SCALE);
    }

    /** 减法：$a - $b（可能为负） */
    public static function sub(string|int|float|null $a, string|int|float|null $b): string
    {
        return bcsub(self::normalize($a), self::normalize($b), self::SCALE);
    }

    /** 乘法（先乘再一次性定标，避免中间精度丢失） */
    public static function mul(string|int|float|null $a, string|int|float|null $b, int $scale = self::SCALE): string
    {
        return bcmul(self::normalize($a), self::normalize($b), $scale);
    }

    /** 除法；$b 为 0 时抛异常而不是返回 INF */
    public static function div(string|int|float|null $a, string|int|float|null $b, int $scale = self::SCALE): string
    {
        $b = self::normalize($b);

        if (bccomp($b, '0', self::SCALE) === 0) {
            throw new InvalidArgumentException('Decimal::div() 除数不能为 0');
        }

        return bcdiv(self::normalize($a), $b, $scale);
    }

    /** $a 是否大于 $b */
    public static function gt(string|int|float|null $a, string|int|float|null $b): bool
    {
        return bccomp(self::normalize($a), self::normalize($b), self::SCALE) === 1;
    }

    /** $a 是否大于等于 $b */
    public static function gte(string|int|float|null $a, string|int|float|null $b): bool
    {
        return bccomp(self::normalize($a), self::normalize($b), self::SCALE) >= 0;
    }

    /** $a 是否小于 $b */
    public static function lt(string|int|float|null $a, string|int|float|null $b): bool
    {
        return bccomp(self::normalize($a), self::normalize($b), self::SCALE) === -1;
    }

    /** 取两者较大值 */
    public static function max(string|int|float|null $a, string|int|float|null $b): string
    {
        return self::gte($a, $b) ? self::normalize($a) : self::normalize($b);
    }

    /** 是否为负数 */
    public static function isNegative(string|int|float|null $a): bool
    {
        return bccomp(self::normalize($a), '0', self::SCALE) === -1;
    }

    /** 格式化展示，如 1,234.56000000 */
    public static function format(string|int|float|null $value, int $scale = 2): string
    {
        return number_format((float) self::normalize($value), $scale, '.', ',');
    }

    /**
     * 精确的千分位格式化（纯字符串运算，不经过 float）。
     * 用于账单/流水这类不能有精度损失的地方。
     */
    public static function group(string|int|float|null $value, int $scale = 8): string
    {
        $raw = is_float($value) ? number_format($value, self::SCALE, '.', '') : (string) ($value ?? '0');
        $neg = str_starts_with($raw, '-');
        $raw = ltrim($raw, '-');

        [$int, $frac] = array_pad(explode('.', $raw, 2), 2, '');
        $int = $int === '' ? '0' : $int;
        $frac = substr(str_pad($frac, $scale, '0'), 0, $scale);

        $grouped = preg_replace('/\B(?=(\d{3})+(?!\d))/', ',', $int);

        $out = $scale > 0 ? $grouped.'.'.$frac : $grouped;

        return ($neg ? '-' : '').$out;
    }

    /**
     * 统一成 bcmath 能接受的字符串。
     * 注意：float 入参本身已是有损的，只允许出现在「展示层解包」的场景，
     * 计费链路必须传字符串（MySQL DECIMAL 经 PDO 取出即为字符串）。
     */
    public static function normalize(string|int|float|null $value): string
    {
        if ($value === null || $value === '') {
            return '0';
        }

        return is_float($value) ? number_format($value, self::SCALE, '.', '') : (string) $value;
    }
}
