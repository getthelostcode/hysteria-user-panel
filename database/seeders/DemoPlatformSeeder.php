<?php

namespace Database\Seeders;

use App\Models\PointsPackage;
use App\Models\Provider;
use App\Models\ProviderNode;
use App\Models\ProviderPricingRule;
use App\Models\ProviderSettlementTerm;
use Illuminate\Database\Seeder;

/**
 * 平台侧基础数据：服务商、节点、版本化定价、结算条款、积分套餐。
 *
 * 定价刻意做成「服务商级默认价 + 节点覆盖价」并存，用来验证：
 *   节点价优先于默认价 → 定价版本化（只增不改）→ 计费取历史时刻的价格。
 */
class DemoPlatformSeeder extends Seeder
{
    public function run(): void
    {
        // ---------------------------------------------------------------
        // 服务商 A：默认 12 Points/GB，香港节点覆盖价 10 Points/GB
        // ---------------------------------------------------------------
        $suyun = Provider::updateOrCreate(
            ['code' => 'suyun'],
            [
                'name' => '速云 Hysteria',
                'contact_email' => 'ops@suyun.example',
                'status' => 'active',
                'api_endpoint' => 'https://node.suyun.example/api',
                'default_commission_rate' => 0.200000,
                'settlement_cycle' => 'monthly',
                'metadata' => ['region' => 'APAC'],
            ]
        );

        $suyunHk = ProviderNode::updateOrCreate(
            ['provider_id' => $suyun->id, 'node_code' => 'hk-01'],
            [
                'name' => '香港 01',
                'region' => '香港',
                'country_code' => 'HK',
                'host' => 'hk01.suyun.example',
                'port' => 443,
                'protocol' => 'hysteria2',
                'capacity_mbps' => 1000,
                'status' => 'active',
                'config' => ['sni' => 'hk01.suyun.example', 'insecure' => false],
                'last_seen_at' => now()->subMinutes(2),
            ]
        );

        $suyunJp = ProviderNode::updateOrCreate(
            ['provider_id' => $suyun->id, 'node_code' => 'jp-01'],
            [
                'name' => '日本 01',
                'region' => '日本',
                'country_code' => 'JP',
                'host' => 'jp01.suyun.example',
                'port' => 443,
                'protocol' => 'hysteria2',
                'capacity_mbps' => 500,
                'status' => 'active',
                'config' => ['sni' => 'jp01.suyun.example', 'insecure' => false],
                'last_seen_at' => now()->subMinutes(5),
            ]
        );

        // 服务商级默认价（node_id = NULL）
        ProviderPricingRule::updateOrCreate(
            ['provider_id' => $suyun->id, 'node_id' => null, 'effective_from' => '2026-01-01 00:00:00'],
            [
                'points_per_gb' => 12.00000000,
                'upload_ratio' => 1.000000,
                'download_ratio' => 1.000000,
                'min_charge_points' => 0.00000000,
                'priority' => 0,
                'status' => 'active',
                'remark' => '服务商级默认价',
            ]
        );

        // 节点覆盖价（node_id = 具体节点）—— 计费时优先命中
        ProviderPricingRule::updateOrCreate(
            ['provider_id' => $suyun->id, 'node_id' => $suyunHk->id, 'effective_from' => '2026-01-01 00:00:00'],
            [
                'points_per_gb' => 10.00000000,
                'upload_ratio' => 1.000000,
                'download_ratio' => 1.000000,
                'min_charge_points' => 0.00000000,
                'priority' => 0,
                'status' => 'active',
                'remark' => '香港节点促销价',
            ]
        );

        ProviderSettlementTerm::updateOrCreate(
            ['provider_id' => $suyun->id, 'effective_from' => '2026-01-01 00:00:00'],
            [
                'currency' => 'CNY',
                'points_to_fiat_rate' => 0.05000000,
                'commission_rate' => 0.200000,   // 平台抽成 20%
                'payout_fee_rate' => 0.010000,
                'tax_rate' => 0.000000,
                'min_payout_points' => 1000.00000000,
                'hold_days' => 3,
                'settlement_cycle' => 'monthly',
                'priority' => 0,
                'status' => 'active',
            ]
        );

        // ---------------------------------------------------------------
        // 服务商 B：默认 15 Points/GB，新加坡节点覆盖价 14 Points/GB
        // ---------------------------------------------------------------
        $xinglian = Provider::updateOrCreate(
            ['code' => 'xinglian'],
            [
                'name' => '星链加速',
                'contact_email' => 'hello@xinglian.example',
                'status' => 'active',
                'api_endpoint' => 'https://api.xinglian.example/v1',
                'default_commission_rate' => 0.250000,
                'settlement_cycle' => 'biweekly',
                'metadata' => ['region' => 'SEA'],
            ]
        );

        $xinglianSg = ProviderNode::updateOrCreate(
            ['provider_id' => $xinglian->id, 'node_code' => 'sg-01'],
            [
                'name' => '新加坡 01',
                'region' => '新加坡',
                'country_code' => 'SG',
                'host' => 'sg01.xinglian.example',
                'port' => 8443,
                'protocol' => 'hysteria2',
                'capacity_mbps' => 2000,
                'status' => 'active',
                'config' => ['sni' => 'sg01.xinglian.example', 'insecure' => false],
                'last_seen_at' => now()->subMinute(),
            ]
        );

        ProviderPricingRule::updateOrCreate(
            ['provider_id' => $xinglian->id, 'node_id' => null, 'effective_from' => '2026-02-01 00:00:00'],
            [
                'points_per_gb' => 15.00000000,
                'upload_ratio' => 1.000000,
                'download_ratio' => 1.500000,   // 下行计 1.5 倍，演示系数
                'min_charge_points' => 0.50000000,
                'priority' => 0,
                'status' => 'active',
                'remark' => '服务商级默认价（下行 1.5 倍系数）',
            ]
        );

        ProviderPricingRule::updateOrCreate(
            ['provider_id' => $xinglian->id, 'node_id' => $xinglianSg->id, 'effective_from' => '2026-02-01 00:00:00'],
            [
                'points_per_gb' => 14.00000000,
                'upload_ratio' => 1.000000,
                'download_ratio' => 1.500000,
                'min_charge_points' => 0.50000000,
                'priority' => 10,
                'status' => 'active',
                'remark' => '新加坡节点价（priority 更高）',
            ]
        );

        ProviderSettlementTerm::updateOrCreate(
            ['provider_id' => $xinglian->id, 'effective_from' => '2026-02-01 00:00:00'],
            [
                'currency' => 'CNY',
                'points_to_fiat_rate' => 0.04500000,
                'commission_rate' => 0.250000,   // 平台抽成 25%
                'payout_fee_rate' => 0.010000,
                'tax_rate' => 0.030000,
                'min_payout_points' => 2000.00000000,
                'hold_days' => 7,
                'settlement_cycle' => 'biweekly',
                'priority' => 0,
                'status' => 'active',
            ]
        );

        // ---------------------------------------------------------------
        // 积分套餐（平台只卖 Hyper Points）
        // ---------------------------------------------------------------
        foreach ([
            ['code' => 'starter', 'name' => '入门包', 'base_points' => 1000, 'bonus_points' => 0, 'price_amount' => 10, 'sort_order' => 1],
            ['code' => 'standard', 'name' => '标准包', 'base_points' => 5000, 'bonus_points' => 500, 'price_amount' => 45, 'sort_order' => 2],
            ['code' => 'pro', 'name' => '专业包', 'base_points' => 20000, 'bonus_points' => 3000, 'price_amount' => 160, 'sort_order' => 3],
        ] as $package) {
            PointsPackage::updateOrCreate(
                ['code' => $package['code']],
                [
                    'name' => $package['name'],
                    'base_points' => $package['base_points'],
                    'bonus_points' => $package['bonus_points'],
                    'price_amount' => $package['price_amount'],
                    'currency' => 'USD',
                    'status' => 'active',
                    'sort_order' => $package['sort_order'],
                    'effective_from' => '2026-01-01 00:00:00',
                    'effective_to' => null,
                ]
            );
        }

        $this->command?->info('平台基础数据已就绪：2 个服务商 / 3 个节点 / 4 条定价 / 3 个套餐');
    }
}
