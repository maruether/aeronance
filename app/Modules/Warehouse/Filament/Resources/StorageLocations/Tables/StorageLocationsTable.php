<?php

declare(strict_types=1);

namespace App\Modules\Warehouse\Filament\Resources\StorageLocations\Tables;

use App\Modules\Warehouse\Models\StorageLocation;
use App\Modules\Warehouse\Permissions;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\RestoreBulkAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;

final class StorageLocationsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label(__('warehouse.location.field.name'))
                    ->searchable()
                    ->sortable(),

                IconColumn::make('is_quarantine')
                    ->label(__('warehouse.location.field.is_quarantine'))
                    ->boolean()
                    ->trueIcon('heroicon-o-lock-closed')
                    ->falseIcon('heroicon-o-lock-open')
                    ->trueColor('warning')
                    ->falseColor('gray'),

                TextColumn::make('compartments_count')
                    ->label(__('warehouse.compartment.plural'))
                    ->counts('compartments')
                    ->sortable(),

                TextColumn::make('description')
                    ->label(__('warehouse.location.field.description'))
                    ->limit(60)
                    ->wrap(),
            ])
            ->defaultSort('name')
            ->filters([
                TernaryFilter::make('is_quarantine')
                    ->label(__('warehouse.location.field.is_quarantine')),
                TrashedFilter::make(),
            ])
            ->recordActions([
                EditAction::make(),

                /*
                 * Das Regalschild. Es traegt den Namen gross und einen
                 * QR-Code, mit dem die Inventur fuer genau diesen Ort
                 * aufgeht -- statt den Ort aus einer Liste zu suchen,
                 * scannt man das Schild, vor dem man ohnehin steht.
                 */
                Action::make('print_label')
                    ->label(__('warehouse.location.action.print_label'))
                    ->icon('heroicon-o-qr-code')
                    ->visible(fn (): bool => auth()->user()?->can(Permissions::STOCK_VIEW) ?? false)
                    ->url(fn (StorageLocation $r): string => route('warehouse.label.locations', [
                        'locations' => $r->getKey(),
                    ]))
                    ->openUrlInNewTab(),
            ])
            ->headerActions([
                // Alle auf einmal -- Schilder druckt man einmal fuer das ganze Lager.
                Action::make('print_all_labels')
                    ->label(__('warehouse.location.action.print_labels'))
                    ->icon('heroicon-o-qr-code')
                    ->color('gray')
                    ->visible(fn (): bool => auth()->user()?->can(Permissions::STOCK_VIEW) ?? false)
                    ->url(route('warehouse.label.locations'))
                    ->openUrlInNewTab(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                    RestoreBulkAction::make(),
                ]),
            ]);
    }
}
