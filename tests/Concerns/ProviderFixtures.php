<?php

namespace Tests\Concerns;

use App\Models\Provider;
use App\Models\ProviderUser;
use Illuminate\Support\Facades\Hash;

/**
 * 服务商后台测试夹具：服务商操作员账号。
 *
 * 与 HysteriaFixtures 配合使用（后者负责服务商 / 节点 / 定价 / 结算条款）。
 */
trait ProviderFixtures
{
    protected function makeProviderUser(
        ?Provider $provider = null,
        string $email = 'ops@example.com',
        string $password = 'password',
        string $status = 'active',
        ?string $name = '运营',
    ): ProviderUser {
        $provider ??= $this->makeProvider()[0];

        return ProviderUser::create([
            'provider_id' => $provider->id,
            'name' => $name,
            'email' => $email,
            'password' => Hash::make($password),
            'status' => $status,
        ]);
    }
}
