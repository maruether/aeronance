<?php

declare(strict_types=1);

namespace App\Modules\Warehouse\Filament\Resources\StockMovements\Tables;

use App\Modules\Warehouse\Actions\ReverseMovement;
use App\Modules\Warehouse\Enums\MovementType;
use App\Modules\Warehouse\Models\StockMovement;
use App\Modules\Warehouse\Permissions;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Throwable;

/**
 * The ledger as a list.
 *
 * The correction action is the only thing here that writes anything, and the
 * form says what it is about to do before it does it: not an edit, a second
 * entry beside the first. People expect a correction to make the mistake go
 * away, and it does the opposite -- it makes it explained.
 */
final class StockMovementsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('occurred_at', 'desc')
            ->columns([
                TextColumn::make('occurred_at')
                    ->label(__('warehouse.movement.field.occurred_at'))
                    ->dateTime('d.m.Y H:i')
                    ->sortable(),

                TextColumn::make('type')
                    ->label(__('warehouse.movement.field.type'))
                    ->badge()
                    ->formatStateUsing(fn (MovementType $state): string => $state->label())
                    ->color(fn (MovementType $state): string => match ($state) {
                        MovementType::Receipt, MovementType::RepairReturn => 'success',
                        MovementType::Issue, MovementType::RepairDispatch => 'info',
                        MovementType::Correction => 'warning',
                        MovementType::Scrap, MovementType::Disposal => 'danger',
                    }),

                TextColumn::make('partType.name')
                    ->label(__('warehouse.part_type.singular'))
                    ->searchable()
                    ->sortable(),

                TextColumn::make('lot.lot_number')
                    ->label(__('warehouse.issue.field.lot'))
                    ->searchable()
                    ->placeholder('—'),

                TextColumn::make('quantity')
                    ->label(__('warehouse.movement.field.quantity'))
                    ->alignEnd()
                    ->formatStateUsing(fn (string $state, StockMovement $r): string => sprintf(
                        '%s%s %s',
                        (float) $state > 0 ? '+' : '',
                        rtrim(rtrim(number_format((float) $state, 3, ',', '.'), '0'), ','),
                        $r->partType?->unit_of_measure ?? '',
                    ))
                    ->color(fn (StockMovement $r): string => $r->isInbound() ? 'success' : 'danger'),

                TextColumn::make('aircraft_reference')
                    ->label(__('warehouse.issue.field.aircraft'))
                    ->placeholder('—')
                    ->toggleable(),

                TextColumn::make('work_order_reference')
                    ->label(__('warehouse.issue.field.work_order'))
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('user.name')
                    ->label(__('warehouse.movement.field.user'))
                    ->placeholder('—')
                    ->toggleable(),

                TextColumn::make('note')
                    ->label(__('warehouse.movement.field.note'))
                    ->wrap()
                    ->limit(60)
                    ->placeholder('—')
                    // Both directions of the correction chain, so an entry never
                    // stands there looking unexplained.
                    ->description(fn (StockMovement $r): ?string => self::correctionNote($r)),
            ])
            ->filters([
                SelectFilter::make('type')
                    ->label(__('warehouse.movement.field.type'))
                    ->multiple()
                    ->options(fn (): array => collect(MovementType::cases())
                        ->mapWithKeys(fn (MovementType $t): array => [$t->value => $t->label()])
                        ->all()),

                SelectFilter::make('part_type_id')
                    ->label(__('warehouse.part_type.singular'))
                    ->relationship('partType', 'name')
                    ->searchable()
                    ->preload(),

                Filter::make('corrections')
                    ->label(__('warehouse.movement.filter.corrections'))
                    ->query(fn (Builder $query): Builder => $query->where(function (Builder $q): void {
                        $q->where('type', MovementType::Correction->value)
                            ->orWhereNotNull('reverses_movement_id');
                    })),
            ])
            ->recordActions([
                self::correctAction(),
            ]);
    }

    /**
     * Says which entry corrects which, from whichever end one is looking at.
     */
    private static function correctionNote(StockMovement $movement): ?string
    {
        if ($movement->reverses_movement_id !== null) {
            return __('warehouse.movement.reverses', ['id' => $movement->reverses_movement_id]);
        }

        $reversal = app(ReverseMovement::class)->reversalOf($movement);

        return $reversal !== null
            ? __('warehouse.movement.reversed_by', [
                'date' => $reversal->occurred_at->format('d.m.Y'),
            ])
            : null;
    }

    private static function correctAction(): Action
    {
        return Action::make('correct')
            ->label(__('warehouse.movement.correct_action'))
            ->icon('heroicon-o-arrow-uturn-left')
            ->color('warning')
            // Offered only where it can succeed. An action that is always shown
            // and usually refuses teaches people to ignore refusals.
            ->visible(fn (StockMovement $r): bool => app(ReverseMovement::class)->isReversible($r)
                && (auth()->user()?->can(Permissions::STOCK_CORRECT) ?? false))
            ->modalHeading(__('warehouse.movement.correct_action'))
            ->modalDescription(__('warehouse.movement.help.correction'))
            ->schema([
                DatePicker::make('occurred_at')
                    ->label(__('warehouse.movement.field.occurred_at'))
                    ->default(now())
                    ->required(),

                Textarea::make('reason')
                    ->label(__('warehouse.movement.field.reason'))
                    ->required()
                    ->rows(2)
                    ->helperText(__('warehouse.movement.help.reason')),
            ])
            ->action(function (StockMovement $record, array $data): void {
                try {
                    app(ReverseMovement::class)->handle(
                        $record,
                        auth()->user(),
                        (string) $data['reason'],
                        $data['occurred_at'] ?? null,
                    );
                } catch (Throwable $e) {
                    Notification::make()
                        ->danger()
                        ->title(__('warehouse.movement.notification.refused'))
                        ->body($e->getMessage())
                        ->persistent()
                        ->send();

                    return;
                }

                Notification::make()
                    ->success()
                    ->title(__('warehouse.movement.notification.corrected'))
                    ->body(__('warehouse.movement.help.correction'))
                    ->send();
            });
    }
}
