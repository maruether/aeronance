@php
    use App\Modules\Billing\Support\Money;

    $credit = $invoice->isCreditNote();
    $taxes = $invoice->tax_treatment->taxesApply();
    $title = $credit ? __('billing.document.title_credit_note') : __('billing.document.title_invoice');
    $orgLines = array_values(array_filter(array_map('trim', preg_split('/\R/', (string) ($organisation['address'] ?? '')) ?: [])));
    $recipientLines = array_values(array_filter(array_map('trim', preg_split('/\R/', (string) $invoice->customer_address) ?: [])));
    $anyVisibleLineDiscount = $invoice->lines->contains(fn ($l) => $l->discount_visible && $l->hasDiscount());
    $invoiceDiscount = $invoice->discountAmount();
    $showInvoiceDiscount = ! Money::isZero($invoiceDiscount) && $invoice->discount_visible;
    $taxRates = $invoice->lines->pluck('tax_rate')->map(fn ($r) => rtrim(rtrim(number_format((float) $r, 2, '.', ''), '0'), '.'))->unique()->values();
    $sender = implode(' · ', array_filter([$organisation['name'] ?? '', ...$orgLines]));
@endphp
<!doctype html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <title>{{ $title }} {{ $invoice->number ?? __('billing.document.draft') }}</title>
    <style>
        @page { size: A4; margin: 18mm 18mm 22mm 20mm; }
        body { font-family: "DejaVu Sans", Arial, sans-serif; font-size: 9.5pt; color: #000; margin: 0; }
        table { border-collapse: collapse; }
        .head { width: 100%; margin-bottom: 8mm; }
        .head td { vertical-align: top; }
        .org { font-size: 13pt; font-weight: 700; }
        .org-sub { font-size: 8pt; color: #333; }
        .sender { font-size: 7pt; color: #555; border-bottom: 0.2mm solid #999; padding-bottom: 1mm; margin-bottom: 3mm; }
        .recipient { font-size: 10pt; line-height: 1.4; min-height: 24mm; }
        .meta { font-size: 8.5pt; }
        .meta td { padding: 0.6mm 2mm 0.6mm 0; }
        .meta td.k { color: #444; white-space: nowrap; }
        .doc-title { font-size: 15pt; font-weight: 700; margin: 6mm 0 2mm 0; }
        .intro { margin: 0 0 4mm 0; white-space: pre-line; }
        .banner { border: 1mm solid #000; padding: 2mm 3mm; margin: 3mm 0 4mm 0; font-weight: 700; font-size: 11pt; text-align: center; letter-spacing: 1px; }
        table.lines { width: 100%; margin-top: 2mm; }
        table.lines th { text-align: left; font-size: 8pt; text-transform: uppercase; border-bottom: 0.4mm solid #000; padding: 1.5mm 1.5mm; }
        table.lines td { padding: 1.6mm 1.5mm; border-bottom: 0.2mm solid #bbb; vertical-align: top; }
        table.lines th.n, table.lines td.n { text-align: right; white-space: nowrap; }
        table.lines td.pos { color: #555; width: 8mm; }
        .src { font-size: 7.5pt; color: #666; }
        table.totals { margin-top: 3mm; margin-left: auto; width: 70mm; }
        table.totals td { padding: 1mm 1.5mm; }
        table.totals td.n { text-align: right; white-space: nowrap; }
        table.totals tr.gross td { border-top: 0.4mm solid #000; font-weight: 700; font-size: 10.5pt; padding-top: 2mm; }
        .notice { margin-top: 5mm; font-size: 9pt; }
        .terms { margin-top: 4mm; }
        .note { margin-top: 4mm; white-space: pre-line; }
        .footer { margin-top: 12mm; border-top: 0.2mm solid #999; padding-top: 2mm; font-size: 7pt; color: #444; width: 100%; }
        .footer td { vertical-align: top; width: 33%; padding-right: 3mm; }
        .no-print { margin-bottom: 4mm; }
        @media print { .no-print { display: none; } }
    </style>
</head>
<body>

@unless ($forPdf)
    <div class="no-print"><button type="button" data-print>Drucken</button><script src="/js/print-button.js"></script></div>
@endunless

@if ($invoice->isDraft())
    <div class="banner">{{ __('billing.invoice.draft_banner') }}</div>
@elseif ($invoice->correctedBy !== null)
    <div class="banner">{{ __('billing.document.cancelled', ['number' => $invoice->correctedBy->number, 'date' => $invoice->correctedBy->issued_at?->format('d.m.Y') ?? '—']) }}</div>
@endif

<table class="head">
    <tr>
        <td style="width: 58%;">
            <div class="org">{{ $organisation['name'] ?? '' }}</div>
            @foreach ($orgLines as $line)
                <div class="org-sub">{{ $line }}</div>
            @endforeach
        </td>
        <td style="width: 42%;">
            <table class="meta">
                <tr><td class="k">{{ __('billing.document.number') }}</td><td><b>{{ $invoice->number ?? '—' }}</b></td></tr>
                <tr><td class="k">{{ __('billing.document.date') }}</td><td>{{ $invoice->issued_at?->format('d.m.Y') ?? now()->format('d.m.Y') }}</td></tr>
                @if ($invoice->service_from !== null || $invoice->service_to !== null)
                    @if ($invoice->service_from !== null && $invoice->service_to !== null && ! $invoice->service_from->isSameDay($invoice->service_to))
                        <tr><td class="k">{{ __('billing.document.service_period') }}</td><td>{{ $invoice->service_from->format('d.m.Y') }} – {{ $invoice->service_to->format('d.m.Y') }}</td></tr>
                    @else
                        <tr><td class="k">{{ __('billing.document.service_date') }}</td><td>{{ ($invoice->service_to ?? $invoice->service_from)?->format('d.m.Y') }}</td></tr>
                    @endif
                @endif
                @if ($invoice->customer_number)
                    <tr><td class="k">{{ __('billing.document.customer_number') }}</td><td>{{ $invoice->customer_number }}</td></tr>
                @endif
                @if ($invoice->work_order_number)
                    <tr><td class="k">{{ __('billing.document.work_order') }}</td><td>{{ $invoice->work_order_number }}</td></tr>
                @endif
                @if ($invoice->aircraft_registration)
                    <tr><td class="k">{{ __('billing.document.aircraft') }}</td><td>{{ $invoice->aircraft_registration }}</td></tr>
                @endif
                @if ($invoice->buyer_reference)
                    <tr><td class="k">{{ __('billing.document.buyer_reference') }}</td><td>{{ $invoice->buyer_reference }}</td></tr>
                @endif
                @if ($invoice->customer_vat_id)
                    <tr><td class="k">{{ __('billing.document.vat_id') }}</td><td>{{ $invoice->customer_vat_id }}</td></tr>
                @endif
            </table>
        </td>
    </tr>
</table>

<div class="sender">{{ $sender }}</div>
<div class="recipient">
    <b>{{ $invoice->customer_name }}</b><br>
    @foreach ($recipientLines as $line)
        {{ $line }}<br>
    @endforeach
    @if (strtoupper((string) $invoice->customer_country) !== 'DE')
        {{ strtoupper((string) $invoice->customer_country) }}
    @endif
</div>

<div class="doc-title">{{ $title }} {{ $invoice->number ?? '' }}</div>

@if ($credit && $invoice->corrects !== null)
    <p class="intro">{{ __('billing.document.corrects', ['number' => $invoice->corrects->number, 'date' => $invoice->corrects->issued_at?->format('d.m.Y') ?? '—']) }}</p>
@endif

@if (filled($invoice->intro))
    <p class="intro">{{ $invoice->intro }}</p>
@endif

<table class="lines">
    <thead>
        <tr>
            <th>{{ __('billing.document.pos') }}</th>
            <th>{{ __('billing.document.description') }}</th>
            <th class="n">{{ __('billing.document.quantity') }}</th>
            @if ($anyVisibleLineDiscount)
                <th class="n">{{ __('billing.document.list_price') }}</th>
                <th class="n">{{ __('billing.document.discount') }}</th>
            @endif
            <th class="n">{{ __('billing.document.unit_price') }}</th>
            <th class="n">{{ __('billing.document.amount') }}</th>
        </tr>
    </thead>
    <tbody>
        @foreach ($invoice->lines as $line)
            @php $showLineDiscount = $line->discount_visible && $line->hasDiscount(); @endphp
            <tr>
                <td class="pos">{{ $line->position }}</td>
                <td>
                    {{ $line->description }}
                    @if ($line->source_reference)
                        <div class="src">{{ $line->source_reference }}</div>
                    @endif
                </td>
                <td class="n">{{ rtrim(rtrim(number_format((float) $line->quantity, 3, ',', '.'), '0'), ',') }} {{ $line->unit }}</td>
                @if ($anyVisibleLineDiscount)
                    <td class="n">{{ $showLineDiscount ? Money::format((string) $line->list_unit_price) : '' }}</td>
                    <td class="n">{{ $showLineDiscount ? rtrim(rtrim(number_format((float) $line->discount_percent, 2, ',', '.'), '0'), ',').' %' : '' }}</td>
                @endif
                <td class="n">{{ Money::format((string) $line->unit_price) }}</td>
                <td class="n">{{ Money::format((string) $line->net_amount) }}</td>
            </tr>
        @endforeach
    </tbody>
</table>

<table class="totals">
    @if ($showInvoiceDiscount)
        <tr><td>{{ __('billing.document.subtotal') }}</td><td class="n">{{ Money::format($invoice->subtotal()) }} €</td></tr>
        <tr><td>{{ __('billing.document.total_discount', ['percent' => rtrim(rtrim(number_format((float) $invoice->discount_percent, 2, ',', '.'), '0'), ',')]) }}</td><td class="n">− {{ Money::format($invoiceDiscount) }} €</td></tr>
    @endif
    <tr><td>{{ __('billing.document.net') }}</td><td class="n">{{ Money::format((string) $invoice->net_total) }} €</td></tr>
    @if ($taxes)
        <tr><td>{{ __('billing.document.tax', ['rate' => $taxRates->count() === 1 ? str_replace('.', ',', $taxRates->first()) : $taxRates->map(fn ($r) => str_replace('.', ',', $r))->implode('/')]) }}</td><td class="n">{{ Money::format((string) $invoice->tax_total) }} €</td></tr>
    @endif
    <tr class="gross"><td>{{ $credit ? __('billing.document.gross_credit') : __('billing.document.gross') }}</td><td class="n">{{ Money::format((string) $invoice->gross_total) }} €</td></tr>
    @if (! Money::isZero((string) $invoice->paid_amount) && ! $credit)
        <tr><td>{{ __('billing.document.paid') }}</td><td class="n">− {{ Money::format((string) $invoice->paid_amount) }} €</td></tr>
        <tr><td>{{ __('billing.document.payable') }}</td><td class="n">{{ Money::format($invoice->outstandingAmount()) }} €</td></tr>
    @endif
</table>

@if ($invoice->tax_treatment->notice() !== null)
    <p class="notice">{{ $invoice->tax_treatment->notice() }}</p>
@endif

<div class="terms">
    @if ($credit)
        {{ __('billing.document.credit_note_terms') }}
    @elseif ($invoice->due_at !== null)
        {{ __('billing.document.payment_terms', ['date' => $invoice->due_at->format('d.m.Y')]) }}
        @if (filled($organisation['iban'] ?? ''))
            {{ __('billing.document.payment_terms_iban') }}
        @endif
    @endif
</div>

@if (filled($invoice->note))
    <p class="note">{{ $invoice->note }}</p>
@endif

@if (filled($footer))
    <p class="note">{{ $footer }}</p>
@endif

<table class="footer">
    <tr>
        <td>
            <b>{{ $organisation['name'] ?? '' }}</b><br>
            @foreach ($orgLines as $line){{ $line }}<br>@endforeach
            @if (filled($organisation['phone'] ?? '')){{ $organisation['phone'] }}<br>@endif
            @if (filled($organisation['email'] ?? '')){{ $organisation['email'] }}@endif
        </td>
        <td>
            @if (filled($organisation['register'] ?? '')){{ __('billing.document.register') }}: {{ $organisation['register'] }}<br>@endif
            @if (filled($organisation['representative'] ?? '')){{ __('billing.document.representative') }}: {{ $organisation['representative'] }}<br>@endif
            @if (filled($organisation['vat_id'] ?? '')){{ __('billing.document.vat_id') }}: {{ $organisation['vat_id'] }}<br>@endif
            @if (filled($organisation['tax_number'] ?? '')){{ __('billing.document.tax_number') }}: {{ $organisation['tax_number'] }}@endif
        </td>
        <td>
            @if (filled($organisation['iban'] ?? ''))
                <b>{{ __('billing.document.bank') }}</b><br>
                @if (filled($organisation['bank_name'] ?? '')){{ $organisation['bank_name'] }}<br>@endif
                IBAN {{ $organisation['iban'] }}<br>
                @if (filled($organisation['bic'] ?? ''))BIC {{ $organisation['bic'] }}@endif
            @endif
        </td>
    </tr>
</table>

</body>
</html>
