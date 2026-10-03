<?php

namespace App\Filament\Provider\Resources\ProviderPricingRuleResource\Pages;

use App\Actions\UpdatePricingAction;
use App\Filament\Provider\Resources\ProviderPricingRuleResource;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

/**
 * 新增定价规则 = 走 UpdatePricingAction。
 *
 * 表单本身不做落库，所有业务规则（归属校验、区间冲突、截断旧价）都在 Action 里，
 * 保证「唯一定价写入入口」。Action 抛出的校验异常会被转成字段级错误（data.*），
 * 让服务商在表单里直接看到哪里冲突了。
 */
class CreateProviderPricingRule extends CreateRecord
{
    protected static string $resource = ProviderPricingRuleResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        try {
            return app(UpdatePricingAction::class)->execute(
                providerId: (int) Filament::getTenant()->getKey(),
                data: $data,
                operatorId: Filament::auth()->id(),
            );
        } catch (ValidationException $e) {
            // 转成表单字段错误：data.<字段>
            $bag = [];

            foreach ($e->errors() as $field => $messages) {
                $bag['data.'.$field] = $messages;
            }

            throw ValidationException::withMessages($bag);
        }
    }

    protected function getCreatedNotification(): ?Notification
    {
        return Notification::make()
            ->title('定价已生效')
            ->body('旧规则已按新规则的生效时间自动截断，历史账单不受影响。')
            ->success()
            ->send();
    }

    protected function getRedirectUrl(): string
    {
        return static::getResource()::getUrl('index');
    }
}
