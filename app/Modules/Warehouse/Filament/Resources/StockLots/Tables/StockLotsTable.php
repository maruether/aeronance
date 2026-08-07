<?php

declare(strict_types=1);

namespace App\Modules\Warehouse\Filament\Resources\StockLots\Tables;

use App\Modules\Warehouse\Actions\ChangeLotState;
use App\Modules\Warehouse\Actions\TransferStock;
use App\Modules\Warehouse\Enums\LotState;
use App\Modules\Warehouse\Filament\Resources\StockLots\StockLotResource;
use App\Modules\Warehouse\Models\StockLot;
use App\Modules\Warehouse\Models\StorageCompartment;
use App\Modules\Warehouse\Permissions;
use App\Modules\Warehouse\Support\DocumentTypes;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\BulkAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Throwable;

/**
 * The lot list.
 *
 * Read-only as far as the fields go: a lot is created by booking goods in and
 * emptied by issuing them, never by editing a quantity. What CAN be done here
 * is change its condition -- and that goes through the action, so the rules
 * about qualifications and one-way transitions apply exactly as they do
 * everywhere else.
 */
final class StockLotsTable
{
    /**
     * Moving a lot to another compartment.
     *
     * Lives on the row rather than on a screen of its own: one is looking at
     * the lot when one decides to move it, and a separate page would mean
     * finding it a second time.
     *
     * The compartment list is not filtered down to what is allowed. Being told
     * "that shelf is not possible, and here is why" is more use than a shelf
     * that silently is not in the list -- especially here, where the reason is
     * a rule somebody should learn rather than a technicality.
     */
    public static function transferAction(): Action
    {
        return Action::make('transfer')
            ->label(__('warehouse.transfer.action'))
            ->icon('heroicon-o-arrows-right-left')
            ->visible(fn (StockLot $r): bool => $r->state !== LotState::Disposed
                && $r->remainingQuantity() > 0
                && (auth()->user()?->can(Permissions::STOCK_TRANSFER) ?? false))
            ->modalDescription(__('warehouse.transfer.help.quarantine'))
            ->schema([
                Select::make('storage_compartment_id')
                    ->label(__('warehouse.transfer.field.target'))
                    ->options(fn (): array => StorageCompartment::with('location')->get()
                        ->sortBy(fn (StorageCompartment $c): string => $c->fullName())
                        ->mapWithKeys(fn (StorageCompartment $c): array => [
                            $c->id => $c->fullName().($c->isQuarantine()
                                ? ' — '.__('warehouse.transfer.is_quarantine')
                                : ''),
                        ])
                        ->all())
                    ->searchable()
                    ->required(),

                Textarea::make('reason')
                    ->label(__('warehouse.movement.field.reason'))
                    ->rows(2)
                    ->helperText(__('warehouse.transfer.help.reason')),
            ])
            ->action(function (StockLot $record, array $data): void {
                $target = StorageCompartment::find($data['storage_compartment_id'] ?? null);

                if ($target === null) {
                    return;
                }

                try {
                    $moved = app(TransferStock::class)->handle(
                        $record,
                        $target,
                        auth()->user(),
                        $data['reason'] ?? null,
                    );
                } catch (Throwable $e) {
                    Notification::make()
                        ->danger()
                        ->title(__('warehouse.transfer.notification.refused'))
                        ->body($e->getMessage())
                        ->persistent()
                        ->send();

                    return;
                }

                Notification::make()
                    ->success()
                    ->title(__('warehouse.transfer.notification.moved', [
                        'compartment' => $target->fullName(),
                    ]))
                    ->send();

                // Said out loud, because it is a consequence of moving a box
                // and nobody expects moving a box to change anything else.
                if ($moved->state === LotState::Quarantined && $target->isQuarantine()) {
                    Notification::make()
                        ->warning()
                        ->title(__('warehouse.transfer.notification.quarantined'))
                        ->persistent()
                        ->send();
                } elseif (app(TransferStock::class)->belongsToQuarantineStore($moved, $target)) {
                    // Advice rather than a refusal -- see TransferStock. The
                    // person holding the box is the one who can put it right.
                    Notification::make()
                        ->warning()
                        ->title(__('warehouse.transfer.notification.belongs_in_quarantine'))
                        ->persistent()
                        ->send();
                }
            });
    }

    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('lot_number')
                    ->label(__('warehouse.lot.field.lot_number'))
                    ->searchable()
                    ->sortable()
                    ->description(fn (StockLot $r): ?string => $r->serial_number
                        ? 'S/N '.$r->serial_number
                        : $r->batch_number),

                TextColumn::make('partType.name')
                    ->label(__('warehouse.lot.field.part_type'))
                    ->searchable()
                    ->sortable(),

