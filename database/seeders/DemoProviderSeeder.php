<?php

namespace Database\Seeders;

use App\Models\Provider;
use App\Models\ProviderUser;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * 服务商后台演示账号。
 *
 * 为什么单独 Seed 而不是塞进 DemoPlatformSeeder：
 *  DemoPlatformSeeder 说的全是「平台侧基础数据」（服务商/节点/定价/条款/套餐），
 *  而这张表是**登录凭证**，两者关注点不同；分开后「重置权限数据」不用担心动到业务数据。
 *
 * 演示账号（密码统一 password）：
 *  - suyun-ops@example.com     → 速云 Hysteria（速云运营）
 *  - xinglian-ops@example.com  → 星链加速（星链财务）
 */
class DemoProviderSeeder extends Seeder
{
    public function run(): void
    {
        $accounts = [
            ['code' => 'suyun', 'email' => 'suyun-ops@example.com', 'name' => '速云运营'],
            ['code' => 'xinglian', 'email' => 'xinglian-ops@example.com', 'name' => '星链财务'],
        ];

        foreach ($accounts as $account) {
            $provider = Provider::where('code', $account['code'])->first();

            if (! $provider) {
                continue;   // 服务商不存在时跳过（可先在平台侧建入驻审核）
            }

            ProviderUser::updateOrCreate(
                ['email' => $account['email']],
                [
                    'provider_id' => $provider->id,
                    'name' => $account['name'],
                    // 密码统一 password：这里显式哈希，模型上不挂 hashed cast，
                    // 避免"再哈希一次"导致登录永远失败
                    'password' => Hash::make('password'),
                    'status' => 'active',
                    'email_verified_at' => now(),
                ]
            );

            // 服务商级 API 密钥（演示用固定值，生产由后台「API 凭证」页生成）
            if ($provider->apiSecret() === null) {
                $provider->setApiSecret('demo-api-secret-'.$provider->code)->save();
            }
        }

        $this->command?->info('服务商后台演示账号已就绪：suyun-ops@example.com / xinglian-ops@example.com（密码 password）');
    }
}
