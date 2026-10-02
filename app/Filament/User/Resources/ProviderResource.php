<?php

namespace App\Filament\User\Resources;

use App\Actions\SwitchProviderAction;
use App\Filament\User\Resources\ProviderResource\Pages;
use App\Models\Provider;
use App\Support\Decimal;
use App\Support\StatusBadge;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;

/**
 * 服务商列表。
 *
 * 展示：服务商名称、状态、节点数、当前起价（Points/GB）。
 * 「查看节点与定价」用模态框展开 provider_nodes + 当前生效的 provider_pricing_rules。
 * 「切换到此服务商」直接调用 SwitchProviderAction（事务内关旧建新 + 审计）。
 */
class ProviderResource extends Resource
{
    protected static ?string $model = Provider::class;

    protected static ?string $navigationIcon = 'heroicon-o-server-stack';

    protected static ?string $navigationGroup = '服务';

    protected static ?string $navigationLabel = '服务商列表';

    protected static ?string $modelLabel = '服务商';

    protected static ?string $pluralModelLabel = '服务商列表';

    protected static ?int $navigationSort = 1;

    protected static bool $isScopedToTenant = false;

    public static function canCreate(): bool
    {
        return false;
    }

    /** 只展示可用的服务商 */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->where('status', 'active')
            ->withCount(['nodes' => fn (Builder $q) => $q->where('status', 'active')]);
    }

    public static function table(Table $table): Table
    {
        $currentProviderId = auth()->user()?->currentProvider()?->id;

        return $table
            ->defaultSort('name')
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->label('服务商')
                    ->weight('bold')
                    ->description(fn (Provider $record) => $record->code)
                    ->searchable(),

                Tables\Columns\TextColumn::make('status')
                    ->label('状态')
                    ->badge()
                    // 文字 + 图标 + 颜色三重表达
                    ->formatStateUsing(fn (string $state) => StatusBadge::provider($state)['label'])
                    ->color(fn (string $state) => StatusBadge::provider($state)['color'])
                    ->icon(fn (string $state) => StatusBadge::provider($state)['icon']),

                Tables\Columns\TextColumn::make('nodes_count')
                    ->label('可用节点数')
                    ->badge()
                    ->color('gray'),

                Tables\Columns\TextColumn::make('price_hint')
                    ->label('价格')
                    ->state(fn (Provider $record) => $record->priceHint()),

                Tables\Columns\TextColumn::make('current')
                    ->label('当前使用')
                    ->state(fn (Provider $record) => $record->id === $currentProviderId ? '使用中' : '未使用')
                    ->badge()
                    ->icon(fn (Provider $record) => $record->id === $currentProviderId ? 'heroicon-m-check-badge' : 'heroicon-m-minus-small')
                    ->color(fn (Provider $record) => $record->id === $currentProviderId ? 'success' : 'gray'),
            ])
            ->filters([
                Tables\Filters\TernaryFilter::make('has_nodes')
                    ->label('仅显示有可用节点的服务商')
                    ->queries(
                        true: fn (Builder $q) => $q->has('nodes'),
                        false: fn (Builder $q) => $q->doesntHave('nodes'),
                    ),
            ])
            ->actions([
                Tables\Actions\Action::make('detail')
                    ->label('查看节点与定价')
                    ->icon('heroicon-o-eye')
                    ->modalHeading(fn (Provider $record) => $record->name.' · 节点与当前定价')
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('关闭')
                    ->modalContent(fn (Provider $record) => view('filament.user.provider-detail', [
                        'provider' => $record,
                        'nodes' => $record->nodes()->where('status', 'active')->orderBy('id')->get(),
                        'rules' => $record->pricingRules()->active()->effectiveAt(now())->get(),
                    ])),

                Tables\Actions\Action::make('switch')
                    ->label(fn (Provider $record) => $record->id === $currentProviderId ? '当前使用中' : '切换到此服务商')
                    ->icon('heroicon-o-arrow-path')
                    ->color('primary')
                    ->button()
                    ->disabled(fn (Provider $record) => $record->id === $currentProviderId)
                    ->requiresConfirmation()
                    ->modalHeading(fn (Provider $record) => '切换到 '.$record->name)
                    ->modalDescription('切换后新流量按新服务商计费，旧流量仍归旧服务商。')
                    ->modalSubmitActionLabel('确认切换')
                    ->action(function (Provider $record): void {
                        try {
                            // 切换服务商必须走 Action：事务内关闭旧绑定 + 新建绑定 + 写审计
                            $binding = app(SwitchProviderAction::class)->execute(auth()->user(), $record);

                            Notification::make()
                                ->title('切换成功')
                                ->body(sprintf('已切换到 %s，标识：%s', $record->name, $binding->external_user_id))
                                ->success()
                                ->send();
                        } catch (ValidationException $exception) {
                            Notification::make()
                                ->title('切换失败')
                                ->body(collect($exception->errors())->flatten()->first())
                                ->danger()
                                ->send();
                        }
                    }),
            ])
            ->bulkActions([])
            ->emptyStateHeading('暂无可用的服务商');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListProviders::route('/'),
        ];
    }
}
