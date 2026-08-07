<?php

declare(strict_types=1);

namespace App\Modules\Warehouse\Filament\Resources\Suppliers\Tables;

use App\Modules\Warehouse\Models\Supplier;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\RestoreBulkAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

final class SuppliersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label(__('warehouse.supplier.field.name'))
                    ->searchable()
                    ->sortable(),

                /*
                 * DIE ZULASSUNG MIT AMPEL. Ohne Faerbung waere sie eine Zahl
                 * unter vielen -- und der einzige Zeitpunkt, zu dem eine
                 * abgelaufene Zulassung auffaellt, waere der Audit.
                 */
                TextColumn::make('approval_number')
                    ->label(__('warehouse.supplier.field.approval_number'))
                    ->searchable()
                    ->placeholder('—')
                    ->badge()
                    ->color(fn (Supplier $record): string => match (true) {
                        ! $record->isApprovedOrganisation() => 'gray',
                        $record->approvalHasLapsed() => 'danger',
                        $record->approvalExpiresSoon() => 'warning',
                        default => 'success',
                    })
                    ->description(fn (Supplier $record): ?string => match (true) {
                        ! $record->isApprovedOrganisation() => null,
                        $record->approval_expires_at === null => __('warehouse.supplier.approval.unlimited'),
                        $record->approvalHasLapsed() => __('warehouse.supplier.approval.lapsed', [
                            'date' => $record->approval_expires_at->format('d.m.Y'),
                        ]),
                        default => __('warehouse.supplier.approval.until', [
                            'date' => $record->approval_expires_at->format('d.m.Y'),
                        ]),
                    }),

                TextColumn::make('approval_scope')
                    ->label(__('warehouse.supplier.field.approval_scope'))
                    ->placeholder('—')
                    ->toggleable(),

                TextColumn::make('part_types_count')
                    ->label(__('warehouse.supplier.field.part_types'))
                    ->counts('partTypes')
                    ->sortable(),

                TextColumn::make('description')
                    ->label(__('warehouse.supplier.field.description'))
                    ->limit(60)
                    ->wrap(),
            ])
            ->defaultSort('name')
            ->filters([
                Filter::make('approval_lapsed')
                    ->label(__('warehouse.supplier.filter.lapsed'))
                    ->query(fn (Builder $query): Builder => $query
                        ->whereNotNull('approval_number')
                        ->whereNotNull('approval_expires_at')
                        ->whereDate('approval_expires_at', '<', now())),
                TrashedFilter::make(),
            ])
            ->recordActions([EditAction::make()])
            ->toolbarActions([
                BulkActionGroup::make([
                    // No force delete: a supplier referenced by historic lots
                    // must stay readable. Soft delete hides it from the pickers.
                    DeleteBulkAction::make(),
                    RestoreBulkAction::make(),
                ]),
            ]);
    }
}
