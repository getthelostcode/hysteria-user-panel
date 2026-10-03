<?php

namespace App\Filament\Provider\Resources;

use App\Actions\TestNodeConnectivityAction;
use App\Filament\Provider\Concerns\ScopedToProvider;
use App\Filament\Provider\Resources\ProviderNodeResource\Pages;
use App\Models\ProviderNode;
use App\Models\ProviderUser;
use App\Services\ProviderUsageStatistics;
use App\Support\Bytes;
use App\Support\StatusBadge;
use Filament\Facades\Filament;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Infolists;
use Filament\Infolists\Infolist;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * 节点管理。
 *
 * 隔离：Model 有 provider() 关系 ⇒ Filament tenancy 自动收窄；
 *      本类再用 ScopedToProvider 显式加 provider_id 条件（第二道防线）；
 *      单条记录的查看/编辑由 ProviderNodePolicy 兜第三道。
 *
 * 写操作全部走 Action：新建 → CreateNodeAction（生成并加密密钥、host:port 去重），
 * 状态变更 → ToggleNodeStatusAction（归属二次校验）。表单的 CreateAction 被显式禁用。
 */
class ProviderNodeResource extends Resource
{
    use ScopedToProvider;

    protected static ?string $model = ProviderNode::class;

    protected static ?string $navigationIcon = 'heroicon-o-server-stack';

    protected static ?string $navigationGroup = '节点管理';

    protected static ?string $navigationLabel = '节点列表';

    protected static ?string $modelLabel = '节点';

    protected static ?string $pluralModelLabel = '节点';

    protected static ?int $navigationSort = 1;

    protected static ?string $recordTitleAttribute = 'node_code';

