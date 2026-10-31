<?php

declare(strict_types=1);

namespace App\Modules\Billing\Actions;

use App\Modules\Billing\Enums\InvoiceState;
use App\Modules\Billing\Models\Invoice;
use Illuminate\Support\Carbon;

/**
 * Die gestellten Rechnungen eines Zeitraums als CSV -- für den Steuerberater.
 *
 * Semikolon und UTF-8 mit BOM, weil das die eine Kombination ist, die ein
 * deutsches Excel ohne Nachfrage richtig öffnet. Beträge mit Komma, aus
 * demselben Grund. Kein DATEV-Format: Das kommt, wenn jemand es verlangt,
 * und dann mit dessen Beraternummer-Kopfzeile, nicht geraten.
 */
final class ExportInvoices
{
    public function csv(Carbon $from, Carbon $to): string
    {
        $rows = Invoice::query()
            ->whereNotIn('state', [InvoiceState::Draft->value])
            ->whereDate('issued_at', '>=', $from->toDateString())
            ->whereDate('issued_at', '<=', $to->toDateString())
            ->orderBy('issued_at')
            ->orderBy('number')
            ->get();

        $out = fopen('php://temp', 'r+');

        fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, [
            __('billing.export.number'), __('billing.export.kind'), __('billing.export.issued_at'),
            __('billing.export.due_at'), __('billing.export.customer'), __('billing.export.customer_number'),
            __('billing.export.work_order'), __('billing.export.net'), __('billing.export.tax'),
            __('billing.export.gross'), __('billing.export.tax_treatment'), __('billing.export.paid'),
            __('billing.export.state'), __('billing.export.corrects'),
        ], ';', '"', '');

        foreach ($rows as $invoice) {
            fputcsv($out, [
                $invoice->number,
                $invoice->kind->label(),
                $invoice->issued_at?->format('d.m.Y'),
                $invoice->due_at?->format('d.m.Y'),
                $invoice->customer_name,
                $invoice->customer_number,
                $invoice->work_order_number,
                self::amount((string) $invoice->net_total),
                self::amount((string) $invoice->tax_total),
                self::amount((string) $invoice->gross_total),
                $invoice->tax_treatment->label(),
                self::amount((string) $invoice->paid_amount),
                $invoice->state->label(),
                $invoice->corrects_invoice_id !== null ? $invoice->corrects?->number : null,
            ], ';', '"', '');
        }

        rewind($out);
        $csv = (string) stream_get_contents($out);
        fclose($out);

        return $csv;
    }

    private static function amount(string $value): string
    {
        return str_replace('.', ',', $value);
    }
}
