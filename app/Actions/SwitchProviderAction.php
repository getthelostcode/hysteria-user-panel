<?php

namespace App\Actions;

use App\Models\Provider;
use App\Models\ProviderSwitchLog;
use App\Models\User;
use App\Models\UserProviderBinding;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * 切换服务商。
 *
 * 事务内必须遵守的操作顺序（顺序反了会撞唯一索引）：
 *   1. SELECT ... FOR UPDATE 锁定该用户全部绑定行（串行化同一用户的并发切换）
 *   2. 关闭旧绑定：status='closed' + effective_to=now
 *        → 生成列 active_binding_key 变为 NULL，uk_user_active_binding 才不再拦
 *   3. 插入新绑定：status='active' + effective_from=now
 *   4. 写 provider_switch_logs 审计
 *
 * 业务性质：
 *   - 旧流量（occurred_at < switch_at）仍然回溯到旧绑定 ⇒ 归旧服务商
 *   - 新流量归新服务商
 *   - 库层唯一索引保证任何时刻都不可能存在 0 条或 2 条 active 绑定
 */
class SwitchProviderAction
{
    public function execute(
        User $user,
        Provider $provider,
        ?string $externalUserId = null,
        ?string $secret = null,
        string $reason = '用户主动切换',
    ): UserProviderBinding {
        if ($provider->status !== 'active') {
            throw ValidationException::withMessages(['provider' => '该服务商当前不可用，无法切换。']);
        }

        // 事务 + 最多 3 次重试（并发切换时的死锁/唯一键冲突）
        return DB::transaction(function () use ($user, $provider, $externalUserId, $secret, $reason) {
            // 1) 锁用户的所有绑定行
            $bindings = UserProviderBinding::query()
                ->where('user_id', $user->id)
                ->orderBy('effective_from')
                ->lockForUpdate()
                ->get();

            /** @var UserProviderBinding|null $old */
            $old = $bindings->firstWhere('status', UserProviderBinding::STATUS_ACTIVE);

            if ($old && $old->provider_id === $provider->id) {
                throw ValidationException::withMessages(['provider' => '你当前正在使用该服务商，无需切换。']);
            }

            $now = now();

            // 防御：极端情况下（同一微秒内连续切换、或时间精度被截断）必须保证
            // effective_to 严格大于旧绑定的 effective_from，否则会违反 ck_upb_win
            if ($old && $now->lessThanOrEqualTo($old->effective_from)) {
                $now = $old->effective_from->copy()->addMillisecond();
            }

            // 2) 关闭旧绑定（必须放在插入新绑定之前）
            if ($old) {
                $old->update([
                    'status' => UserProviderBinding::STATUS_CLOSED,
                    'effective_to' => $now,
                ]);
            }

            // 3) 新建绑定
            $binding = new UserProviderBinding([
                'user_id' => $user->id,
                'provider_id' => $provider->id,
                'external_user_id' => $externalUserId ?: $this->makeExternalUserId($user),
                'status' => UserProviderBinding::STATUS_ACTIVE,
                'effective_from' => $now,
                'effective_to' => null,
                'switch_from_binding_id' => $old?->id,
                'metadata' => ['switched_at' => $now->toIso8601String()],
            ]);

            // 密钥加密落库（VARBINARY 列，存 Laravel 加密串）
            $binding->setAuthSecret($secret ?: Str::random(32));
            $binding->save();

            // 4) 审计
            ProviderSwitchLog::create([
                'user_id' => $user->id,
                'from_binding_id' => $old?->id,
                'to_binding_id' => $binding->id,
                'from_provider_id' => $old?->provider_id,
                'to_provider_id' => $provider->id,
                'effective_at' => $now,
                'status' => 'success',
                'operator_type' => 'user',
                'operator_id' => $user->id,
                'reason' => $reason,
                'detail' => [
                    'from_provider' => $old?->provider_id ? (string) $old->provider_id : null,
                    'to_provider' => (string) $provider->id,
                ],
            ]);

            return $binding;
        }, 3);
    }

    /** 服务商侧用户标识：平台用户名 + 短随机串，保证在不同服务商下也不冲突 */
    protected function makeExternalUserId(User $user): string
    {
        return $user->username.'_'.Str::lower(Str::random(4));
    }
}
