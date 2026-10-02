<?php

namespace Tests\Concerns;

use App\Models\PointsPackage;
use App\Models\Provider;
use App\Models\ProviderNode;
use App\Models\ProviderPricingRule;
use App\Models\ProviderSettlementTerm;
use App\Models\User;
use App\Models\UserPointsWallet;
use App\Models\UserProviderBinding;

/**
 * 测试夹具：按最小可用集造出服务商 / 节点 / 定价 / 结算条款 / 套餐 / 用户。
 */
trait HysteriaFixtures
{
    protected function makeUser(string $username = 'alice', ?string $email = null): User
    {
        $user = User::factory()->create([
            'username' => $username,
            'email' => $email ?? $username.'@example.com',
        ]);

        UserPointsWallet::firstOrCreate(['user_id' => $user->id]);

        return $user->refresh();
    }

    /**
     * 造一个服务商（含 1 个节点、默认价 + 可选节点覆盖价、结算条款）。
     *
     * @return array{0: Provider, 1: ProviderNode, 2: ProviderPricingRule, 3: ?ProviderPricingRule}
     */
    protected function makeProvider(
        string $code = 'p1',
        string $name = 'Provider One',
        string $defaultPointsPerGb = '10.00000000',
        ?string $nodePointsPerGb = null,
        string $commissionRate = '0.200000',
    ): array {
        $provider = Provider::create([
            'code' => $code,
            'name' => $name,
            'status' => 'active',
            'settlement_cycle' => 'monthly',
        ]);

        $node = ProviderNode::create([
            'provider_id' => $provider->id,
            'node_code' => 'n1',
            'name' => 'Node One',
            'region' => '香港',
            'host' => $code.'.example.com',
            'port' => 443,
            'status' => 'active',
            'config' => ['sni' => $code.'.example.com'],
        ]);

        $defaultRule = ProviderPricingRule::create([
            'provider_id' => $provider->id,
            'node_id' => null,
            'points_per_gb' => $defaultPointsPerGb,
            'upload_ratio' => 1,
            'download_ratio' => 1,
            'min_charge_points' => 0,
            'priority' => 0,
            'status' => 'active',
            'effective_from' => '2026-01-01 00:00:00',
        ]);

        $nodeRule = null;

        if ($nodePointsPerGb !== null) {
            $nodeRule = ProviderPricingRule::create([
                'provider_id' => $provider->id,
                'node_id' => $node->id,
                'points_per_gb' => $nodePointsPerGb,
                'upload_ratio' => 1,
                'download_ratio' => 1,
                'min_charge_points' => 0,
                'priority' => 0,
                'status' => 'active',
                'effective_from' => '2026-01-01 00:00:00',
            ]);
        }

        ProviderSettlementTerm::create([
            'provider_id' => $provider->id,
            'currency' => 'CNY',
            'points_to_fiat_rate' => '0.05000000',
            'commission_rate' => $commissionRate,
            'payout_fee_rate' => 0,
            'tax_rate' => 0,
            'min_payout_points' => 0,
            'hold_days' => 0,
            'settlement_cycle' => 'monthly',
            'priority' => 0,
            'status' => 'active',
            'effective_from' => '2026-01-01 00:00:00',
        ]);

        return [$provider->refresh(), $node, $defaultRule, $nodeRule];
    }

    protected function makePackage(string $code = 'starter', string $basePoints = '1000.00000000', string $price = '10.00000000', string $bonus = '0.00000000'): PointsPackage
    {
        return PointsPackage::create([
            'code' => $code,
            'name' => '套餐 '.$code,
            'base_points' => $basePoints,
            'bonus_points' => $bonus,
            'price_amount' => $price,
            'currency' => 'USD',
            'status' => 'active',
            'sort_order' => 1,
            'effective_from' => '2026-01-01 00:00:00',
        ]);
    }

    /**
     * 直接造一条「已经在生效中」的绑定（可指定历史生效时间）。
     *
     * 为什么不用 SwitchProviderAction：Action 的 effective_from 永远是 now()，
     * 而计费测试需要「绑定区间覆盖过去的流量」这一前提。
     */
    protected function makeActiveBinding(
        User $user,
        Provider $provider,
        ?\DateTimeInterface $from = null,
        string $secret = 'test-secret-123456',
        ?string $externalUserId = null,
    ): UserProviderBinding {
        $binding = new UserProviderBinding([
            'user_id' => $user->id,
            'provider_id' => $provider->id,
            'external_user_id' => $externalUserId ?? ('ext-'.$user->id.'-'.$provider->id),
            'status' => UserProviderBinding::STATUS_ACTIVE,
            'effective_from' => $from ?? now(),
            'effective_to' => null,
        ]);

        $binding->setAuthSecret($secret);
        $binding->save();

        return $binding;
    }
}
