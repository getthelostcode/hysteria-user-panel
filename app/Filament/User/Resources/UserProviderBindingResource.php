<?php

namespace App\Filament\User\Resources;

use App\Filament\User\Resources\UserProviderBindingResource\Pages;
use App\Models\UserProviderBinding;
use App\Support\StatusBadge;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * 我的绑定（只读）。
 *
 * 展示当前与历史绑定，体现「生效时间区间 [effective_from, effective_to)」的版本化语义。
 * 切换服务商请到「切换服务商」页或服务商列表 —— 本页没有任何写入口。
 */
class UserProviderBindingResource extends Resource
{
    protected static ?string $model = UserProviderBinding::class;

    protected static ?string $navigationIcon = 'heroicon-o-link';

    protected static ?string $navigationGroup = '服务';

    protected static ?string $navigationLabel = '我的绑定';

    protected static ?string $modelLabel = '绑定';

    protected static ?string $pluralModelLabel = '我的绑定';

    protected static ?int $navigationSort = 3;

    protected static bool $isScopedToTenant = false;

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->where('user_id', auth()->id())
            ->with('provider');
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('effective_from', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('provider.name')
                    ->label('服务商')
                    ->weight('bold'),

                Tables\Columns\TextColumn::make('external_user_id')
                    ->label('服务商侧用户标识')
                    ->fontFamily('mono')
                    ->copyable(),

                Tables\Columns\TextColumn::make('status')
                    ->label('状态')
                    ->badge()
                    // 文字 + 图标 + 颜色三重表达
                    ->formatStateUsing(fn (string $state) => StatusBadge::binding($state)['label'])
                    ->color(fn (string $state) => StatusBadge::binding($state)['color'])
                    ->icon(fn (string $state) => StatusBadge::binding($state)['icon']),

                Tables\Columns\TextColumn::make('effective_from')
                    ->label('生效起(UTC)')
                    ->dateTime('Y-m-d H:i:s'),

                Tables\Columns\TextColumn::make('effective_to')
                    ->label('生效止(UTC)')
                    ->dateTime('Y-m-d H:i:s')
                    ->placeholder('至今'),

                Tables\Columns\TextColumn::make('duration')
                    ->label('时长')
                    ->state(function (UserProviderBinding $record) {
                        $end = $record->effective_to ?? now();
                        $minutes = $record->effective_from->diffInMinutes($end);

                        return $minutes < 60
                            ? $minutes.' 分钟'
                            : ($minutes < 1440 ? round($minutes / 60, 1).' 小时' : round($minutes / 1440, 1).' 天');
                    })
                    ->toggleable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->label('状态')
                    ->options(UserProviderBinding::statusLabels()),

                Tables\Filters\SelectFilter::make('provider_id')
                    ->label('服务商')
                    ->relationship('provider', 'name'),
            ])
            ->actions([])
            ->bulkActions([])
            ->emptyStateHeading('还没有切换过服务商')
            ->emptyStateDescription('第一次选择服务商后，这里会保留完整的绑定历史。');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListUserProviderBindings::route('/'),
        ];
    }
}
