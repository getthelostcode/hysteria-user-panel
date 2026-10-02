<?php

namespace App\Support;

use App\Models\PointsOrder;
use App\Models\TrafficUsageHourly;
use App\Models\UsageLedger;
use App\Models\UserPointsLedger;
use App\Models\UserProviderBinding;

/**
 * 状态徽章的「文字 + 颜色 + 图标」三件套。
 *
 * 为什么必须三件套：
 *  需求明确要求「不允许仅靠颜色表达状态」——只用颜色对色觉障碍用户 / 黑白打印不可读，
 *  所以每个状态都同时给出中文文字与图标，颜色只是强化。
 *
 * 颜色映射（全局统一）：
 *  success=绿(active/normal) · warning=黄(pending) · gray=灰(inactive) · danger=红(failed)
 *  primary=品牌色(进行中) · info=信息
 */
final class StatusBadge
{
    /** 用户积分流水类型 */
    public static function pointsLedger(string $bizType): array
    {
        return match ($bizType) {
            UserPointsLedger::BIZ_RECHARGE => self::make('充值', 'success', 'heroicon-m-arrow-down-tray'),
            UserPointsLedger::BIZ_BONUS => self::make('赠送', 'success', 'heroicon-m-gift'),
            UserPointsLedger::BIZ_USAGE => self::make('流量扣费', 'warning', 'heroicon-m-arrow-up-tray'),
            UserPointsLedger::BIZ_REFUND => self::make('退款', 'info', 'heroicon-m-arrow-uturn-left'),
            UserPointsLedger::BIZ_ADJUST => self::make('人工调整', 'gray', 'heroicon-m-wrench-screwdriver'),
            UserPointsLedger::BIZ_EXPIRE => self::make('过期', 'gray', 'heroicon-m-clock'),
            UserPointsLedger::BIZ_REVERSAL => self::make('红冲', 'danger', 'heroicon-m-arrow-path'),
            default => self::make($bizType, 'gray', 'heroicon-m-question-mark-circle'),
        };
    }

    /** 积分流水方向 */
    public static function direction(string $direction): array
    {
        return $direction === UserPointsLedger::DIRECTION_CREDIT
            ? self::make('收入', 'success', 'heroicon-m-plus-circle')
            : self::make('支出', 'danger', 'heroicon-m-minus-circle');
    }

    /** 订单状态 */
    public static function order(string $status): array
    {
        return match ($status) {
            PointsOrder::STATUS_PENDING => self::make('待支付', 'warning', 'heroicon-m-clock'),
            PointsOrder::STATUS_PAID => self::make('已支付', 'success', 'heroicon-m-check-circle'),
            PointsOrder::STATUS_FAILED => self::make('支付失败', 'danger', 'heroicon-m-x-circle'),
            PointsOrder::STATUS_CANCELLED => self::make('已取消', 'gray', 'heroicon-m-no-symbol'),
            PointsOrder::STATUS_REFUNDING => self::make('退款中', 'warning', 'heroicon-m-arrow-uturn-left'),
            PointsOrder::STATUS_REFUNDED => self::make('已退款', 'gray', 'heroicon-m-arrow-uturn-left'),
            default => self::make($status, 'gray', 'heroicon-m-question-mark-circle'),
        };
    }

    /** 绑定状态 */
    public static function binding(string $status): array
    {
        return match ($status) {
            UserProviderBinding::STATUS_ACTIVE => self::make('使用中', 'success', 'heroicon-m-check-badge'),
            UserProviderBinding::STATUS_PENDING => self::make('待开通', 'warning', 'heroicon-m-clock'),
            UserProviderBinding::STATUS_SUSPENDED => self::make('已暂停', 'warning', 'heroicon-m-pause-circle'),
            UserProviderBinding::STATUS_CLOSED => self::make('已结束', 'gray', 'heroicon-m-archive-box'),
            default => self::make($status, 'gray', 'heroicon-m-question-mark-circle'),
        };
    }

    /** 流量桶计费状态 */
    public static function traffic(string $status): array
    {
        return match ($status) {
            TrafficUsageHourly::STATUS_BILLED => self::make('已计费', 'success', 'heroicon-m-check-circle'),
            TrafficUsageHourly::STATUS_PENDING => self::make('待计费', 'warning', 'heroicon-m-clock'),
            TrafficUsageHourly::STATUS_SKIPPED => self::make('已跳过', 'gray', 'heroicon-m-forward'),
            TrafficUsageHourly::STATUS_FAILED => self::make('计费失败', 'danger', 'heroicon-m-exclamation-triangle'),
            default => self::make($status, 'gray', 'heroicon-m-question-mark-circle'),
        };
    }

    /** 账单状态 */
    public static function usage(string $status): array
    {
        return match ($status) {
            UsageLedger::STATUS_CHARGED => self::make('已计费', 'success', 'heroicon-m-check-circle'),
            UsageLedger::STATUS_PENDING => self::make('待计费', 'warning', 'heroicon-m-clock'),
            UsageLedger::STATUS_SKIPPED => self::make('已跳过', 'gray', 'heroicon-m-forward'),
            UsageLedger::STATUS_FAILED => self::make('计费失败', 'danger', 'heroicon-m-exclamation-triangle'),
            UsageLedger::STATUS_REVERSED => self::make('已红冲', 'danger', 'heroicon-m-arrow-path'),
            default => self::make($status, 'gray', 'heroicon-m-question-mark-circle'),
        };
    }

    /** 服务商状态 */
    public static function provider(string $status): array
    {
        return match ($status) {
            'active' => self::make('正常', 'success', 'heroicon-m-check-badge'),
            'pending' => self::make('待入驻', 'warning', 'heroicon-m-clock'),
            'suspended' => self::make('已暂停', 'warning', 'heroicon-m-pause-circle'),
            'terminated' => self::make('已终止', 'danger', 'heroicon-m-x-circle'),
            default => self::make($status, 'gray', 'heroicon-m-question-mark-circle'),
        };
    }

    /** 节点状态 */
    public static function node(string $status): array
    {
        return match ($status) {
            'active' => self::make('正常', 'success', 'heroicon-m-signal'),
            'maintenance' => self::make('维护中', 'warning', 'heroicon-m-wrench'),
            'offline' => self::make('离线', 'danger', 'heroicon-m-signal-slash'),
            'disabled' => self::make('已停用', 'gray', 'heroicon-m-no-symbol'),
            default => self::make($status, 'gray', 'heroicon-m-question-mark-circle'),
        };
    }

    /** @return array{label: string, color: string, icon: string} */
    private static function make(string $label, string $color, string $icon): array
    {
        return ['label' => $label, 'color' => $color, 'icon' => $icon];
    }
}
