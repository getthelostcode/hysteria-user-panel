<?php

namespace App\Console\Commands;

use App\Services\UsageBillingService;
use Illuminate\Console\Command;

/**
 * 调试用：手动跑一次用量计费。
 *
 * 生产环境的计费由定时任务驱动；本命令用于本地验证与补数据，
 * 逻辑与线上完全一致（按流量发生时间回溯绑定与定价 + 事务内三方记账）。
 */
class BillUsageCommand extends Command
{
    protected $signature = 'hysteria:bill
        {--user= : 只处理某个用户 ID}
        {--from= : 起始时间（含），如 2026-09-01}
        {--to= : 结束时间（不含），如 2026-10-01}
        {--limit=500 : 单次最多处理的桶数}';

    protected $description = '按 traffic_usage_hourly 生成 usage_ledger 并扣减用户 / 记账给服务商';

    public function handle(UsageBillingService $billing): int
    {
        $from = $this->option('from') ? \Illuminate\Support\Carbon::parse($this->option('from')) : null;
        $to = $this->option('to') ? \Illuminate\Support\Carbon::parse($this->option('to')) : null;
        $limit = (int) $this->option('limit');

        $userIds = $this->option('user')
            ? [(int) $this->option('user')]
            : \App\Models\TrafficUsageHourly::query()->distinct()->pluck('user_id')->all();

        if (empty($userIds)) {
            $this->warn('没有需要计费的流量桶。');

            return self::SUCCESS;
        }

        foreach ($userIds as $userId) {
            $result = $billing->billUser((int) $userId, $from, $to, $limit);

            $this->info(sprintf(
                '用户 #%d：已计费 %d 条，失败 %d 条，扣减 %s Points',
                $userId,
                $result['billed'],
                $result['failed'],
                rtrim(rtrim($result['points'], '0'), '.') ?: '0',
            ));
        }

        return self::SUCCESS;
    }
}
