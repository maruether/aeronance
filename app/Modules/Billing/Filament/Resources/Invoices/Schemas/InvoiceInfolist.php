<?php

declare(strict_types=1);

namespace App\Modules\Billing\Filament\Resources\Invoices\Schemas;

use App\Modules\Billing\Enums\InvoiceState;
use App\Modules\Billing\Enums\TaxTreatment;
use App\Modules\Billing\Models\Invoice;
use App\Modules\Billing\Models\InvoiceLine;
use App\Modules\Billing\Support\Money;
use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

/**
 * Die gestellte Rechnung -- lesend, mit allem, was auf dem Beleg steht, und
 * dem, was NICHT darauf steht: Listenpreis und Rabatt sieht man hier auch
 * dann, wenn das Papier sie verbirgt.
 */
final class InvoiceInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(__('billing.invoice.section.details'))
                ->columns(4)
                ->schema([
                    TextEntry::make('number')
                        ->label(__('billing.invoice.field.number'))
                        ->placeholder('—')
                        ->weight('bold'),
                    TextEntry::make('kind')
                        ->label(__('billing.invoice.field.kind'))
                        ->formatStateUsing(fn ($state): string => $state->label()),
                    TextEntry::make('state')
                        ->label(__('billing.invoice.field.state'))
                        ->badge()
                        ->formatStateUsing(fn (InvoiceState $state): string => $state->label())
                        ->color(fn (InvoiceState $state): string => $state->color()),
                    TextEntry::make('issued_by_name')
                        ->label(__('billing.invoice.field.issued_by'))
                        ->placeholder('—'),
                    TextEntry::make('customer_name')
                        ->label(__('billing.invoice.field.customer_name'))
                        ->state(fn (Invoice $record): string => trim($record->customer_name."\n".($record->customer_address ?? '')))
                        ->columnSpan(2),
                    TextEntry::make('issued_at')
                        ->label(__('billing.invoice.field.issued_at'))
                        ->date('d.m.Y')
                        ->placeholder('—'),
                    TextEntry::make('due_at')
                        ->label(__('billing.invoice.field.due_at'))
                        ->date('d.m.Y')
                        ->placeholder('—')
                        ->color(fn (Invoice $record): ?string => $record->isOverdue() ? 'danger' : null),
                    TextEntry::make('work_order_number')
                        ->label(__('billing.invoice.field.work_order_number'))
                        ->placeholder('—'),
                    TextEntry::make('aircraft_registration')
                        ->label(__('billing.invoice.field.aircraft_registration'))
                        ->placeholder('—'),
                    TextEntry::make('service')
                        ->label(__('billing.invoice.field.service_from'))
                        ->state(fn (Invoice $record): string => implode(' – ', array_filter([
                            $record->service_from?->format('d.m.Y'),
                            $record->service_to?->format('d.m.Y'),
                        ])) ?: '—'),
                    TextEntry::make('tax_treatment')
                        ->label(__('billing.invoice.field.tax_treatment'))
                        ->formatStateUsing(fn (TaxTreatment $state): string => $state->label()),
                    TextEntry::make('corrects.number')
                        ->label(__('billing.invoice.field.corrects'))
                        ->visible(fn (Invoice $record): bool => $record->corrects_invoice_id !== null),
                    TextEntry::make('correctedBy.number')
                        ->label(__('billing.invoice.field.corrected_by'))
                        ->visible(fn (Invoice $record): bool => $record->correctedBy !== null),
                    TextEntry::make('cancellation_reason')
                        ->label(__('billing.invoice.field.cancellation_reason'))
                        ->visible(fn (Invoice $record): bool => $record->cancellation_reason !== null)
                        ->columnSpan(2),
                ]),

            Section::make(__('billing.invoice.section.lines'))
                ->schema([
                    RepeatableEntry::make('lines')
                        ->hiddenLabel()
                        ->columns(12)
                        ->schema([
                            TextEntry::make('position')->hiddenLabel()->columnSpan(1),
                            TextEntry::make('description')
                                ->hiddenLabel()
                                ->columnSpan(5)
                                ->helperText(fn (InvoiceLine $record): ?string => $record->source_reference),
                            TextEntry::make('quantity')
                                ->hiddenLabel()
                                ->state(fn (InvoiceLine $record): string => rtrim(rtrim(number_format((float) $record->quantity, 3, ',', '.'), '0'), ',').' '.$record->unit)
                                ->columnSpan(2),
                            TextEntry::make('unit_price')
                                ->hiddenLabel()
                                ->state(fn (InvoiceLine $record): string => $record->hasDiscount()
                                    ? sprintf('%s € (%s € − %s %%%s)', Money::format((string) $record->unit_price), Money::format((string) $record->list_unit_price), rtrim(rtrim((string) $record->discount_percent, '0'), '.'), $record->discount_visible ? '' : ', '.__('billing.customer.option.hidden'))
                                    : Money::format((string) $record->unit_price).' €')
                                ->columnSpan(3),
                            TextEntry::make('net_amount')
                                ->hiddenLabel()
                                ->state(fn (InvoiceLine $record): string => Money::format((string) $record->net_amount).' €')
                                ->weight('bold')
                                ->alignEnd()
                                ->columnSpan(1),
                        ]),
                ]),

            Section::make(__('billing.invoice.section.totals'))
                ->columns(4)
                ->schema([
                    TextEntry::make('net_total')
                        ->label(__('billing.invoice.field.net_total'))
                        ->state(fn (Invoice $record): string => Money::format((string) $record->net_total).' €'),
                    TextEntry::make('tax_total')
                        ->label(__('billing.invoice.field.tax_total'))
                        ->state(fn (Invoice $record): string => Money::format((string) $record->tax_total).' €'),
                    TextEntry::make('gross_total')
                        ->label(__('billing.invoice.field.gross_total'))
                        ->state(fn (Invoice $record): string => Money::format((string) $record->gross_total).' €')
                        ->weight('bold'),
                    TextEntry::make('outstanding')
                        ->label(__('billing.invoice.field.outstanding'))
                        ->state(fn (Invoice $record): string => Money::format($record->outstandingAmount()).' €')
                        ->color(fn (Invoice $record): ?string => $record->isOverdue() ? 'danger' : null),
                    IconEntry::make('discount_visible')
                        ->label(__('billing.invoice.field.discount_visible'))
                        ->boolean()
                        ->visible(fn (Invoice $record): bool => $record->discount_percent !== null),
                    TextEntry::make('discount_percent')
                        ->label(__('billing.invoice.field.discount_percent'))
                        ->visible(fn (Invoice $record): bool => $record->discount_percent !== null),
                    TextEntry::make('sent_at')
                        ->label(__('billing.invoice.field.sent_at'))
                        ->dateTime('d.m.Y H:i')
                        ->placeholder('—'),
                    TextEntry::make('paid_at')
                        ->label(__('billing.invoice.field.paid_at'))
                        ->date('d.m.Y')
                        ->placeholder('—'),
                ]),

            Section::make(__('billing.invoice.section.payments'))
                ->visible(fn (Invoice $record): bool => $record->payments->isNotEmpty())
                ->schema([
                    RepeatableEntry::make('payments')
                        ->hiddenLabel()
                        ->columns(4)
                        ->schema([
                            TextEntry::make('paid_on')->label(__('billing.payment.field.paid_on'))->date('d.m.Y'),
                            TextEntry::make('amount')
                                ->label(__('billing.payment.field.amount'))
                                ->state(fn ($record): string => Money::format((string) $record->amount).' €'),
                            TextEntry::make('method')->label(__('billing.payment.field.method'))->placeholder('—'),
                            TextEntry::make('reference')->label(__('billing.payment.field.reference'))->placeholder('—'),
                        ]),
                ]),

            Section::make(__('billing.invoice.section.texts'))
                ->collapsed()
                ->schema([
                    TextEntry::make('intro')->label(__('billing.invoice.field.intro'))->placeholder('—'),
                    TextEntry::make('note')->label(__('billing.invoice.field.note'))->placeholder('—'),
                    TextEntry::make('internal_note')->label(__('billing.invoice.field.internal_note'))->placeholder('—'),
                ]),
        ]);
    }
}