    /** 导航徽标显示在线节点数，让服务商一眼看到节点是否掉线 */
    public static function getNavigationBadge(): ?string
    {
        $providerId = static::currentProviderId();

        if ($providerId === 0) {
            return null;
        }

        return (string) ProviderNode::query()
            ->ofProvider($providerId)
            ->whereIn('status', ['active', 'maintenance'])
            ->where('last_seen_at', '>=', now()->subMinutes(10))
            ->count();
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'success';
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('基本信息')
                ->description('节点名称、区域用于给用户展示；节点编码用于流量上报与服务端配置。')
                ->schema([
                    Forms\Components\TextInput::make('name')
                        ->label('节点名称')
                        ->required()
                        ->maxLength(128),

                    Forms\Components\TextInput::make('node_code')
                        ->label('节点编码')
                        ->maxLength(64)
                        ->helperText('留空则按名称自动生成；创建后不可修改（流量上报按它归集）。')
                        ->disabled(fn (string $operation): bool => $operation === 'edit')
                        ->dehydrated(),

                    Forms\Components\Select::make('region')
                        ->label('区域')
                        ->options([
                            '香港' => '香港', '台湾' => '台湾', '日本' => '日本', '韩国' => '韩国',
                            '新加坡' => '新加坡', '美国' => '美国', '英国' => '英国',
                            '德国' => '德国', '荷兰' => '荷兰', '其它' => '其它',
                        ])
                        ->searchable()
                        ->native(false),

                    Forms\Components\TextInput::make('country_code')
                        ->label('国家代码')
                        ->maxLength(2)
                        ->helperText('ISO-3166-1 alpha2，例如 HK / JP'),
                ])
                ->columns(2),

            Forms\Components\Section::make('接入地址')
                ->description('节点侧 Hysteria 2 服务监听地址；同一服务商下 host + port 不允许重复。')
                ->schema([
                    Forms\Components\TextInput::make('host')
                        ->label('Host')
                        ->required()
                        ->maxLength(255)
                        ->placeholder('hk01.example.com'),

                    Forms\Components\TextInput::make('port')
                        ->label('端口')
                        ->numeric()
                        ->required()
                        ->default(443)
                        ->minValue(1)
                        ->maxValue(65535),

                    Forms\Components\Select::make('protocol')
                        ->label('协议')
                        ->options(['hysteria2' => 'Hysteria 2'])
                        ->default('hysteria2')
                        ->required(),

                    Forms\Components\TextInput::make('capacity_mbps')
                        ->label('带宽上限 (Mbps)')
                        ->numeric()
                        ->minValue(0),
                ])
                ->columns(2),

            Forms\Components\Section::make('扩展配置')
                ->description('JSON，例如 {"sni":"hk01.example.com","insecure":false}。SNI 会写进节点服务端配置。')
                ->schema([
                    Forms\Components\KeyValue::make('config')
                        ->label('配置项')
                        ->keyLabel('键')
                        ->valueLabel('值')
                        ->addActionLabel('添加配置项')
                        // 密钥等敏感项由系统维护，不允许在这里手写
                        ->helperText('密钥由系统生成并加密存储，不在此处展示。'),
                ]),
        ]);
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist->schema([
            Infolists\Components\Section::make('节点概览')->schema([
                Infolists\Components\TextEntry::make('name')->label('节点名称'),
                Infolists\Components\TextEntry::make('node_code')->label('节点编码'),
                Infolists\Components\TextEntry::make('status')
                    ->label('状态')
                    ->badge()
                    ->formatStateUsing(fn (string $state) => StatusBadge::node($state)['label'])
                    ->color(fn (string $state) => StatusBadge::node($state)['color'])
                    ->icon(fn (string $state) => StatusBadge::node($state)['icon']),
                Infolists\Components\TextEntry::make('region')->label('区域')->placeholder('—'),
                Infolists\Components\TextEntry::make('endpoint')->label('接入地址')
                    ->state(fn (ProviderNode $record) => $record->endpoint()),
                Infolists\Components\TextEntry::make('capacity_mbps')->label('带宽上限 (Mbps)')->placeholder('未设置'),
                Infolists\Components\TextEntry::make('last_seen_at')
                    ->label('最近心跳 (UTC)')
                    ->state(fn (ProviderNode $record) => $record->last_seen_at === null
                        ? '从未上报'
                        : $record->last_seen_at->format('Y-m-d H:i:s').($record->isRecentlySeen() ? '（在线）' : '（超过 10 分钟无心跳）')),
                Infolists\Components\TextEntry::make('today_traffic')
                    ->label('当日流量')
                    ->state(function (ProviderNode $record): string {
                        $t = app(ProviderUsageStatistics::class)->nodeTrafficToday($record->id);

                        return sprintf('%s（上行 %s / 下行 %s）', Bytes::human($t['total']), Bytes::human($t['upload']), Bytes::human($t['download']));
                    }),
                Infolists\Components\TextEntry::make('auth_secret')
                    ->label('节点密钥（脱敏）')
                    ->state(fn (ProviderNode $record) => $record->maskedAuthSecret())
                    ->hint('需要明文请用「查看明文配置」动作'),
                Infolists\Components\TextEntry::make('created_at')->label('创建时间 (UTC)')->dateTime('Y-m-d H:i:s'),
            ])->columns(2),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            // 每个节点的当日/本月流量：用两次相关子查询一次取回，避免 N+1（节点数通常十几条）
            ->modifyQueryUsing(function (Builder $query): Builder {
                $stats = app(ProviderUsageStatistics::class);
                [$todayFrom, $todayTo] = $stats->todayRange();
                [$monthFrom, $monthTo] = $stats->monthRange();

                return $query
                    // 必须显式带上 provider_nodes.*：selectSub 会把 columns 从 null 变成非空，
                    // 此时 Eloquent 的默认 `select *` 不再生效，模型会拿不到 id（列表直接 500）。
                    ->select(['provider_nodes.*'])
                    ->selectSub(
                        fn ($sub) => $sub->from('traffic_usage_hourly')
                            ->selectRaw('COALESCE(SUM(upload_bytes + download_bytes), 0)')
                            ->whereColumn('node_id', 'provider_nodes.id')
                            ->where('period_start', '>=', $todayFrom)
                            ->where('period_start', '<', $todayTo),
                        'today_bytes',
                    )
                    ->selectSub(
                        fn ($sub) => $sub->from('traffic_usage_hourly')
                            ->selectRaw('COALESCE(SUM(upload_bytes + download_bytes), 0)')
                            ->whereColumn('node_id', 'provider_nodes.id')
                            ->where('period_start', '>=', $monthFrom)
                            ->where('period_start', '<', $monthTo),
                        'month_bytes',
                    );
            })
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->label('节点')
                    ->description(fn (ProviderNode $record) => $record->node_code)
                    ->searchable(['name', 'node_code'])
                    ->sortable(),

                Tables\Columns\TextColumn::make('region')->label('区域')->placeholder('—')->toggleable(),

                Tables\Columns\TextColumn::make('host')
                    ->label('接入地址')
                    ->state(fn (ProviderNode $record) => $record->endpoint())
                    ->copyable(),

                Tables\Columns\TextColumn::make('capacity_mbps')->label('带宽 (Mbps)')->placeholder('—')->sortable(),

                Tables\Columns\TextColumn::make('status')
                    ->label('状态')
                    ->badge()
                    ->formatStateUsing(fn (string $state) => StatusBadge::node($state)['label'])
                    ->color(fn (string $state) => StatusBadge::node($state)['color'])
                    ->icon(fn (string $state) => StatusBadge::node($state)['icon']),

                Tables\Columns\TextColumn::make('last_seen_at')
                    ->label('最近心跳')
                    ->since()
                    ->placeholder('从未上报')
                    ->color(fn (ProviderNode $record) => $record->isRecentlySeen() ? 'success' : 'danger')
                    ->sortable(),

                // 每台服务器的流量：今日 + 本月（与计费同源，取自小时聚合表）
                Tables\Columns\TextColumn::make('today_bytes')
                    ->label('今日流量')
                    ->state(fn (ProviderNode $record) => Bytes::human((int) ($record->today_bytes ?? 0)))
                    ->alignEnd()
                    ->sortable(),

                Tables\Columns\TextColumn::make('month_bytes')
                    ->label('本月流量')
                    ->state(fn (ProviderNode $record) => Bytes::human((int) ($record->month_bytes ?? 0)))
                    ->alignEnd()
                    ->sortable(),

                Tables\Columns\TextColumn::make('pricing_count')
                    ->label('定价规则')
                    ->counts('pricingRules')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->label('状态')
                    ->options(ProviderNode::statusLabels())
                    ->multiple(),

                // 运维视角：一眼分出「在线（10 分钟内有心跳）」与「离线/从未上报」
                Tables\Filters\TernaryFilter::make('online')
                    ->label('是否在线')
                    ->placeholder('全部')
                    ->trueLabel('在线')
                    ->falseLabel('离线 / 未上报')
                    ->queries(
                        true: fn (Builder $q) => $q->where('last_seen_at', '>=', now()->subMinutes(10)),
                        false: fn (Builder $q) => $q->where(fn (Builder $inner) => $inner
                            ->whereNull('last_seen_at')
                            ->orWhere('last_seen_at', '<', now()->subMinutes(10))),
                    ),

                Tables\Filters\SelectFilter::make('region')
                    ->label('区域')
                    ->options(fn () => ProviderNode::query()
                        ->ofProvider(static::currentProviderId())
                        ->whereNotNull('region')
                        ->distinct()
                        ->pluck('region', 'region')
                        ->all()),
            ])
            ->actions([
                Tables\Actions\ViewAction::make()->label('详情'),

                Tables\Actions\EditAction::make()->label('编辑'),

                // 测试连通性：TCP 探测 host:port，结果写回 config.last_probe
                Tables\Actions\Action::make('probe')
                    ->label('测试连通性')
                    ->icon('heroicon-o-signal')
                    ->color('info')
                    ->action(function (ProviderNode $record): void {
                        // Action 内部再校验一次归属：即使有人伪造 record，也不能探测别家节点
                        if ((int) $record->provider_id !== static::currentProviderId()) {
                            Notification::make()->title('无权操作该节点')->danger()->send();

                            return;
                        }

                        $result = app(TestNodeConnectivityAction::class)->execute($record);

                        Notification::make()
                            ->title($result['ok'] ? '连通性正常' : '连通性异常')
                            ->body($result['message'])
                            ->status($result['ok'] ? 'success' : 'danger')
                            ->send();
                    }),

                // 重新生成密钥：旧密钥立即失效，明文只展示这一次
                Tables\Actions\Action::make('rotate_key')
                    ->label('重新生成密钥')
                    ->icon('heroicon-o-key')
                    ->color('warning')
                    ->requiresConfirmation()
                    ->modalHeading('重新生成节点密钥')
                    ->modalDescription('生成后旧密钥立即失效，节点侧需要重新拉取配置。新密钥只显示这一次。')
                    ->action(function (ProviderNode $record): void {
                        if ((int) $record->provider_id !== static::currentProviderId()) {
                            Notification::make()->title('无权操作该节点')->danger()->send();

                            return;
                        }

                        $result = app(\App\Actions\RegenerateNodeKeyAction::class)
                            ->execute(static::currentProviderId(), $record->id);

                        Notification::make()
                            ->title('新密钥已生成（仅此一次显示）')
                            ->body($result['secret'])
                            ->persistent()
                            ->warning()
                            ->send();
                    }),

                // 启用 / 停用 / 下线
                Tables\Actions\ActionGroup::make([
                    Tables\Actions\Action::make('enable')
                        ->label('启用节点')
                        ->icon('heroicon-o-play')
                        ->color('success')
                        ->visible(fn (ProviderNode $record) => $record->status !== 'active')
                        ->action(fn (ProviderNode $record) => static::changeStatus($record, 'active')),

                    Tables\Actions\Action::make('maintenance')
                        ->label('置为维护中')
                        ->icon('heroicon-o-wrench')
                        ->color('warning')
                        ->visible(fn (ProviderNode $record) => $record->status !== 'maintenance')
                        ->action(fn (ProviderNode $record) => static::changeStatus($record, 'maintenance')),

                    Tables\Actions\Action::make('disable')
                        ->label('停用节点')
                        ->icon('heroicon-o-no-symbol')
                        ->color('gray')
                        ->requiresConfirmation()
                        ->modalDescription('停用后节点不再接受新用户，但已绑定用户的历史流量仍按定价规则计费。')
                        ->visible(fn (ProviderNode $record) => $record->status !== 'disabled')
                        ->action(fn (ProviderNode $record) => static::changeStatus($record, 'disabled')),

                    Tables\Actions\Action::make('offline')
                        ->label('下线节点')
                        ->icon('heroicon-o-signal-slash')
                        ->color('danger')
                        ->requiresConfirmation()
                        ->visible(fn (ProviderNode $record) => $record->status !== 'offline')
                        ->action(fn (ProviderNode $record) => static::changeStatus($record, 'offline')),
                ])->label('状态操作')->icon('heroicon-o-adjustments-horizontal'),
            ])
            // 删除在本面板永久关闭：节点承载历史账单与流量归属
            ->bulkActions([])
            ->emptyStateHeading('还没有节点')
            ->emptyStateDescription('添加第一个 Hysteria 2 节点后，节点侧按生成的配置启动即可开始上报流量。');
    }

    /** 统一的状态变更入口：全部经 ToggleNodeStatusAction（内部做归属校验） */
    protected static function changeStatus(ProviderNode $record, string $status): void
    {
        try {
            app(\App\Actions\ToggleNodeStatusAction::class)
                ->execute(static::currentProviderId(), $record->id, $status);

            Notification::make()->title('节点状态已更新')->success()->send();
        } catch (\Throwable $e) {
            Notification::make()->title('操作失败')->body($e->getMessage())->danger()->send();
        }
    }

    /** 表单创建走 CreateNodeAction（含密钥生成与去重），因此这里不暴露默认的 CreateAction */
    public static function canCreate(): bool
    {
        $user = Filament::auth()->user();

        return $user instanceof ProviderUser && parent::canCreate();
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListProviderNodes::route('/'),
            'create' => Pages\CreateProviderNode::route('/create'),
            'view' => Pages\ViewProviderNode::route('/{record}'),
            'edit' => Pages\EditProviderNode::route('/{record}/edit'),
        ];
    }
}
