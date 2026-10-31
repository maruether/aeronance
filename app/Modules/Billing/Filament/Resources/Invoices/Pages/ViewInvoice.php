<?php

declare(strict_types=1);

namespace App\Modules\Billing\Filament\Resources\Invoices\Pages;

use App\Core\Mail\Postman;
use App\Modules\Billing\Actions\CancelInvoice;
use App\Modules\Billing\Actions\RecordPayment;
use App\Modules\Billing\Actions\SendInvoice;
use App\Modules\Billing\Enums\InvoiceState;
use App\Modules\Billing\Filament\Resources\Invoices\InvoiceResource;
use App\Modules\Billing\Models\Invoice;
use App\Modules\Billing\Permissions;
use App\Modules\Billing\Support\Money;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;
use Throwable;

/**
 * Die gestellte Rechnung und alles, was an ihr noch geschehen darf.
 */
final class ViewInvoice extends ViewRecord
{
    protected static string $resource = InvoiceResource::class;

    public function getTitle(): string|Htmlable
    {
        /** @var Invoice $invoice */
        $invoice = $this->getRecord();

        return $invoice->kind->label().' '.$invoice->label();
    }

    public function getSubheading(): string|Htmlable|null
    {
        /** @var Invoice $invoice */
        $invoice = $this->getRecord();

        if ($invoice->correctedBy !== null) {
            return __('billing.invoice.cancelled_banner', [
                'number' => $invoice->correctedBy->number,
                'date' => $invoice->correctedBy->issued_at?->format('d.m.Y') ?? '—',
            ]);
        }

        return $invoice->isFrozen() ? __('billing.invoice.frozen_hint') : null;
    }

    protected function getHeaderActions(): array
    {
        /** @var Invoice $invoice */
        $invoice = $this->getRecord();

        return [
            EditAction::make()->visible(fn (): bool => $invoice->isDraft()),

            Action::make('print')
                ->label(__('billing.invoice.action.print'))
                ->icon(Heroicon::OutlinedPrinter)
                ->color('gray')
                ->url(fn (): string => route('billing.invoice', ['invoice' => $invoice]), shouldOpenInNewTab: true),

            Action::make('pdf')
                ->label(__('billing.invoice.action.pdf'))
                ->icon(Heroicon::OutlinedDocumentArrowDown)
                ->color('gray')
                ->visible(fn (): bool => $invoice->isFrozen())
                ->url(fn (): string => route('billing.invoice.pdf', ['invoice' => $invoice]), shouldOpenInNewTab: true),

            Action::make('xml')
                ->label(__('billing.invoice.action.xml'))
                ->icon(Heroicon::OutlinedCodeBracket)
                ->color('gray')
                ->visible(fn (): bool => $invoice->isFrozen())
                ->url(fn (): string => route('billing.invoice.xml', ['invoice' => $invoice])),

            $this->sendAction($invoice),
            $this->paymentAction($invoice),
            $this->cancelAction($invoice),
        ];
    }

    private function sendAction(Invoice $invoice): Action
    {
        return Action::make('send')
            ->label(__('billing.invoice.action.send'))
            ->icon(Heroicon::OutlinedEnvelope)
            ->visible(fn (): bool => $invoice->isFrozen()
                && Postman::canSend()
                && filled($invoice->customer?->email)
                && (auth()->user()?->canAny([Permissions::MANAGE, Permissions::ISSUE]) ?? false))
            ->modalHeading(__('billing.invoice.action.send_heading'))
            ->modalDescription(__('billing.invoice.action.send_description', ['email' => (string) $invoice->customer?->email]))
            ->schema([
                Toggle::make('with_xml')
                    ->label(__('billing.invoice.action.send_with_xml'))
                    ->default(false),
            ])
            ->action(function (array $data) use ($invoice): void {
                try {
                    app(SendInvoice::class)->handle($invoice, auth()->user(), (bool) ($data['with_xml'] ?? false));
                } catch (Throwable $e) {
                    Notification::make()->danger()->title(__('billing.invoice.action.failed'))->body($e->getMessage())->persistent()->send();

                    return;
                }

                Notification::make()->success()->title(__('billing.invoice.action.sent', ['email' => (string) $invoice->customer?->email]))->send();
                $this->refreshFormData(['sent_at']);
            });
    }

