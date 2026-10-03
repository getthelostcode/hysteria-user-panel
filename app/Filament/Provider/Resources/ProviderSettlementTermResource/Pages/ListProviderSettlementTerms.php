<?php

namespace App\Filament\Provider\Resources\ProviderSettlementTermResource\Pages;

use App\Filament\Provider\Resources\ProviderSettlementTermResource;
use Filament\Actions;
use Filament\Facades\Filament;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Support\Facades\DB;

class ListProviderSettlementTerms extends ListRecords
{
    protected static string $resource = ProviderSettlementTermResource::class;

    protected function getHeaderActions(): array
    {
        return [
            // 服务商不能直接改条款（等于自己改抽成），只能提交申请，由平台侧审核
            Actions\Action::make('request_change')
                ->label('申请调整条款')
                ->icon('heroicon-o-paper-airplane')
                ->color('warning')
                ->modalHeading('申请调整结算条款')
                ->modalDescription('提交后由平台审核。审核通过前条款保持原样，历史账单永远按当时的条款计算。')
                ->form([
                    Forms\Components\Textarea::make('reason')
                        ->label('调整原因')
                        ->required()
                        ->rows(3)
                        ->maxLength(500),
                    Forms\Components\KeyValue::make('suggested')
                        ->label('希望调整的项')
                        ->keyLabel('字段（如 points_to_fiat_rate / min_payout_points）')
                        ->valueLabel('期望值')
                        ->addActionLabel('添加一项'),
                ])
                ->action(function (array $data): void {
                    $provider = Filament::getTenant();

                    // 只往 providers.metadata 里追加一条申请记录：
                    // 不碰 provider_settlement_terms（条款是版本化 + 平台口径）
                    DB::transaction(function () use ($provider, $data) {
                        $provider->refresh();
                        $metadata = $provider->metadata ?? [];
                        $requests = $metadata['settlement_term_requests'] ?? [];

                        $requests[] = [
                            'requested_at' => now()->toIso8601String(),
                            'requested_by' => Filament::auth()->id(),
                            'reason' => $data['reason'],
                            'suggested' => $data['suggested'] ?? [],
                            'status' => 'pending',
                        ];

                        $metadata['settlement_term_requests'] = $requests;
                        $provider->update(['metadata' => $metadata]);
                    });

                    Notification::make()
                        ->title('申请已提交')
                        ->body('平台侧审核后会在条款版本里体现，请留意结算条款页的更新。')
                        ->success()
                        ->send();
                }),
        ];
    }
}
