<?php

namespace App\Filament\Provider\Widgets;

use App\Models\UsageLedger;
use App\Support\Decimal;
use App\Support\StatusBadge;
use Filament\Facades\Filament;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;

/**
 * 最近 10 条计费明细（Dashboard 表格组件）。只读，强制限定当前服务商。
 */
class RecentUsageLedgerTable extends TableWidget
{
    protected static ?int $sort = 5;

    protected int|string|array $columnSpan = 'full';

    protected static ?string $heading = '最近 10 条计费明细';

    public function table(Table $table): Table
    {
        $providerId = (int) (Filament::getTenant()?->getKey() ?? 0);

        return $table
            ->query(UsageLedger::query()
                ->where('provider_id', $providerId)
                ->latest('id')
                ->limit(10))
            ->paginated(false)
            ->columns([
                Tables\Columns\TextColumn::make('period_start')->label('计费小时 (UTC)')->dateTime('m-d H:00'),

                Tables\Columns\TextColumn::make('user.username')->label('用户')->placeholder('—'),

                Tables\Columns\TextColumn::make('node.name')->label('节点')->placeholder('—'),

                Tables\Columns\TextColumn::make('billable_gb')
                    ->label('计费量 (GB)')
                    ->formatStateUsing(fn ($state) => Decimal::group($state, 3))
                    ->alignEnd(),

                Tables\Columns\TextColumn::make('provider_points_amount')
                    ->label('我应得')
                    ->formatStateUsing(fn ($state) => Decimal::points($state, 4, ''))
                    ->weight('bold')
                    ->color('success')
                    ->alignEnd(),

                Tables\Columns\TextColumn::make('status')
                    ->label('状态')
                    ->badge()
                    ->formatStateUsing(fn (string $state) => StatusBadge::usage($state)['label'])
                    ->color(fn (string $state) => StatusBadge::usage($state)['color'])
                    ->icon(fn (string $state) => StatusBadge::usage($state)['icon']),
            ])
            ->actions([])
            ->bulkActions([]);
    }
}
