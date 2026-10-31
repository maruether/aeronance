<?php

declare(strict_types=1);

namespace App\Modules\Billing\Filament\Resources\Invoices\Pages;

use App\Modules\Billing\Actions\BuildInvoiceFromWorkOrder;
use App\Modules\Billing\Actions\ExportInvoices;
use App\Modules\Billing\Enums\InvoiceState;
use App\Modules\Billing\Filament\Resources\Invoices\InvoiceResource;
use App\Modules\Billing\Models\Customer;
use App\Modules\Billing\Models\Invoice;
use App\Modules\Billing\Permissions;
use App\Modules\TaskCards\Models\WorkOrder;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

final class ListInvoices extends ListRecords
{
    protected static string $resource = InvoiceResource::class;

    protected function getHeaderActions(): array
    {
        return [
            $this->fromVisitAction(),
            CreateAction::make(),
            $this->exportAction(),
        ];
    }

    /**
     * Rechnung aus einem Vorgang -- der Normalweg.
     *
     * Angeboten werden nur Vorgänge, die fertig sind (geschlossen oder
     * freigegeben) und noch keine gestellte Rechnung haben. Der Kunde wird
     * vorgeschlagen, wenn das Luftfahrzeug einen Halter hat, der an einem
     * Kunden hängt.
     */
    private function fromVisitAction(): Action
    {
        return Action::make('fromVisit')
            ->label(__('billing.invoice.action.from_visit'))
            ->icon(Heroicon::OutlinedClipboardDocumentCheck)
            ->color('primary')
            ->visible(fn (): bool => auth()->user()?->can(Permissions::MANAGE) ?? false)
            ->modalHeading(__('billing.invoice.action.from_visit_heading'))
            ->modalDescription(__('billing.invoice.action.from_visit_description'))
            ->schema([
                Select::make('work_order_id')
                    ->label(__('billing.invoice.action.visit'))
                    ->helperText(__('billing.invoice.action.visit_help'))
                    ->options(fn (): array => self::invoiceableVisits())
                    ->searchable()
                    ->required()
                    ->native(false)
                    ->live()
                    ->afterStateUpdated(function (Set $set, ?string $state): void {
                        $order = $state !== null ? WorkOrder::query()->with('aircraft')->find($state) : null;
                        $holderId = $order?->aircraft?->holder_id;

                        if ($holderId !== null) {
                            $customer = Customer::query()->active()->where('holder_id', $holderId)->first();
                            if ($customer !== null) {
                                $set('customer_id', (string) $customer->getKey());
                            }
                        }
                    }),
                Select::make('customer_id')
                    ->label(__('billing.invoice.field.customer'))
                    ->options(fn (): array => Customer::query()->active()->orderBy('name')->get()
                        ->mapWithKeys(fn (Customer $c): array => [$c->getKey() => $c->label()])
                        ->all())
                    ->searchable()
                    ->required()
                    ->native(false),
            ])
            ->action(function (array $data): void {
                try {
                    $invoice = app(BuildInvoiceFromWorkOrder::class)->handle(
                        WorkOrder::query()->findOrFail($data['work_order_id']),
                        Customer::query()->findOrFail($data['customer_id']),
                        auth()->user(),
                    );
                } catch (Throwable $e) {
                    Notification::make()->danger()->title(__('billing.invoice.action.failed'))->body($e->getMessage())->persistent()->send();

                    return;
                }

                $this->redirect(InvoiceResource::getUrl('edit', ['record' => $invoice]));
            });
    }

    /** @return array<int, string> */
    private static function invoiceableVisits(): array
    {
        $invoiced = Invoice::query()
            ->whereIn('state', [InvoiceState::Issued->value, InvoiceState::Paid->value])
            ->where('kind', 'invoice')
            ->whereNotNull('work_order_id')
            ->pluck('work_order_id');

        return WorkOrder::query()
            ->with('aircraft')
            ->where(fn ($q) => $q->whereNotNull('released_at')->orWhere('state', '!=', 'open'))
            ->whereNotIn('id', $invoiced)
            ->orderByDesc('id')
            ->limit(200)
            ->get()
            ->mapWithKeys(fn (WorkOrder $order): array => [
                $order->getKey() => $order->label().' ('.($order->aircraft?->registration ?? '—').')',
            ])
            ->all();
    }

    private function exportAction(): Action
    {
        return Action::make('export')
            ->label(__('billing.invoice.action.export'))
            ->icon(Heroicon::OutlinedArrowDownTray)
            ->color('gray')
            ->modalHeading(__('billing.invoice.action.export_heading'))
            ->modalDescription(__('billing.invoice.action.export_description'))
            ->schema([
                DatePicker::make('from')
                    ->label(__('billing.invoice.action.export_from'))
                    ->default(now()->startOfMonth()->toDateString())
                    ->required(),
                DatePicker::make('to')
                    ->label(__('billing.invoice.action.export_to'))
                    ->default(now()->toDateString())
                    ->required(),
            ])
            ->action(function (array $data): StreamedResponse {
                $from = Carbon::parse($data['from']);
                $to = Carbon::parse($data['to']);
                $csv = app(ExportInvoices::class)->csv($from, $to);

                return response()->streamDownload(
                    static function () use ($csv): void {
                        echo $csv;
                    },
                    sprintf('rechnungen-%s-%s.csv', $from->format('Ymd'), $to->format('Ymd')),
                    ['Content-Type' => 'text/csv; charset=UTF-8'],
                );
            });
    }
}
