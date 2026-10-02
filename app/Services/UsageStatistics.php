<?php

namespace App\Services;

use App\Models\TrafficUsageHourly;
use App\Models\UsageLedger;
use App\Models\User;
use App\Support\Decimal;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * 用户用量/消费统计（供 Dashboard Widget 与列表页复用）。
 *
 * 统计口径与账单口径保持一致：
 *   - 流量取 traffic_usage_hourly（已按小时聚合，避免扫原始表）
 *   - 消费取 usage_ledger.user_points_amount（只算已计费、非红冲的账单）
 *   - Points 求和用 bcadd 累加字符串，绝不用 float
 */
class UsageStatistics
{
    /** 本月时间范围（左闭右开） */
    public function monthRange(?CarbonInterface $at = null): array
    {
        $at = $at ?? now();

        return [$at->copy()->startOfMonth(), $at->copy()->startOfMonth()->addMonth()];
    }

    /** 某时间范围内的流量字节数 */
    public function trafficBytes(User $user, CarbonInterface $from, CarbonInterface $to): array
    {
        $row = TrafficUsageHourly::query()
            ->ofUser($user->id)
            ->periodBetween($from, $to)
            ->selectRaw('COALESCE(SUM(upload_bytes),0) as up, COALESCE(SUM(download_bytes),0) as down')
            ->first();

        $upload = (int) ($row->up ?? 0);
        $download = (int) ($row->down ?? 0);

        return ['upload' => $upload, 'download' => $download, 'total' => $upload + $download];
    }

    /** 本月流量 */
    public function monthlyTrafficBytes(User $user): array
    {
        [$from, $to] = $this->monthRange();

        return $this->trafficBytes($user, $from, $to);
    }

    /** 某时间范围内已计费的消费 Points（字符串） */
    public function consumedPoints(User $user, CarbonInterface $from, CarbonInterface $to): string
    {
        // SUM 返回 DECIMAL，这里再累加成 8 位小数字符串
        $rows = UsageLedger::query()
            ->ofUser($user->id)
            ->charged()
            ->periodBetween($from, $to)
            ->pluck('user_points_amount');

        $total = '0';
        foreach ($rows as $amount) {
            $total = Decimal::add($total, (string) $amount);
        }

        return $total;
    }

    /** 本月消费 Points */
    public function monthlyConsumedPoints(User $user): string
    {
        [$from, $to] = $this->monthRange();

        return $this->consumedPoints($user, $from, $to);
    }

    /** 本月计费条数 */
    public function monthlyBilledCount(User $user): int
    {
        [$from, $to] = $this->monthRange();

        return UsageLedger::query()
            ->ofUser($user->id)
            ->charged()
            ->periodBetween($from, $to)
            ->count();
    }

    /**
     * 最近 N 天每日上下行流量（GB，字符串），供折线图使用。
     *
     * @return array{labels: array<int,string>, upload: array<int,float>, download: array<int,float>, total_bytes: int}
     */
    public function dailyTraffic(User $user, int $days = 30): array
    {
        $start = now()->startOfDay()->subDays($days - 1);
        $end = now()->startOfDay()->addDay();

        $rows = TrafficUsageHourly::query()
            ->ofUser($user->id)
            ->periodBetween($start, $end)
            ->selectRaw('DATE(period_start) as day, SUM(upload_bytes) as up, SUM(download_bytes) as down')
            ->groupBy('day')
            ->orderBy('day')
            ->get()
            ->keyBy(fn ($row) => (string) $row->day);

        $labels = [];
        $upload = [];
        $download = [];
        $totalBytes = 0;

        for ($i = 0; $i < $days; $i++) {
            $date = $start->copy()->addDays($i);
            $key = $date->toDateString();
            $row = $rows->get($key);

            $up = (int) ($row->up ?? 0);
            $down = (int) ($row->down ?? 0);
            $totalBytes += $up + $down;

            $labels[] = $date->format('m-d');
            // 图表只需要可视化精度，这里允许转 float；计费链路仍然全字符串
            $upload[] = round($up / Decimal::BYTES_PER_GB, 4);
            $download[] = round($down / Decimal::BYTES_PER_GB, 4);
        }

        return [
            'labels' => $labels,
            'upload' => $upload,
            'download' => $download,
            'total_bytes' => $totalBytes,
        ];
    }

    /** 该用户贡献给各服务商的 Points 汇总（按服务商分组） */
    public function pointsByProvider(User $user): array
    {
        return DB::table('usage_ledger as ul')
            ->join('providers as p', 'p.id', '=', 'ul.provider_id')
            ->where('ul.user_id', $user->id)
            ->where('ul.status', UsageLedger::STATUS_CHARGED)
            ->where('ul.is_reversal', 0)
            ->groupBy('p.id', 'p.name')
            ->selectRaw('p.id as provider_id, p.name as provider_name, SUM(ul.user_points_amount) as points, SUM(ul.billable_bytes) as bytes')
            ->get()
            ->map(fn ($row) => [
                'provider_id' => $row->provider_id,
                'provider_name' => $row->provider_name,
                'points' => (string) $row->points,
                'bytes' => (int) $row->bytes,
            ])
            ->all();
    }
}
