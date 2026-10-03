<?php

namespace App\Filament\Provider\Concerns;

use App\Models\Provider;
use Filament\Facades\Filament;
use Illuminate\Database\Eloquent\Builder;

/**
 * 服务商资源/组件的数据隔离基座（第二道防线）。
 *
 * 三层隔离，缺一不可：
 *  1. Filament tenancy（->tenant(Provider::class)）：自动按 provider 关系收窄查询，
 *     并在路由层调用 ProviderUser::canAccessTenant() 拦住"改 URL 换租户"；
 *  2. 本 trait 的 getEloquentQuery()：把 provider_id 显式写进 SQL，
 *     即使某天有人关掉 tenancy 或写错 Resource，也拿不到别家数据；
 *  3. Policy：按记录再判一次归属（view/update 单条记录时生效）。
 *
 * 没有租户上下文时一律返回空集（whereRaw('1 = 0')），而不是全量数据 —— 出错时宁可"看不到"。
 */
trait ScopedToProvider
{
    /** 当前登录服务商 id（无租户上下文返回 0） */
    public static function currentProviderId(): int
    {
        $tenant = Filament::getTenant();

        return $tenant instanceof Provider ? (int) $tenant->getKey() : 0;
    }

    /** 当前登录服务商（无租户上下文返回 null） */
    public static function currentProvider(): ?Provider
    {
        $tenant = Filament::getTenant();

        return $tenant instanceof Provider ? $tenant : null;
    }

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();

        $providerId = static::currentProviderId();

        if ($providerId === 0) {
            // 没有租户上下文：绝不返回数据（防止任何"未登录/未选租户"的越权读取）
            return $query->whereRaw('1 = 0');
        }

        return $query->where($query->getModel()->getTable().'.provider_id', $providerId);
    }
}
