<?php

namespace App\Filament\Provider\Resources;

use App\Filament\Provider\Concerns\ScopedToProvider;
use App\Filament\Provider\Resources\ProviderPricingRuleResource\Pages;
use App\Models\ProviderNode;
use App\Models\ProviderPricingRule;
use App\Models\ProviderUser;
use App\Support\Decimal;
use App\Support\StatusBadge;
use Filament\Facades\Filament;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\Facades\DB;

/**
 * 定价管理（**版本化：改价 = 新增一条，绝不改旧记录**）。
 *
 * 本面板刻意做到「只读 + 新增」：
 *  - 没有编辑/删除动作（Policy 里 update/delete 恒为 false）；
 *  - 新增走 UpdatePricingAction：校验区间冲突 + 截断被覆盖的旧规则 + 写入新规则，
 *    全过程在一个事务里完成，旧记录的价格字段一个字节都不动；
 *  - 支持定时生效：把「生效时间」填成未来时间即可，生效前老价继续命中。
 *
 * 这样任何历史账单都能凭 usage_ledger.pricing_rule_id 回溯出「当时的价格」。
 */
class ProviderPricingRuleResource extends Resource
{
    use ScopedToProvider;

    protected static ?string $model = ProviderPricingRule::class;

    protected static ?string $navigationIcon = 'heroicon-o-currency-yen';

    protected static ?string $navigationGroup = '定价管理';

    protected static ?string $navigationLabel = '定价规则';

    protected static ?string $modelLabel = '定价规则';

    protected static ?string $pluralModelLabel = '定价规则';

    protected static ?int $navigationSort = 1;

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('适用范围')
                ->description('不选节点 = 服务商级默认价（对所有节点生效）；选了节点 = 节点覆盖价，优先级高于默认价。')
                ->schema([
                    Forms\Components\Select::make('node_id')
                        ->label('适用节点')
                        ->options(fn (): array => ProviderNode::query()
                            ->ofProvider(static::currentProviderId())
                            ->orderBy('name')
                            ->pluck('name', 'id')
                            ->all())
                        ->placeholder('服务商级默认价（全部节点）')
                        ->nullable()
                        ->searchable()
                        ->native(false)
                        ->helperText('只列出本服务商自己的节点。'),

                    Forms\Components\TextInput::make('priority')
                        ->label('优先级')
                        ->numeric()
                        ->default(0)
                        ->helperText('数值越大越优先（节点价本身已优先于默认价）。'),
                ])
                ->columns(2),

            Forms\Components\Section::make('价格')
                ->description('多少个 Hyper Points = 1 GB。改价请新建一条，系统会自动截断旧规则。')
                ->schema([
                    Forms\Components\TextInput::make('points_per_gb')
                        ->label('Points / GB')
                        ->numeric()
                        ->required()
                        ->minValue(0)
                        ->helperText('例如 12 表示 1GB 收 12 Points。'),

                    Forms\Components\TextInput::make('min_charge_points')
                        ->label('单次最低扣费 (Points)')
                        ->numeric()
                        ->default(0)
                        ->minValue(0),

                    Forms\Components\TextInput::make('upload_ratio')
                        ->label('上行计费系数')
                        ->numeric()
                        ->default(1)
                        ->minValue(0)
                        ->helperText('1 = 按实际上行字节计费，例如 0.5 表示上行打五折。'),

                    Forms\Components\TextInput::make('download_ratio')
                        ->label('下行计费系数')
                        ->numeric()
                        ->default(1)
                        ->minValue(0),
                ])
                ->columns(2),

            Forms\Components\Section::make('生效时间')
                ->description('区间为左闭右开 [生效时间, 失效时间)。填未来时间即为「定时生效」。')
                ->schema([
                    Forms\Components\DateTimePicker::make('effective_from')
                        ->label('生效时间 (UTC)')
                        ->required()
                        ->default(fn () => now())
                        ->seconds(false),

                    Forms\Components\DateTimePicker::make('effective_to')
                        ->label('失效时间 (UTC)')
                        ->seconds(false)
                        ->helperText('留空 = 长期有效。若填写，需保证该时间之后仍有价格可用（否则会被拒绝）。'),
                ])
                ->columns(2),

