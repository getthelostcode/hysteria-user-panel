<?php

namespace App\Filament\User\Pages;

use App\Models\UserPointsLedger;
use Filament\Pages\Page;

/**
 * 我的积分（资产总览，只读）。
 *
 * 余额来自 user_points_wallets（缓存层），并同时展示「按账本聚合的权威余额」用于自证一致性。
 */
class MyWallet extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-wallet';

    protected static ?string $navigationGroup = '资产';

    protected static ?string $navigationLabel = '我的积分';

    protected static ?string $title = '我的积分';

    protected static ?int $navigationSort = 1;

    protected static string $view = 'filament.user.pages.my-wallet';

    /** 钱包（可能还没记录，回退为零值） */
    public function wallet(): array
    {
        $user = auth()->user();
        $wallet = $user->wallet()->first();

        return [
            'balance' => (string) ($wallet->balance ?? '0'),
            'frozen' => (string) ($wallet->frozen ?? '0'),
            'total_recharged' => (string) ($wallet->total_recharged ?? '0'),
            'total_consumed' => (string) ($wallet->total_consumed ?? '0'),
        ];
    }

    /**
     * 权威余额 = 账本求和（signed_amount）。
     * 与钱包缓存应当永远相等；不等就说明有代码绕过了账本，属于严重 bug。
     */
    public function ledgerBalance(): string
    {
        $sum = UserPointsLedger::query()
            ->where('user_id', auth()->id())
            ->sum('signed_amount');

        return (string) ($sum ?? '0');
    }

    /** 最近 5 条流水，页面底部快速预览 */
    public function recentLedger()
    {
        return UserPointsLedger::query()
            ->where('user_id', auth()->id())
            ->orderByDesc('id')
            ->limit(5)
            ->get();
    }
}
