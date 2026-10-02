<?php

namespace App\Filament\User\Widgets;

use App\Models\UserPointsLedger;
use App\Support\Decimal;
use App\Support\StatusBadge;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;

/**
 * 最近 10 条积分流水（Dashboard 底部）。
 */
class RecentPointsLedgerTable extends TableWidget
{
    protected static ?int $sort = 3;

    protected int|string|array $columnSpan = 'full';

    protected function getTableHeading(): ?string
    {
        return '最近积分流水';
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(
                UserPointsLedger::query()
                    ->where('user_id', auth()->id())
                    ->orderByDesc('id')
                    ->limit(10)
            )
            ->paginated(false)
            ->columns([
                Tables\Columns\TextColumn::make('created_at')
                    ->label('时间(UTC)')
                    ->dateTime('Y-m-d H:i:s'),

                Tables\Columns\TextColumn::make('biz_type')
                    ->label('类型')
                    ->badge()
                    // 文字 + 图标 + 颜色三重表达
                    ->formatStateUsing(fn (string $state) => StatusBadge::pointsLedger($state)['label'])
                    ->color(fn (string $state) => StatusBadge::pointsLedger($state)['color'])
                    ->icon(fn (string $state) => StatusBadge::pointsLedger($state)['icon']),

                Tables\Columns\TextColumn::make('signed_amount')
                    ->label('变动')
                    ->state(fn (UserPointsLedger $record) => $record->signedAmount())
                    ->suffix(' Points')
                    ->formatStateUsing(fn (string $state) => (str_starts_with($state, '-') ? '-' : '+').Decimal::points(ltrim($state, '-'), 8, ''))
                    ->color(fn (UserPointsLedger $record) => $record->isCredit() ? 'success' : 'danger')
                    ->weight('bold'),

                Tables\Columns\TextColumn::make('balance_after')
                    ->label('余额')
                    ->state(fn (UserPointsLedger $record) => Decimal::points($record->balance_after, 8, ''))
                    ->suffix(' Points'),

                Tables\Columns\TextColumn::make('remark')
                    ->label('备注')
                    ->limit(30)
                    ->wrap(),
            ])
            ->actions([])
            ->emptyStateHeading('还没有积分变动');
    }
}