                TextColumn::make('remaining')
                    ->label(__('warehouse.lot.field.remaining'))
                    ->state(fn (StockLot $r): string => sprintf(
                        '%s %s',
                        rtrim(rtrim(number_format($r->remainingQuantity(), 3, ',', '.'), '0'), ','),
                        $r->partType?->unit_of_measure ?? '',
                    )),

                TextColumn::make('state')
                    ->label(__('warehouse.lot.field.state'))
                    ->badge()
                    ->formatStateUsing(fn (LotState $state): string => $state->label())
                    ->color(fn (LotState $state): string => match ($state) {
                        LotState::Serviceable => 'success',
                        LotState::Quarantined => 'warning',
                        LotState::Unserviceable => 'danger',
                        LotState::Unsalvageable => 'danger',
                        LotState::Disposed => 'gray',
                    }),

                TextColumn::make('expires_at')
                    ->label(__('warehouse.lot.field.expires_at'))
                    ->date('d.m.Y')
                    ->sortable()
                    ->placeholder('—')
                    ->color(fn (StockLot $r): ?string => $r->hasExpired() ? 'danger' : null)
                    ->description(fn (StockLot $r): ?string => $r->hasExpired()
                        ? __('warehouse.lot.expired')
                        : null),

                TextColumn::make('document_reference')
                    ->label(__('warehouse.lot.field.document'))
                    ->placeholder('—')
                    ->description(fn (StockLot $r): ?string => $r->document_type !== StockLot::DOCUMENT_NONE
                        ? DocumentTypes::label($r->document_type)
                        : null)
                    ->searchable(),

                // Zwischen "Nummer erfasst" und "Scan liegt vor" unterscheiden:
                // Für die tägliche Arbeit reicht die Nummer, für ein Audit nicht.
                IconColumn::make('has_document_file')
                    ->label(__('warehouse.lot.field.document_file'))
                    ->state(fn (StockLot $r): bool => $r->hasDocumentFile())
                    ->boolean()
                    ->trueIcon('heroicon-o-paper-clip')
                    ->falseIcon('heroicon-o-minus')
                    ->falseColor('gray')
                    ->url(fn (StockLot $r): ?string => $r->hasDocumentFile()
                        ? route('warehouse.document', ['lot' => $r, 'media' => $r->documents()->first()])
                        : null)
                    ->openUrlInNewTab(),

