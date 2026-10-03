<?php

namespace App\Providers;

use Illuminate\Database\Connection;
use Illuminate\Database\Query\Grammars\MySqlGrammar;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->useMicrosecondPrecisionForMysqlBindings();
    }

    /**
     * 让 Eloquent / Query Builder 绑定时间参数时保留微秒。
     *
     * 背景（真实踩坑）：
     *  架构师的 DDL 全部使用 DATETIME(6)（微秒精度），业务上大量依赖
     *  「左闭右开区间」比较：effective_from <= t < effective_to。
     *  但 Laravel 的 MySqlGrammar 默认日期格式是 'Y-m-d H:i:s'，会把绑定参数里的
     *  微秒**截断**，于是同一秒内的比较会判错：
     *    绑定 effective_from = 11:00:06.178838
     *    查询参数 now() = 11:00:06.185577  → 绑定后被截断成 '11:00:06'
     *    → 11:00:06.178838 <= '11:00:06' 为 false → 明明生效的绑定查不到
     *  后果是刚切换完服务商，新流量会被判成"找不到绑定"而计费失败。
     *
     * 修法：给 MySQL 连接换一个日期格式为微秒精度的语法器。
     * 与 App\Models\Concerns\HasMicrosecondTimestamps（模型写入侧）配套使用。
     */
    protected function useMicrosecondPrecisionForMysqlBindings(): void
    {
        /** @var Connection $connection */
        $connection = DB::connection();

        if (! $connection instanceof \Illuminate\Database\MySqlConnection) {
            return; // 只有 MySQL 需要（sqlite 等场景不影响）
        }

        $connection->setQueryGrammar(tap(new class extends MySqlGrammar {
            /** @var string 绑定参数的时间格式：保留微秒，与 DATETIME(6) 对齐 */
            protected $dateFormat = 'Y-m-d H:i:s.u';
        }, function (MySqlGrammar $grammar) use ($connection): void {
            // 必须显式注入连接：Illuminate\Database\Grammar 没有构造函数，
            // 不注入的话 $this->connection 为 null，escape() 会抛
            // "The database driver's grammar implementation does not support escaping values."
            // —— 直接影响 QueryException 的错误信息与 toRawSql()，会把真实 SQL 报错盖掉。
            $grammar->setConnection($connection);
        }));
    }
}
