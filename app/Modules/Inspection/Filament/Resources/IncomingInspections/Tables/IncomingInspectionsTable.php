<?php

declare(strict_types=1);

namespace App\Modules\Inspection\Filament\Resources\IncomingInspections\Tables;

use App\Modules\Inspection\Actions\CompleteIncomingInspection;
use App\Modules\Inspection\Enums\InspectionState;
use App\Modules\Inspection\Filament\Resources\IncomingInspections\Schemas\ChecklistSchema;
use App\Modules\Inspection\Models\IncomingInspection;
use App\Modules\Inspection\Permissions;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

/**
 * Was angekommen ist und noch niemand angesehen hat.
 *
 * Offene zuerst und älteste zuoberst: Die Lieferung, die seit drei Wochen im
 * Karton steht, ist die, die jemanden interessieren sollte — nicht die von
 * heute Morgen.
 */
final class IncomingInspectionsTable
{
    /**
     * Die Prüfung selbst — ein Dialog, ein Absenden.
     *
     * Die Aktion RUFT NUR AUF. Jede Regel — vollständig beantwortet, Bemerkung
     * bei Beanstandung, Qualifikation beim Freigeben — steckt in
     * CompleteIncomingInspection, und zwar dort allein. Eine Prüfung im
     * Formular wäre eine zweite Wahrheit, und zwei Wahrheiten driften immer in
     * die nachgiebige Richtung.
     */
    public static function inspect(): Action
    {
        return Action::make('inspect')
            ->label(__('inspection.action.inspect'))
            ->icon(Heroicon::OutlinedClipboardDocumentCheck)
            ->modalHeading(fn (IncomingInspection $record): string => __('inspection.singular').' — '.$record->label())
            ->modalSubmitActionLabel(__('inspection.action.sign'))
            ->visible(fn (IncomingInspection $record): bool => $record->state->isOpen()
                && (auth()->user()?->can(Permissions::INSPECTION_PERFORM) ?? false))
            ->schema(fn (IncomingInspection $record): array => ChecklistSchema::for($record))
            ->action(function (IncomingInspection $record, array $data): void {
                $aktion = app(CompleteIncomingInspection::class);
                $benutzer = auth()->user();
                $antworten = $data['answers'] ?? [];
                $bemerkung = $data['note'] ?? null;

                try {
                    $angenommen = ($data['outcome'] ?? null) === InspectionState::Accepted->value;

                    $angenommen
                        ? $aktion->accept($record, $benutzer, $antworten, $bemerkung)
                        : $aktion->reject($record, $benutzer, $antworten, $bemerkung);

                    Notification::make()
                        ->success()
                        ->title($angenommen
                            ? ($record->stock_lot_id !== null
                                ? __('inspection.action.accepted')
                                : __('inspection.action.accepted_bulk'))
                            : __('inspection.action.rejected'))
                        ->send();
                } catch (\Throwable $e) {
                    /*
                     * Der Grund wird durchgereicht, nicht zu „Fehlgeschlagen"
                     * verkuerzt: „Zu ,Ausstellende Stelle' fehlt die Bemerkung"
                     * sagt, was zu tun ist -- eine allgemeine Fehlermeldung
                     * schickt jemanden ins Raten.
                     */
                    Notification::make()
                        ->danger()
                        ->title(__('inspection.action.failed'))
                        ->body($e->getMessage())
                        ->persistent()
                        ->send();
                }
            });
    }

    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('arrived_at', 'asc')
            ->columns([
                TextColumn::make('state')
                    ->label(__('inspection.field.state'))
                    ->badge()
                    ->formatStateUsing(fn (InspectionState $state): string => $state->label())
                    ->color(fn (InspectionState $state): string => $state->color()),

                TextColumn::make('partType.name')
                    ->label(__('inspection.field.part'))
                    ->searchable()
                    ->sortable(),

                TextColumn::make('lot.lot_number')
                    ->label(__('inspection.field.lot'))
                    ->searchable()
                    ->placeholder('—'),

                TextColumn::make('movement.quantity')
                    ->label(__('inspection.field.quantity'))
                    ->numeric(decimalPlaces: 2),

                TextColumn::make('arrived_at')
                    ->label(__('inspection.field.arrived_at'))
                    ->dateTime('d.m.Y')
                    ->sortable()
                    /*
                     * Wie lange steht das schon da. Die Zahl ist der eigentliche
                     * Grund fuer diese Liste: „seit 19 Tagen" liest sich anders
                     * als ein Datum, das man erst im Kopf ausrechnen muss.
                     */
                    ->description(fn (IncomingInspection $record): ?string => $record->state->isOpen()
                        ? __('inspection.since', ['days' => (int) $record->arrived_at->diffInDays(now())])
                        : null),

                TextColumn::make('decided_by_name')
                    ->label(__('inspection.field.decided_by'))
                    ->placeholder('—')
                    ->toggleable(),

                TextColumn::make('decided_at')
                    ->label(__('inspection.field.decided_at'))
                    ->dateTime('d.m.Y H:i')
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('decision_note')
                    ->label(__('inspection.field.decision_note'))
                    ->placeholder('—')
                    ->wrap()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('state')
                    ->label(__('inspection.filter.state'))
                    ->options(fn (): array => collect(InspectionState::cases())
                        ->mapWithKeys(fn (InspectionState $s): array => [$s->value => $s->label()])
                        ->all())
                    /*
                     * Offene sind voreingestellt. Wer die Liste aufmacht, will
                     * wissen, was noch zu tun ist -- nicht, was letztes Jahr
                     * angekommen ist.
                     */
                    ->default(InspectionState::Open->value),
            ])
            ->recordActions([self::inspect()])
            ->emptyStateHeading(__('inspection.empty.heading'))
            ->emptyStateDescription(__('inspection.empty.description'));
    }
}