                TextColumn::make('received_at')
                    ->label(__('warehouse.lot.field.received_at'))
                    ->date('d.m.Y')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('lot_number', 'desc')
            ->filters([
                SelectFilter::make('state')
                    ->label(__('warehouse.lot.field.state'))
                    ->options(fn (): array => collect(LotState::cases())
                        ->mapWithKeys(fn (LotState $s): array => [$s->value => $s->label()])
                        ->all()),

                Filter::make('expiring')
                    ->label(__('warehouse.lot.filter.expiring'))
                    ->query(fn (Builder $query): Builder => $query->expiringWithin(90)),

                Filter::make('expired')
                    ->label(__('warehouse.lot.filter.expired'))
                    ->query(fn (Builder $query): Builder => $query
                        ->whereNotNull('expires_at')
                        ->whereDate('expires_at', '<', now()->toDateString())),

                Filter::make('in_stock')
                    ->label(__('warehouse.lot.filter.in_stock'))
                    ->default()
                    ->query(fn (Builder $query): Builder => $query->whereHas(
                        'movements', fn ($q) => $q, '>=', 1,
                    )),
            ])
            ->recordUrl(fn (StockLot $record): string => StockLotResource::getUrl('view', ['record' => $record]))
            ->recordActions([
                ActionGroup::make([
                    self::transferAction(),
                    self::quarantineAction(),
                    self::determineAction(),
                    self::printTagAction(),
                    self::printLabelAction(),
                ]),
            ])
            /*
             * Und fuer mehrere auf einmal: Wer eine Lieferung einbucht, will
             * nicht zwoelfmal einzeln drucken. Bei der Rolle laeuft das
             * durch, beim A4-Bogen fuellt es den Bogen.
             */
            ->toolbarActions([
                BulkAction::make('print_labels')
                    ->label(__('warehouse.lot.action.print_labels'))
                    ->icon('heroicon-o-printer')
                    ->visible(fn (): bool => auth()->user()?->can(Permissions::STOCK_VIEW) ?? false)
                    ->deselectRecordsAfterCompletion()
                    ->action(fn (Collection $records) => redirect()->away(route('warehouse.label.print', [
                        'lots' => $records->pluck('id')->implode(','),
                    ]))),
            ]);
    }

    /**
     * Setting a lot aside -- precautionary, reversible, no qualification.
     */
    private static function quarantineAction(): Action
    {
        return Action::make('quarantine')
            ->label(__('warehouse.lot.action.quarantine'))
            ->icon('heroicon-o-lock-closed')
            ->color('warning')
            ->visible(fn (StockLot $r): bool => $r->state === LotState::Serviceable
                && (auth()->user()?->can(Permissions::STOCK_QUARANTINE) ?? false))
            ->schema([
                Textarea::make('reason')
                    ->label(__('warehouse.lot.field.reason'))
                    ->required()
                    ->rows(3)
                    ->helperText(__('warehouse.lot.help.quarantine_reason')),
            ])
            ->action(function (StockLot $record, array $data): void {
                try {
                    $change = app(ChangeLotState::class)->handle(
                        $record, LotState::Quarantined, $data['reason'], auth()->user(),
                    );

                    Notification::make()
                        ->warning()
                        ->title(__('warehouse.lot.notification.quarantined'))
                        ->body(__('warehouse.lot.notification.tag', ['tag' => $change->quarantine_tag]))
                        ->persistent()
                        ->send();
                } catch (Throwable $e) {
                    self::refuse($e);
                }
            });
    }

    /**
     * The determinations -- qualified acts, frozen into the record.
     *
     * The options offered are only the transitions the chain actually allows
     * from where the lot is now, so an impossible step is never presented in
     * the first place.
     */
    private static function determineAction(): Action
    {
        return Action::make('determine')
            ->label(__('warehouse.lot.action.determine'))
            ->icon('heroicon-o-clipboard-document-check')
            ->color('danger')
            ->visible(fn (StockLot $r): bool => $r->state->allowedTransitions() !== []
                && (auth()->user()?->canAny([
                    Permissions::STOCK_QUARANTINE_CERTIFY,
                    Permissions::STOCK_SCRAP,
                ]) ?? false))
            ->schema(fn (StockLot $record): array => [
                Select::make('to_state')
                    ->label(__('warehouse.lot.field.new_state'))
                    ->options(collect($record->state->allowedTransitions())
                        ->mapWithKeys(fn (LotState $s): array => [$s->value => $s->label()])
                        ->all())
                    ->required(),

                Textarea::make('reason')
                    ->label(__('warehouse.lot.field.reason'))
                    ->required()
                    ->rows(3)
                    ->helperText(__('warehouse.lot.help.determination_reason')),
            ])
            ->action(function (StockLot $record, array $data): void {
                try {
                    app(ChangeLotState::class)->handle(
                        $record,
                        LotState::from($data['to_state']),
                        $data['reason'],
                        auth()->user(),
                    );

                    Notification::make()
                        ->success()
                        ->title(__('warehouse.lot.notification.state_changed'))
                        ->send();
                } catch (Throwable $e) {
                    self::refuse($e);
                }
            });
    }

    /**
     * Reprinting a slip that is already hanging on a part.
     *
     * The number is never reissued, so this hands out the same one again --
     * useful when a tag is lost or unreadable, and the reason the number lives
     * on the state change rather than being generated at print time.
     */
    private static function printTagAction(): Action
    {
        return Action::make('print_tag')
            ->label(__('warehouse.lot.action.print_tag'))
            ->icon('heroicon-o-printer')
            ->visible(fn (StockLot $r): bool => $r->stateChanges()->whereNotNull('quarantine_tag')->exists())
            ->url(fn (StockLot $r): string => route('warehouse.tag.single', [
                'change' => $r->stateChanges()->whereNotNull('quarantine_tag')->first(),
            ]))
            ->openUrlInNewTab();
    }

    /**
     * Der Losaufkleber — das Etikett, das am Teil bleibt.
     *
     * Anders als der Sperrzettel ohne Bedingung sichtbar: Jedes Los hat ein
     * Etikett, und es wird nachgedruckt, wenn es kaputtgeht oder das Teil
     * umgepackt wird.
     */
    private static function printLabelAction(): Action
    {
        return Action::make('print_label')
            ->label(__('warehouse.lot.action.print_label'))
            ->icon('heroicon-o-tag')
            ->visible(fn (): bool => auth()->user()?->can(Permissions::STOCK_VIEW) ?? false)
            ->url(fn (StockLot $r): string => route('warehouse.label.print', ['lots' => $r->getKey()]))
            ->openUrlInNewTab();
    }

    /**
     * Refusals are shown as they were raised.
     *
     * The action's messages say precisely what is wrong -- missing permission,
     * missing qualification, a transition that does not exist -- and rewording
     * them here would lose exactly the part that helps.
     */
    private static function refuse(Throwable $e): void
    {
        Notification::make()
            ->danger()
            ->title(__('warehouse.lot.notification.refused'))
            ->body($e->getMessage())
            ->persistent()
            ->send();
    }
}
