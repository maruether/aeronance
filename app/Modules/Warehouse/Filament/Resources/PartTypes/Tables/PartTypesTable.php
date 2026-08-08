<?php

declare(strict_types=1);

namespace App\Modules\Warehouse\Filament\Resources\PartTypes\Tables;

use App\Modules\Warehouse\Enums\PartClassification;
use App\Modules\Warehouse\Models\PartType;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\RestoreBulkAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

final class PartTypesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            /*
             * Der Bestand kommt als EIN Aggregat pro Seite mit, nicht als eine
             * SUM-Query je Zeile: availableStock() liest den vorberechneten
             * Wert, sobald der Scope ihn geliefert hat -- genau dafuer wurde
             * er gebaut, benutzt hat ihn nur nie jemand. Bei fuenfzig Zeilen
             * sind das fuenfzig gesparte Queries pro Seitenaufbau, mal drei,
             * weil auch Farbe und Fettdruck der Spalte nachrechnen.
             */
            ->modifyQueryUsing(fn (Builder $query): Builder => $query
                ->withAvailableStock()
                ->with('storageCompartment.location'))
            ->columns([
                TextColumn::make('name')
                    ->label(__('warehouse.part_type.field.name'))
                    ->searchable()
                    ->sortable(),

                TextColumn::make('classification')
                    ->label(__('warehouse.part_type.field.classification'))
                    ->badge()
                    ->formatStateUsing(fn (PartClassification $state): string => $state->label())
                    ->color(fn (PartClassification $state): string => match ($state) {
                        PartClassification::Component => 'info',
                        PartClassification::StandardPart => 'gray',
                        PartClassification::ConsumableMaterial => 'warning',
                    }),

                TextColumn::make('ipc_part_number')
                    ->label(__('warehouse.part_type.field.ipc_part_number'))
                    ->searchable()
                    ->toggleable(),

                // Computed, not stored: stock is the sum of its movements (E1).
                TextColumn::make('stock')
                    ->label(__('warehouse.part_type.field.stock'))
                    ->state(fn (PartType $record): string => sprintf(
                        '%s %s',
                        rtrim(rtrim(number_format($record->availableStock(), 3, ',', '.'), '0'), ','),
                        $record->unit_of_measure,
                    ))
                    ->description(fn (PartType $record): ?string => $record->minimum_stock !== null
                        ? __('warehouse.part_type.minimum_is', ['n' => $record->minimum_stock])
                        : null)
                    ->color(fn (PartType $record): ?string => $record->isBelowMinimum() ? 'danger' : null)
                    ->weight(fn (PartType $record): ?string => $record->isBelowMinimum() ? 'bold' : null),

                IconColumn::make('lot_tracked')
                    ->label(__('warehouse.part_type.field.lot_tracked'))
                    ->state(fn (PartType $record): bool => $record->isLotTracked())
                    ->boolean()
                    ->tooltip(fn (PartType $record): string => $record->isLotTracked()
                        ? __('warehouse.part_type.help.lot_tracked_yes')
                        : __('warehouse.part_type.help.lot_tracked_no')),

                TextColumn::make('storageCompartment.name')
                    ->label(__('warehouse.part_type.field.compartment'))
                    ->formatStateUsing(fn ($state, PartType $record): string => $record->storageCompartment?->fullName() ?? '—')
                    ->toggleable(),
            ])
            ->defaultSort('name')
            ->filters([
                SelectFilter::make('classification')
                    ->label(__('warehouse.part_type.field.classification'))
                    ->options(fn (): array => collect(PartClassification::cases())
                        ->mapWithKeys(fn (PartClassification $c): array => [$c->value => $c->label()])
                        ->all()),

                Filter::make('below_minimum')
                    ->label(__('warehouse.part_type.filter.below_minimum'))
                    ->query(fn (Builder $query): Builder => $query->belowMinimum()),

                TrashedFilter::make(),
            ])
            ->recordActions([EditAction::make()])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                    RestoreBulkAction::make(),
                ]),
            ]);
    }
}
