<?php

namespace App\Filament\Provider\Resources;

use App\Filament\Provider\Concerns\ScopedToProvider;
use App\Filament\Provider\Resources\ProviderUserBindingResource\Pages;
use App\Models\UserProviderBinding;
use App\Support\StatusBadge;
use Filament\Forms\Components\DatePicker;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * 我的用户（绑定关系，**只读**）。
 *
 * 展示「当前/历史上绑定过本服务商的用户」：external_user_id 是服务商节点侧识别用户的口径
 * （Hysteria 上报里带的就是它），用户改名不影响它。
 * 绑定关系由用户主动切换服务商产生，服务商不能在这里创建/修改/删除任何绑定。
 */
class ProviderUserBindingResource extends Resource
{
    use ScopedToProvider;

    protected static ?string $model = UserProviderBinding::class;

    protected static ?string $navigationIcon = 'heroicon-o-users';

    protected static ?string $navigationGroup = '流量与用户';

    protected static ?string $navigationLabel = '我的用户';

    protected static ?string $modelLabel = '用户绑定';

    protected static ?string $pluralModelLabel = '我的用户';

    protected static ?int $navigationSort = 3;

    public static function canCreate(): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('effective_from', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('user.username')
                    ->label('平台用户')
                    ->searchable()
                    ->description(fn (UserProviderBinding $record) => $record->user?->email),

                Tables\Columns\TextColumn::make('external_user_id')
                    ->label('服务商侧用户 ID')
                    ->copyable()
                    ->searchable()
                    ->limit(32),

                Tables\Columns\TextColumn::make('status')
                    ->label('状态')
                    ->badge()
                    ->formatStateUsing(fn (string $state) => StatusBadge::binding($state)['label'])
                    ->color(fn (string $state) => StatusBadge::binding($state)['color'])
                    ->icon(fn (string $state) => StatusBadge::binding($state)['icon']),

                Tables\Columns\TextColumn::make('effective_from')
                    ->label('生效时间 (UTC)')
                    ->dateTime('Y-m-d H:i')
                    ->sortable(),

                Tables\Columns\TextColumn::make('effective_to')
                    ->label('结束时间 (UTC)')
                    ->dateTime('Y-m-d H:i')
                    ->placeholder('持续中'),

                Tables\Columns\TextColumn::make('switch_from_binding_id')
                    ->label('切换来源')
                    ->placeholder('首次绑定')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label('状态')
                    ->multiple()
                    ->options(UserProviderBinding::statusLabels()),

                Filter::make('effective')
                    ->label('生效时间区间')
                    ->form([
                        DatePicker::make('from')->label('开始日期')->native(false),
                        DatePicker::make('until')->label('结束日期')->native(false),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when($data['from'] ?? null, fn (Builder $q, $date) => $q->where('effective_from', '>=', $date.' 00:00:00.000000'))
                        ->when($data['until'] ?? null, fn (Builder $q, $date) => $q->where('effective_from', '<', date('Y-m-d', strtotime($date.' +1 day')).' 00:00:00.000000'))),
            ])
            ->actions([])
            ->bulkActions([])
            ->emptyStateHeading('还没有用户绑定')
            ->emptyStateDescription('用户在用户中心选择本服务商后，这里会出现绑定关系。');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListProviderUserBindings::route('/'),
        ];
    }
}
