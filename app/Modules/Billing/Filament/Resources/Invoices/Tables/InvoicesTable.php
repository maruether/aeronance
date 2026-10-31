<?php

declare(strict_types=1);

namespace App\Modules\Billing\Filament\Resources\Invoices\Tables;

use App\Modules\Billing\Enums\InvoiceKind;
use App\Modules\Billing\Enums\InvoiceState;
use App\Modules\Billing\Models\Invoice;
use App\Modules\Billing\Support\Money;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Die Rechnungsliste -- neueste zuoberst, Überfälliges rot.
 */
final class InvoicesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('number')
                    ->label(__('billing.invoice.field.number'))
                    ->placeholder(fn (Invoice $record): string => $record->label())
                    ->searchable()
                    ->sortable()
                    ->weight('bold'),
                TextColumn::make('kind')
                    ->label(__('billing.invoice.field.kind'))
                    ->badge()
                    ->color('gray')
                    ->formatStateUsing(fn (InvoiceKind $state): string => $state->label()),
                TextColumn::make('state')
                    ->label(__('billing.invoice.field.state'))
                    ->badge()
                    ->formatStateUsing(fn (InvoiceState $state): string => $state->label())
                    ->color(fn (InvoiceState $state): string => $state->color()),
                TextColumn::make('customer_name')
                    ->label(__('billing.invoice.field.customer_name'))
                    ->searchable()
                    ->sortable()
                    ->description(fn (Invoice $record): ?string => $record->work_order_number),
                TextColumn::make('issued_at')
                    ->label(__('billing.invoice.field.issued_at'))
                    ->date('d.m.Y')
                    ->placeholder('—')
                    ->sortable(),
                TextColumn::make('due_at')
                    ->label(__('billing.invoice.field.due_at'))
                    ->date('d.m.Y')
                    ->placeholder('—')
                    ->color(fn (Invoice $record): ?string => $record->isOverdue() ? 'danger' : null)
                    ->sortable(),
                TextColumn::make('gross_total')
                    ->label(__('billing.invoice.field.gross_total'))
                    ->state(fn (Invoice $record): string => Money::format((string) $record->gross_total).' €')
                    ->alignEnd()
                    ->sortable(),
                TextColumn::make('outstanding')
                    ->label(__('billing.invoice.field.outstanding'))
                    ->state(fn (Invoice $record): string => $record->isIssued() ? Money::format($record->outstandingAmount()).' €' : '—')
                    ->alignEnd(),
            ])
            ->defaultSort('id', 'desc')
            ->filters([
                SelectFilter::make('state')
                    ->label(__('billing.invoice.filter.state'))
                    ->options(collect(InvoiceState::cases())
                        ->mapWithKeys(fn (InvoiceState $s): array => [$s->value => $s->label()])
                        ->all()),
                SelectFilter::make('kind')
                    ->label(__('billing.invoice.filter.kind'))
                    ->options(collect(InvoiceKind::cases())
                        ->mapWithKeys(fn (InvoiceKind $k): array => [$k->value => $k->label()])
                        ->all()),
                Filter::make('overdue')
                    ->label(__('billing.invoice.filter.overdue'))
                    ->toggle()
                    ->query(fn (Builder $query): Builder => $query->overdue()),
            ])
            ->emptyStateHeading(__('billing.invoice.empty'))
            ->recordActions([
                ViewAction::make(),
                EditAction::make()->visible(fn (Invoice $record): bool => $record->isDraft()),
            ]);
    }
}
