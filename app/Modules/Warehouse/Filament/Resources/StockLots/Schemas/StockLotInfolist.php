<?php

declare(strict_types=1);

namespace App\Modules\Warehouse\Filament\Resources\StockLots\Schemas;

use App\Modules\Warehouse\Enums\LotState;
use App\Modules\Warehouse\Models\LotStateChange;
use App\Modules\Warehouse\Models\StockLot;
use App\Modules\Warehouse\Models\StockMovement;
use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

/**
 * Everything known about one lot.
 *
 * The screen where traceability stops being an architectural claim and becomes
 * something a person can read: which certificate this batch arrived on, where
 * every piece of it went, and who determined what about it under which licence.
 *
 * Read-only throughout. Nothing here can be edited, because none of it was
 * entered here -- movements come from bookings, determinations from qualified
 * acts, and both are append-only.
 */
final class StockLotInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(__('warehouse.lot.section.identity'))
                ->schema([
                    TextEntry::make('lot_number')
                        ->label(__('warehouse.lot.field.lot_number')),

                    TextEntry::make('partType.name')
                        ->label(__('warehouse.lot.field.part_type')),

                    TextEntry::make('state')
                        ->label(__('warehouse.lot.field.state'))
                        ->badge()
                        ->formatStateUsing(fn (LotState $state): string => $state->label())
                        ->color(fn (LotState $state): string => match ($state) {
                            LotState::Serviceable => 'success',
                            LotState::Quarantined => 'warning',
                            LotState::Unserviceable, LotState::Unsalvageable => 'danger',
                            LotState::Disposed => 'gray',
                        }),

                    TextEntry::make('remaining')
                        ->label(__('warehouse.lot.field.remaining'))
                        ->state(fn (StockLot $record): string => sprintf(
                            '%s %s',
                            self::number($record->remainingQuantity()),
                            $record->partType?->unit_of_measure ?? '',
                        )),

                    TextEntry::make('serial_number')
                        ->label(__('warehouse.lot.field.serial_number'))
                        ->placeholder('—'),

                    TextEntry::make('batch_number')
                        ->label(__('warehouse.lot.field.batch_number'))
                        ->placeholder('—'),

                    TextEntry::make('received_at')
                        ->label(__('warehouse.lot.field.received_at'))
                        ->date('d.m.Y'),

                    TextEntry::make('expires_at')
                        ->label(__('warehouse.lot.field.expires_at'))
                        ->date('d.m.Y')
                        ->placeholder(__('warehouse.lot.no_expiry'))
                        ->color(fn (StockLot $record): ?string => $record->hasExpired() ? 'danger' : null),
                ])
                ->columns(4),

            // Laid out in the order of the printed form, so a paper certificate
            // can be checked against this line by line.
            Section::make(__('warehouse.lot.section.certificate'))
                ->schema([
                    TextEntry::make('document_type')
                        ->label(__('warehouse.lot.field.document_type'))
                        ->formatStateUsing(fn (string $state): string => __('warehouse.document_type.'.$state)),

                    TextEntry::make('document_reference')
                        ->label(__('warehouse.lot.field.document_reference'))
                        ->placeholder('—'),

                    TextEntry::make('document_issuer')
                        ->label(__('warehouse.lot.field.document_issuer'))
                        ->placeholder('—'),

                    TextEntry::make('document_issuer_approval')
                        ->label(__('warehouse.lot.field.document_issuer_approval'))
                        ->placeholder('—'),

                    TextEntry::make('document_issued_at')
                        ->label(__('warehouse.lot.field.document_issued_at'))
                        ->date('d.m.Y')
                        ->placeholder('—'),

                    TextEntry::make('document_signatory')
                        ->label(__('warehouse.lot.field.document_signatory'))
                        ->placeholder('—'),

                    IconEntry::make('has_file')
                        ->label(__('warehouse.lot.field.document_file'))
                        ->state(fn (StockLot $record): bool => $record->hasDocumentFile())
                        ->boolean()
                        ->url(fn (StockLot $record): ?string => $record->hasDocumentFile()
                            ? route('warehouse.document', ['lot' => $record, 'media' => $record->documents()->first()])
                            : null)
                        ->openUrlInNewTab(),
                ])
                ->columns(4)
                ->visible(fn (StockLot $record): bool => $record->document_type !== StockLot::DOCUMENT_NONE),

            // Where every piece of this batch went. This is the chain.
            Section::make(__('warehouse.lot.section.movements'))
                ->schema([
                    RepeatableEntry::make('movements')
                        ->hiddenLabel()
                        ->schema([
                            TextEntry::make('occurred_at')
                                ->label(__('warehouse.lot.field.when'))
                                ->dateTime('d.m.Y')
                                ->timezone(config('aeronance.organisation.timezone')),

                            TextEntry::make('type')
                                ->label(__('warehouse.lot.field.movement'))
                                ->badge()
                                ->formatStateUsing(fn ($state): string => $state->label()),

                            TextEntry::make('quantity')
                                ->label(__('warehouse.lot.field.quantity'))
                                ->formatStateUsing(fn (StockMovement $record): string => sprintf(
                                    '%s%s',
                                    $record->isInbound() ? '+' : '',
                                    self::number((float) $record->quantity),
                                ))
                                ->color(fn (StockMovement $record): string => $record->isInbound() ? 'success' : 'gray'),

                            TextEntry::make('aircraft_reference')
                                ->label(__('warehouse.lot.field.aircraft'))
                                ->placeholder('—'),

                            TextEntry::make('work_order_reference')
                                ->label(__('warehouse.lot.field.work_order'))
                                ->placeholder('—'),

                            TextEntry::make('user.name')
                                ->label(__('warehouse.lot.field.by'))
                                ->placeholder('—'),
                        ])
                        ->columns(6),
                ]),

            // Determinations, with the credential they were made under. Copied
            // at the time, so this stays readable whatever happens to the
            // account afterwards -- see E7.
            Section::make(__('warehouse.lot.section.determinations'))
                ->schema([
                    RepeatableEntry::make('stateChanges')
                        ->hiddenLabel()
                        ->schema([
                            TextEntry::make('occurred_at')
                                ->label(__('warehouse.lot.field.when'))
                                ->dateTime('d.m.Y H:i')
                                ->timezone(config('aeronance.organisation.timezone')),

                            TextEntry::make('transition')
                                ->label(__('warehouse.lot.field.transition'))
                                ->state(fn (LotStateChange $record): string => sprintf(
                                    '%s → %s',
                                    $record->from_state->label(),
                                    $record->to_state->label(),
                                )),

                            TextEntry::make('quarantine_tag')
                                ->label(__('warehouse.lot.field.tag'))
                                ->placeholder('—')
                                ->badge(),

                            // The credential is part of the line rather than a
                            // note beneath it: whether an entry was a qualified
                            // determination or a precaution is the first thing
                            // anyone reading this wants to know.
                            TextEntry::make('certifier')
                                ->label(__('warehouse.lot.field.determined_by'))
                                ->state(fn (LotStateChange $record): string => $record->isDetermination()
                                    ? sprintf('%s — %s', $record->certifierDescription(), __('warehouse.lot.qualified_act'))
                                    : sprintf('%s — %s', $record->user?->name ?? '—', __('warehouse.lot.precautionary'))),

                            TextEntry::make('reason')
                                ->label(__('warehouse.lot.field.reason'))
                                ->columnSpanFull(),
                        ])
                        ->columns(4),
                ])
                ->visible(fn (StockLot $record): bool => $record->stateChanges()->exists()),
        ]);
    }

    private static function number(float $value): string
    {
        return rtrim(rtrim(number_format($value, 3, ',', '.'), '0'), ',');
    }
}
