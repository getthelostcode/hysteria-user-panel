<?php

namespace App\Services;

use App\Models\ProviderNode;
use App\Models\ProviderPointsWallet;
use App\Models\TrafficUsageHourly;
use App\Models\UsageLedger;
use App\Support\Bytes;
use App\Support\Decimal;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * 服务商侧统计（Dashboard 卡片 / 图表 / 排行 / 列表页复用）。
 *
 * 口径与计费保持一致，避免"面板数字和账单对不上"：
 *  - 流量：traffic_usage_hourly（已按小时聚合的表，不扫分区原始表）；
 *  - 应得 Points：usage_ledger.provider_points_amount（只算已计费、非红冲）；
 *  - 余额：provider_points_wallets（缓存层，权威值来自账本）。
 * 所有 Points 求和都用 bcadd 累加字符串，绝不用 float（float 只用于图表坐标）。
 */
class ProviderUsageStatistics
{
    /** 今日区间 [今天 00:00, 明天 00:00) */
    public function todayRange(?CarbonInterface $at = null): array
    {
        $at = $at ?? now();

        return [$at->copy()->startOfDay(), $at->copy()->startOfDay()->addDay()];
    }

    /** 本月区间 [本月 1 日, 下月 1 日) */
    public function monthRange(?CarbonInterface $at = null): array
    {
        $at = $at ?? now();

        return [$at->copy()->startOfMonth(), $at->copy()->startOfMonth()->addMonth()];
    }

    // ---------------------------------------------------------------------
    // 流量
    // ---------------------------------------------------------------------

    /** @return array{upload:int, download:int, total:int} */
    public function trafficBytes(int $providerId, CarbonInterface|string $from, CarbonInterface|string $to): array
    {
        $row = TrafficUsageHourly::query()
            ->where('provider_id', $providerId)
            ->periodBetween($from, $to)
            ->selectRaw('COALESCE(SUM(upload_bytes),0) as up, COALESCE(SUM(download_bytes),0) as down')
            ->first();

        $upload = (int) ($row->up ?? 0);
        $download = (int) ($row->down ?? 0);

        return ['upload' => $upload, 'download' => $download, 'total' => $upload + $download];
    }

    public function todayTraffic(int $providerId): array
    {
        [$from, $to] = $this->todayRange();

        return $this->trafficBytes($providerId, $from, $to);
    }

    public function monthlyTraffic(int $providerId): array
    {
        [$from, $to] = $this->monthRange();

        return $this->trafficBytes($providerId, $from, $to);
    }

    /** 单个节点今日流量（节点详情页用） */
    public function nodeTrafficToday(int $nodeId): array
    {
        [$from, $to] = $this->todayRange();

        $row = TrafficUsageHourly::query()
            ->where('node_id', $nodeId)
            ->periodBetween($from, $to)
            ->selectRaw('COALESCE(SUM(upload_bytes),0) as up, COALESCE(SUM(download_bytes),0) as down')
            ->first();

        $upload = (int) ($row->up ?? 0);
        $download = (int) ($row->down ?? 0);

        return ['upload' => $upload, 'download' => $download, 'total' => $upload + $download];
    }

    // ---------------------------------------------------------------------
    // Points
    // ---------------------------------------------------------------------

    /** 某区间的应得 Points（已计费、非红冲） */
    public function earnedPoints(int $providerId, CarbonInterface|string $from, CarbonInterface|string $to): string
    {
        $rows = UsageLedger::query()
            ->where('provider_id', $providerId)
            ->charged()
            ->where('period_start', '>=', \App\Support\DbTime::sql($from))
            ->where('period_start', '<', \App\Support\DbTime::sql($to))
            ->pluck('provider_points_amount');

        $total = '0';

        foreach ($rows as $amount) {
            $total = Decimal::add($total, (string) $amount);
        }

        return $total;
    }

    public function monthlyEarnedPoints(int $providerId): string
    {
        [$from, $to] = $this->monthRange();

        return $this->earnedPoints($providerId, $from, $to);
    }

    public function monthlyBilledCount(int $providerId): int
    {
        [$from, $to] = $this->monthRange();

        return UsageLedger::query()
            ->where('provider_id', $providerId)
            ->charged()
            ->where('period_start', '>=', \App\Support\DbTime::sql($from))
            ->where('period_start', '<', \App\Support\DbTime::sql($to))
            ->count();
    }

    public function wallet(int $providerId): ?ProviderPointsWallet
    {
        return ProviderPointsWallet::query()->where('provider_id', $providerId)->first();
    }

    public function balance(int $providerId): string
    {
        return (string) ($this->wallet($providerId)?->balance ?? '0');
    }

    /** 可结算余额（balance - frozen） */
    public function availablePoints(int $providerId): string
    {
        $wallet = $this->wallet($providerId);

        return $wallet ? $wallet->available() : '0';
    }

