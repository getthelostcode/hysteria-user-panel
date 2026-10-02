<?php

namespace App\Actions;

use App\Models\PointsOrder;
use App\Models\PointsPackage;
use App\Models\User;
use App\Models\UserPointsLedger;
use App\Models\UserPointsWallet;
use App\Support\Decimal;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * 购买 Points（法币 → Hyper Points）。
 *
 * 完整链路（全部在一个事务里）：
 *   1. 创建 points_orders（pending）
 *   2. 模拟支付成功（真实环境由支付回调触发，且必须幂等）
 *   3. 锁定 user_points_wallets 行，用 bcmath 加余额（绝不 float）
 *   4. 写 user_points_ledger（credit，biz_type=recharge），balance_after 为快照
 *   5. 回填 order.ledger_id，订单置 paid
 *
 * 幂等保障（库层）：
 *   - points_orders.uk_po_idem(idempotency_key)
 *   - points_orders.uk_po_pay_ref(payment_channel, payment_ref)  ← 同一支付流水不可能入账两次
 *   - user_points_ledger.uk_upl_idem(idempotency_key)
 */
class PurchasePointsAction
{
    /**
     * @param  string  $paymentChannel  支付通道；sandbox = 平台内模拟支付
     * @param  string|null  $idempotencyKey  外部传入的幂等键（同一请求重放时复用）
     */
    public function execute(
        User $user,
        PointsPackage $package,
        string $paymentChannel = 'sandbox',
        ?string $idempotencyKey = null,
    ): PointsOrder {
        if ($package->status !== 'active') {
            throw ValidationException::withMessages(['package' => '该套餐已下架，请选择其它套餐。']);
        }

        return DB::transaction(function () use ($user, $package, $paymentChannel, $idempotencyKey) {
            $orderNo = 'PO'.now()->format('YmdHis').strtoupper(Str::random(6));

            // 1) 下单。points_amount = base + bonus，与库层 CHECK ck_po_sum 口径一致
            $order = PointsOrder::create([
                'order_no' => $orderNo,
                'user_id' => $user->id,
                'package_id' => $package->id,
                'base_points' => $package->base_points,
                'bonus_points' => $package->bonus_points,
                'points_amount' => $package->totalPoints(),
                'fiat_amount' => $package->price_amount,
                'fiat_currency' => $package->currency,
                'payment_method' => $paymentChannel === 'sandbox' ? 'sandbox' : 'online',
                'payment_channel' => $paymentChannel,
                'status' => PointsOrder::STATUS_PENDING,
                'idempotency_key' => $idempotencyKey ? 'recharge:'.$idempotencyKey : 'recharge:'.$orderNo,
                'expire_at' => now()->addMinutes(30),
            ]);

            // 2) 模拟支付成功。真实环境这里由支付回调触发（回调必须校验签名 + 幂等）
            $paymentRef = 'SIM-'.Str::uuid()->toString();
            $order->update([
                'status' => PointsOrder::STATUS_PAID,
                'paid_at' => now(),
                'payment_ref' => $paymentRef,
                'raw_payload' => ['channel' => $paymentChannel, 'ref' => $paymentRef, 'simulated' => true],
            ]);

            // 3) 锁定钱包行，bcmath 加余额
            $wallet = UserPointsWallet::query()->where('user_id', $user->id)->lockForUpdate()->first();

            if (! $wallet) {
                $wallet = UserPointsWallet::create(['user_id' => $user->id]);
                $wallet = UserPointsWallet::query()->where('user_id', $user->id)->lockForUpdate()->first();
            }

            $newBalance = Decimal::add($wallet->balance, $order->points_amount);

            // 4) 账本是唯一权威：先写分录，再更新钱包缓存
            $ledger = UserPointsLedger::create([
                'user_id' => $user->id,
                'biz_type' => UserPointsLedger::BIZ_RECHARGE,
                'direction' => UserPointsLedger::DIRECTION_CREDIT,
                'amount' => $order->points_amount,
                'balance_after' => $newBalance,
                'biz_ref_type' => 'points_orders',
                'biz_ref_id' => $order->id,
                'idempotency_key' => 'recharge-paid:'.$paymentChannel.':'.$paymentRef,
                'remark' => sprintf('购买套餐「%s」', $package->name),
            ]);

            $wallet->update([
                'balance' => $newBalance,
                'total_recharged' => Decimal::add($wallet->total_recharged, $order->points_amount),
                'last_ledger_id' => $ledger->id,
                'version' => $wallet->version + 1,
            ]);

            // 5) 回填订单
            $order->update(['ledger_id' => $ledger->id]);

            return $order->refresh();
        });
    }
}
