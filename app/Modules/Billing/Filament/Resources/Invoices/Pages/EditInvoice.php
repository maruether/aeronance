<?php

declare(strict_types=1);

namespace App\Modules\Billing\Filament\Resources\Invoices\Pages;

use App\Modules\Billing\Actions\InvoiceCalculator;
use App\Modules\Billing\Actions\IssueInvoice;
use App\Modules\Billing\Filament\Resources\Invoices\InvoiceResource;
use App\Modules\Billing\Models\Invoice;
use App\Modules\Billing\Models\InvoiceLine;
use App\Modules\Billing\Permissions;
use App\Modules\Billing\Support\Money;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Forms\Components\DatePicker;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Icons\Heroicon;
use Throwable;

/**
 * Der Entwurf. Speichern rechnet; „Stellen" friert ein.
 */
final class EditInvoice extends EditRecord
{
    protected static string $resource = InvoiceResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('preview')
                ->label(__('billing.invoice.action.preview'))
                ->icon(Heroicon::OutlinedEye)
                ->color('gray')
                ->url(fn (): string => route('billing.invoice', ['invoice' => $this->getRecord()]), shouldOpenInNewTab: true),

            Action::make('issue')
                ->label(__('billing.invoice.action.issue'))
                ->icon(Heroicon::OutlinedLockClosed)
                ->color('success')
                ->visible(fn (): bool => auth()->user()?->can(Permissions::ISSUE) ?? false)
                ->requiresConfirmation()
                ->modalHeading(__('billing.invoice.action.issue_heading'))
                ->modalDescription(__('billing.invoice.action.issue_description'))
                ->schema([
                    DatePicker::make('issued_at')
                        ->label(__('billing.invoice.action.issue_date'))
                        ->default(now()->toDateString())
                        ->maxDate(now())
                        ->required(),
                ])
                ->action(function (array $data): void {
                    // Erst speichern, was im Formular steht -- gestellt wird,
                    // was auf dem Bildschirm ist, nicht der letzte Speicherstand.
                    $this->save(shouldRedirect: false, shouldSendSavedNotification: false);

                    try {
                        $issued = app(IssueInvoice::class)->handle(
                            $this->getRecord()->fresh(['lines', 'customer']),
                            auth()->user(),
                            (string) $data['issued_at'],
                        );
                    } catch (Throwable $e) {
                        Notification::make()->danger()->title(__('billing.invoice.action.failed'))->body($e->getMessage())->persistent()->send();

                        return;
                    }

                    Notification::make()->success()->title(__('billing.invoice.action.issued', ['number' => $issued->number]))->send();
                    $this->redirect(InvoiceResource::getUrl('view', ['record' => $issued]));
                }),

            DeleteAction::make()->label(__('billing.invoice.action.delete_draft')),
        ];
    }

    /** @param  array<string, mixed>  $data */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        return self::normalise($data);
    }

    protected function afterSave(): void
    {
        self::clearPriceGapsWherePriced($this->getRecord());
        app(InvoiceCalculator::class)->recalculate($this->getRecord()->fresh());
    }

    /**
     * Leere Zahlenfelder sind null, nicht "" -- die Datenbank hält DECIMAL.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function normalise(array $data): array
    {
        foreach (['discount_percent', 'service_from', 'service_to', 'work_order_number', 'aircraft_registration', 'intro', 'note', 'internal_note'] as $field) {
            if (($data[$field] ?? '') === '') {
                $data[$field] = null;
            }
        }

        return $data;
    }

    /**
     * „Preis fehlt" verschwindet, sobald einer dasteht.
     *
     * Die Markierung setzt der Aufbau aus dem Vorgang; sie bleibt, bis
     * jemand einen Listenpreis eintraegt. Wer 0 eintraegt und das so meint,
     * braucht keine Markierung -- aber der haeufigere Fall ist der, der die
     * Zeile uebersehen hat, deshalb raeumt nur ein Betrag ungleich 0 sie weg.
     */
    public static function clearPriceGapsWherePriced(Invoice $invoice): void
    {
        $invoice->lines()->get()->each(function (InvoiceLine $line): void {
            if ($line->needs_price && ! Money::isZero(Money::of((string) $line->list_unit_price))) {
                $line->needs_price = false;
                $line->save();
            }
        });
    }
}
