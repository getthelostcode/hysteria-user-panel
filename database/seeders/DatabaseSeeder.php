<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * 演示数据编排。
 *
 * 顺序很重要：先有服务商/节点/定价/套餐，才有用户绑定与流量。
 */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            DemoPlatformSeeder::class,   // 服务商 / 节点 / 定价 / 结算条款 / 积分套餐
            DemoProviderSeeder::class,   // 服务商后台登录账号（provider_users）
            DemoUserSeeder::class,       // 演示用户 + 绑定 + 流量 + 购买 + 计费
        ]);
    }
}