            Forms\Components\Textarea::make('remark')
                ->label('变更说明')
                ->rows(2)
                ->maxLength(255)
                ->helperText('建议写清楚这次改价的原因，便于日后对账。'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('effective_from', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('scope')
                    ->label('适用范围')
                    ->state(fn (ProviderPricingRule $record) => $record->node_id === null
                        ? '服务商级默认价'
                        : ('节点：'.($record->node?->name ?? $record->node_id)))
                    ->badge()
                    ->color(fn (ProviderPricingRule $record) => $record->node_id === null ? 'gray' : 'primary'),

                Tables\Columns\TextColumn::make('points_per_gb')
                    ->label('Points/GB')
                    ->formatStateUsing(fn ($state) => Decimal::points($state, 8, ''))
                    ->weight('bold')
                    ->sortable(),

                Tables\Columns\TextColumn::make('upload_ratio')
                    ->label('上行系数')
                    ->formatStateUsing(fn ($state) => Decimal::group($state, 6))
                    ->toggleable(),

                Tables\Columns\TextColumn::make('download_ratio')
                    ->label('下行系数')
                    ->formatStateUsing(fn ($state) => Decimal::group($state, 6))
                    ->toggleable(),

                Tables\Columns\TextColumn::make('min_charge_points')
                    ->label('最低扣费')
                    ->formatStateUsing(fn ($state) => Decimal::points($state, 8, ''))
                    ->toggleable(),

                Tables\Columns\TextColumn::make('priority')->label('优先级')->sortable(),

                Tables\Columns\TextColumn::make('effective_from')
                    ->label('生效时间 (UTC)')
                    ->dateTime('Y-m-d H:i')
                    ->sortable()
                    ->description(fn (ProviderPricingRule $record) => $record->effective_from > now() ? '待生效' : '已生效'),

                Tables\Columns\TextColumn::make('effective_to')
                    ->label('失效时间 (UTC)')
                    ->dateTime('Y-m-d H:i')
                    ->placeholder('长期')
                    ->description(fn (ProviderPricingRule $record) => $record->effective_to !== null && $record->effective_to < now() ? '已被新版本截断' : null),

                Tables\Columns\TextColumn::make('status')
                    ->label('状态')
                    ->badge()
                    ->formatStateUsing(fn (string $state) => StatusBadge::pricingRule($state)['label'])
                    ->color(fn (string $state) => StatusBadge::pricingRule($state)['color'])
                    ->icon(fn (string $state) => StatusBadge::pricingRule($state)['icon']),

                Tables\Columns\TextColumn::make('remark')->label('变更说明')->wrap()->limit(40)->toggleable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('node_id')
                    ->label('适用范围')
                    ->options(fn (): array => array_merge(
                        ['__default' => '服务商级默认价'],
                        ProviderNode::query()->ofProvider(static::currentProviderId())->pluck('name', 'id')->all(),
                    ))
                    ->query(function ($query, array $data) {
                        if (($data['value'] ?? null) === '__default') {
                            return $query->whereNull('node_id');
                        }

                        return $data['value'] ? $query->where('node_id', $data['value']) : $query;
                    }),

                Tables\Filters\TernaryFilter::make('active_now')
                    ->label('当前是否生效')
                    ->placeholder('全部')
                    ->trueLabel('当前生效中')
                    ->falseLabel('未生效/已过期')
                    ->queries(
                        true: fn ($query) => $query->effectiveAt(now())->where('status', 'active'),
                        false: fn ($query) => $query->where(fn ($q) => $q->where('effective_from', '>', now())->orWhere('effective_to', '<=', now())->orWhere('status', 'inactive')),
                    ),
            ])
            ->actions([
                // 未生效的规则可以作废（配置写错了要能撤回），已生效的一律只能靠新版本覆盖
                Tables\Actions\Action::make('void')
                    ->label('作废（未生效）')
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalDescription('仅未生效的规则可以作废。作废后该规则不再参与定价匹配。')
                    ->visible(fn (ProviderPricingRule $record) => $record->effective_from > now() && $record->status === 'active')
                    ->action(function (ProviderPricingRule $record): void {
                        // Action 内部再次校验：归属 + 未生效（不依赖前端 visible）
                        if ((int) $record->provider_id !== static::currentProviderId()) {
                            Notification::make()->title('无权操作该规则')->danger()->send();

                            return;
                        }

                        if ($record->effective_from <= now()) {
                            Notification::make()
                                ->title('已生效的规则不能作废')
                                ->body('改价请新增一条规则来覆盖，历史账单需要凭旧规则回溯。')
                                ->danger()
                                ->send();

                            return;
                        }

                        DB::transaction(function () use ($record) {
                            ProviderPricingRule::query()
                                ->ofProvider(static::currentProviderId())
                                ->whereKey($record->getKey())
                                ->lockForUpdate()
                                ->update(['status' => 'inactive']);
                        });

                        Notification::make()->title('规则已作废')->success()->send();
                    }),
            ])
            ->bulkActions([])
            ->emptyStateHeading('还没有定价规则')
            ->emptyStateDescription('先建一条「服务商级默认价」，再按节点做覆盖价。改价请新增记录，历史价格不会被改写。');
    }

    public static function canCreate(): bool
    {
        $user = Filament::auth()->user();

        return $user instanceof ProviderUser && parent::canCreate();
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListProviderPricingRules::route('/'),
            'create' => Pages\CreateProviderPricingRule::route('/create'),
        ];
    }
}