    // ---------------------------------------------------------------------
    // 节点
    // ---------------------------------------------------------------------

    /** @return array{total:int, active:int, online:int, offline:int, disabled:int} */
    public function nodeCounts(int $providerId): array
    {
        $rows = ProviderNode::query()
            ->ofProvider($providerId)
            ->selectRaw('status, COUNT(*) as c')
            ->groupBy('status')
            ->pluck('c', 'status');

        // 在线 = 状态 active/maintenance 且最近 10 分钟内有心跳
        $online = ProviderNode::query()
            ->ofProvider($providerId)
            ->whereIn('status', ['active', 'maintenance'])
            ->where('last_seen_at', '>=', now()->subMinutes(10))
            ->count();

        return [
            'total' => (int) $rows->sum(),
            'active' => (int) ($rows['active'] ?? 0),
            'disabled' => (int) ($rows['disabled'] ?? 0),
            'offline' => (int) ($rows['offline'] ?? 0),
            'online' => $online,
        ];
    }

    // ---------------------------------------------------------------------
    // 图表数据（float 只用于坐标，不参与任何金额计算）
    // ---------------------------------------------------------------------

    /**
     * 最近 N 天每日流量（GB）。
     *
     * @return array{labels:array<int,string>, upload:array<int,float>, download:array<int,float>, total_bytes:int}
     */
    public function dailyTraffic(int $providerId, int $days = 30): array
    {
        $start = now()->startOfDay()->subDays($days - 1);
        $end = now()->startOfDay()->addDay();

        $rows = TrafficUsageHourly::query()
            ->where('provider_id', $providerId)
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
            $row = $rows->get($date->toDateString());

            $up = (int) ($row->up ?? 0);
            $down = (int) ($row->down ?? 0);
            $totalBytes += $up + $down;

            $labels[] = $date->format('m-d');
            $upload[] = round($up / Decimal::BYTES_PER_GB, 4);
            $download[] = round($down / Decimal::BYTES_PER_GB, 4);
        }

        return ['labels' => $labels, 'upload' => $upload, 'download' => $download, 'total_bytes' => $totalBytes];
    }

    /**
     * 最近 N 天每日应得 Points。
     *
     * @return array{labels:array<int,string>, points:array<int,float>, total:string}
     */
    public function dailyEarnedPoints(int $providerId, int $days = 30): array
    {
        $start = now()->startOfDay()->subDays($days - 1);
        $end = now()->startOfDay()->addDay();

        $rows = UsageLedger::query()
            ->where('provider_id', $providerId)
            ->charged()
            ->where('period_start', '>=', \App\Support\DbTime::sql($start))
            ->where('period_start', '<', \App\Support\DbTime::sql($end))
            ->selectRaw('DATE(period_start) as day, SUM(provider_points_amount) as pts')
            ->groupBy('day')
            ->orderBy('day')
            ->get()
            ->keyBy(fn ($row) => (string) $row->day);

        $labels = [];
        $points = [];
        $total = '0';

        for ($i = 0; $i < $days; $i++) {
            $date = $start->copy()->addDays($i);
            $value = (string) ($rows->get($date->toDateString())->pts ?? '0');
            $total = Decimal::add($total, $value);

            $labels[] = $date->format('m-d');
            $points[] = round((float) $value, 4);
        }

        return ['labels' => $labels, 'points' => $points, 'total' => $total];
    }

    // ---------------------------------------------------------------------
    // 排行
    // ---------------------------------------------------------------------

    /**
     * 流量排行 Top N（区间内）。
     *
     * @return Collection<int, object{user_id:int, username:?string, up:int, down:int, total:int}>
     */
    public function topUsers(int $providerId, CarbonInterface|string $from, CarbonInterface|string $to, int $limit = 10): Collection
    {
        return DB::table('traffic_usage_hourly as t')
            ->join('users as u', 'u.id', '=', 't.user_id')
            ->where('t.provider_id', $providerId)
            ->where('t.period_start', '>=', \App\Support\DbTime::sql($from))
            ->where('t.period_start', '<', \App\Support\DbTime::sql($to))
            ->groupBy('t.user_id', 'u.username')
            ->selectRaw('t.user_id, u.username, SUM(t.upload_bytes) as up, SUM(t.download_bytes) as down')
            ->orderByRaw('SUM(t.upload_bytes) + SUM(t.download_bytes) DESC')
            ->limit($limit)
            ->get()
            ->map(fn ($row) => (object) [
                'user_id' => (int) $row->user_id,
                'username' => $row->username,
                'up' => (int) $row->up,
                'down' => (int) $row->down,
                'total' => (int) $row->up + (int) $row->down,
                'total_human' => Bytes::human((int) $row->up + (int) $row->down),
            ]);
    }
}
