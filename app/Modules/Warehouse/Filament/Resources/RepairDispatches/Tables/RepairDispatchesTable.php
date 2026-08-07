<?php

declare(strict_types=1);

namespace App\Modules\Warehouse\Filament\Resources\RepairDispatches\Tables;

use App\Modules\Warehouse\Actions\ReceiveFromRepair;
use App\Modules\Warehouse\Enums\RepairState;
use App\Modules\Warehouse\Models\RepairDispatch;
use App\Modules\Warehouse\Models\StockLot;
use App\Modules\Warehouse\Permissions;
use App\Modules\Warehouse\Support\DocumentTypes;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Throwable;

/**
 * Parts that are away, and what happens when they come back.
 *
 * The return form is the consequential part of this screen, and it is built
 * around one question: did a Form 1 come back with the part? Everything else
 * follows from the answer -- serviceable or quarantined, free to travel or
 * still tied to one aircraft -- so the form says so before the answer is given
 * rather than reporting it afterwards.
 */
final class RepairDispatchesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('dispatched_at', 'desc')
            ->columns([
                TextColumn::make('partType.name')
                    ->label(__('warehouse.part_type.singular'))
                    ->searchable()
                    ->sortable()
                    ->description(fn (RepairDispatch $r): ?string => filled($r->serial_number)
                        ? 'S/N '.$r->serial_number
                        : $r->lot?->lot_number),

                TextColumn::make('shop_name')
                    ->label(__('warehouse.repair.field.shop_name'))
                    ->searchable()
                    ->description(fn (RepairDispatch $r): ?string => $r->shop_approval)
                    ->placeholder(fn (RepairDispatch $r): string => $r->destination->label()),

                TextColumn::make('dispatched_at')
                    ->label(__('warehouse.repair.field.dispatched_at'))
                    ->date('d.m.Y')
                    ->sortable(),

                TextColumn::make('expected_back_at')
                    ->label(__('warehouse.repair.field.expected_back_at'))
                    ->date('d.m.Y')
                    ->sortable()
                    ->placeholder('—')
                    // Not an error -- shops run late. But an eight-month-old
                    // dispatch is one everybody has stopped thinking about.
                    ->color(fn (RepairDispatch $r): ?string => $r->isOverdue() ? 'warning' : null),

                TextColumn::make('restricted_to_aircraft')
                    ->label(__('warehouse.repair.field.restriction'))
                    ->placeholder('—')
                    ->badge()
                    ->color('warning')
                    ->tooltip(fn (RepairDispatch $r): ?string => $r->carriesAircraftRestriction()
                        ? __('warehouse.repair.help.restriction_at_stake', ['aircraft' => $r->restricted_to_aircraft])
                        : null),

                TextColumn::make('state')
                    ->label(__('warehouse.repair.field.state'))
                    ->badge()
                    ->formatStateUsing(fn (RepairState $state): string => $state->label())
                    ->color(fn (RepairState $state): string => match ($state) {
                        RepairState::Dispatched => 'info',
                        RepairState::Returned => 'success',
                        RepairState::WrittenOff => 'danger',
                    }),

                TextColumn::make('returnedLot.lot_number')
                    ->label(__('warehouse.repair.field.returned_lot'))
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('state')
                    ->label(__('warehouse.repair.field.state'))
                    ->options(fn (): array => collect(RepairState::cases())
                        ->mapWithKeys(fn (RepairState $s): array => [$s->value => $s->label()])
                        ->all())
                    ->default(RepairState::Dispatched->value),

                Filter::make('overdue')
                    ->label(__('warehouse.repair.filter.overdue'))
                    ->query(fn (Builder $query): Builder => $query->overdue()),
            ])
            ->recordActions([
                self::returnAction(),
                self::writeOffAction(),
            ]);
    }

    private static function returnAction(): Action
    {
        return Action::make('receive')
            ->label(__('warehouse.repair.return_action'))
            ->icon('heroicon-o-arrow-uturn-left')
            ->visible(fn (RepairDispatch $r): bool => $r->state->isOpen()
                && (auth()->user()?->can(Permissions::STOCK_REPAIR) ?? false))
            ->modalDescription(fn (RepairDispatch $r): string => $r->carriesAircraftRestriction()
                ? __('warehouse.repair.help.restriction_at_stake', ['aircraft' => $r->restricted_to_aircraft])
                : __('warehouse.repair.help.form_one_lifts'))
            ->schema([
                DatePicker::make('returned_at')
                    ->label(__('warehouse.repair.field.returned_at'))
                    ->default(now())
                    ->required(),

                Select::make('document_type')
                    ->label(__('warehouse.lot.field.document_type'))
                    ->options(fn (): array => DocumentTypes::options())
                    ->default(StockLot::DOCUMENT_FORM_ONE)
                    ->selectablePlaceholder(false)
                    ->live()
                    ->helperText(__('warehouse.repair.help.form_one_lifts')),

                TextInput::make('document_reference')
                    ->label(__('warehouse.lot.field.document_reference'))
                    ->maxLength(128)
                    ->required(fn (callable $get): bool => $get('document_type') === StockLot::DOCUMENT_FORM_ONE)
                    ->visible(fn (callable $get): bool => $get('document_type') !== StockLot::DOCUMENT_NONE),

                TextInput::make('document_issuer')
                    ->label(__('warehouse.lot.field.document_issuer'))
                    ->maxLength(160)
                    ->default(fn (RepairDispatch $r): ?string => $r->shop_name)
                    ->visible(fn (callable $get): bool => $get('document_type') !== StockLot::DOCUMENT_NONE),

                TextInput::make('document_issuer_approval')
                    ->label(__('warehouse.lot.field.document_issuer_approval'))
                    ->maxLength(64)
                    ->default(fn (RepairDispatch $r): ?string => $r->shop_approval)
                    ->visible(fn (callable $get): bool => $get('document_type') !== StockLot::DOCUMENT_NONE),

                DatePicker::make('document_issued_at')
                    ->label(__('warehouse.lot.field.document_issued_at'))
                    ->visible(fn (callable $get): bool => $get('document_type') !== StockLot::DOCUMENT_NONE),

                Textarea::make('note')
                    ->label(__('warehouse.repair.field.return_note'))
                    ->rows(2),
            ])
            ->action(function (RepairDispatch $record, array $data): void {
                try {
                    $lot = app(ReceiveFromRepair::class)->handle(
                        $record,
                        auth()->user(),
                        lotData: $data,
                        returnedAt: $data['returned_at'] ?? null,
                        note: $data['note'] ?? null,
                    );
                } catch (Throwable $e) {
                    Notification::make()
                        ->danger()
                        ->title(__('warehouse.repair.notification.refused'))
                        ->body($e->getMessage())
                        ->persistent()
                        ->send();

                    return;
                }

                Notification::make()
                    ->success()
                    ->title(__('warehouse.repair.notification.returned', ['lot' => $lot->lot_number]))
                    ->send();

                // The consequence, said out loud. Whether the part came back
                // free or still tied is the thing the whole trip was about.
                if ($lot->state->allowsIssue() && $record->carriesAircraftRestriction()) {
                    Notification::make()
                        ->success()
                        ->title(__('warehouse.repair.notification.returned_free', [
                            'aircraft' => $record->restricted_to_aircraft,
                        ]))
                        ->persistent()
                        ->send();
                } elseif (! $lot->state->allowsIssue()) {
                    Notification::make()
                        ->warning()
                        ->title(__('warehouse.repair.notification.returned_quarantined'))
                        ->persistent()
                        ->send();
                }
            });
    }

    private static function writeOffAction(): Action
    {
        return Action::make('writeOff')
            ->label(__('warehouse.repair.write_off_action'))
            ->icon('heroicon-o-x-circle')
            ->color('danger')
            ->requiresConfirmation()
            ->visible(fn (RepairDispatch $r): bool => $r->state->isOpen()
                && (auth()->user()?->can(Permissions::STOCK_REPAIR) ?? false))
            ->modalDescription(__('warehouse.repair.help.write_off'))
            ->schema([
                Textarea::make('reason')
                    ->label(__('warehouse.repair.field.reason'))
                    ->required()
                    ->rows(2),
            ])
            ->action(function (RepairDispatch $record, array $data): void {
                try {
                    app(ReceiveFromRepair::class)->writeOff(
                        $record,
                        auth()->user(),
                        (string) $data['reason'],
                    );
                } catch (Throwable $e) {
                    Notification::make()
                        ->danger()
                        ->title(__('warehouse.repair.notification.refused'))
                        ->body($e->getMessage())
                        ->persistent()
                        ->send();

                    return;
                }

                Notification::make()
                    ->success()
                    ->title(__('warehouse.repair.notification.written_off'))
                    ->send();
            });
    }
}