    private function paymentAction(Invoice $invoice): Action
    {
        return Action::make('payment')
            ->label(__('billing.invoice.action.payment'))
            ->icon(Heroicon::OutlinedBanknotes)
            ->color('success')
            ->visible(fn (): bool => in_array($invoice->state, [InvoiceState::Issued, InvoiceState::Paid], true)
                && (auth()->user()?->can(Permissions::PAYMENTS) ?? false))
            ->modalHeading(__('billing.invoice.action.payment_heading'))
            ->schema([
                TextInput::make('amount')
                    ->label(__('billing.invoice.action.payment_amount'))
                    ->helperText(__('billing.invoice.action.payment_amount_help', ['amount' => Money::format($invoice->outstandingAmount())]))
                    ->default($invoice->outstandingAmount())
                    ->numeric()
                    ->required()
                    ->suffix('€'),
                DatePicker::make('paid_on')
                    ->label(__('billing.invoice.action.payment_date'))
                    ->default(now()->toDateString())
                    ->maxDate(now())
                    ->required(),
                Select::make('method')
                    ->label(__('billing.invoice.action.payment_method'))
                    ->options([
                        'transfer' => __('billing.payment.method.transfer'),
                        'cash' => __('billing.payment.method.cash'),
                        'card' => __('billing.payment.method.card'),
                        'other' => __('billing.payment.method.other'),
                    ])
                    ->default('transfer')
                    ->native(false),
                TextInput::make('reference')
                    ->label(__('billing.invoice.action.payment_reference'))
                    ->maxLength(128),
            ])
            ->action(function (array $data) use ($invoice): void {
                try {
                    app(RecordPayment::class)->handle(
                        $invoice,
                        (string) $data['amount'],
                        (string) $data['paid_on'],
                        auth()->user(),
                        $data['method'] ?? null,
                        $data['reference'] ?? null,
                    );
                } catch (Throwable $e) {
                    Notification::make()->danger()->title(__('billing.invoice.action.failed'))->body($e->getMessage())->persistent()->send();

                    return;
                }

                Notification::make()->success()->title(__('billing.invoice.action.payment_recorded'))->send();
                $this->redirect(InvoiceResource::getUrl('view', ['record' => $invoice]));
            });
    }

    private function cancelAction(Invoice $invoice): Action
    {
        return Action::make('cancel')
            ->label(__('billing.invoice.action.cancel'))
            ->icon(Heroicon::OutlinedXCircle)
            ->color('danger')
            ->visible(fn (): bool => in_array($invoice->state, [InvoiceState::Issued, InvoiceState::Paid], true)
                && ! $invoice->isCreditNote()
                && (auth()->user()?->can(Permissions::ISSUE) ?? false))
            ->requiresConfirmation()
            ->modalHeading(__('billing.invoice.action.cancel_heading'))
            ->modalDescription(__('billing.invoice.action.cancel_description'))
            ->schema([
                Textarea::make('reason')
                    ->label(__('billing.invoice.action.cancel_reason'))
                    ->required()
                    ->rows(3),
            ])
            ->action(function (array $data) use ($invoice): void {
                try {
                    $creditNote = app(CancelInvoice::class)->handle($invoice, auth()->user(), (string) $data['reason']);
                } catch (Throwable $e) {
                    Notification::make()->danger()->title(__('billing.invoice.action.failed'))->body($e->getMessage())->persistent()->send();

                    return;
                }

                Notification::make()->success()->title(__('billing.invoice.action.cancelled', ['number' => $creditNote->number]))->send();
                $this->redirect(InvoiceResource::getUrl('view', ['record' => $creditNote]));
            });
    }
}
