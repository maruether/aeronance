<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <title>{{ __('warehouse.inventory.title') }} — {{ $asOf->format('d.m.Y') }}</title>
    <style>
        @page { size: A4 portrait; margin: 12mm 10mm 14mm; }
        * { box-sizing: border-box; }
        body { font: 9pt/1.35 "DejaVu Sans", Arial, sans-serif; margin: 0; color: #111; }

        h1 { font-size: 14pt; margin: 0 0 1mm; }
        h2 { font-size: 10.5pt; margin: 7mm 0 2mm; padding-bottom: 1mm;
             border-bottom: .4mm solid #000; page-break-after: avoid; }
        h2 .count { font-weight: normal; color: #666; font-size: 9pt; }
        .meta { font-size: 8pt; color: #555; margin-bottom: 2mm; }
        .meta strong { color: #111; }
        .lead { font-size: 8pt; color: #555; margin: 0 0 2mm; }

        table { width: 100%; border-collapse: collapse; }
        thead { display: table-header-group; }
        th { text-align: left; font-size: 7.5pt; text-transform: uppercase;
             letter-spacing: .2pt; color: #444; border-bottom: .3mm solid #666;
             padding: 1.2mm 1mm; }
        td { padding: 1.2mm 1mm; border-bottom: .15mm solid #e2e2e2; vertical-align: top; }
        tr { page-break-inside: avoid; }
        .num  { text-align: right; white-space: nowrap; }
        .sub  { font-size: 7.5pt; color: #555; }
        .warn { color: #b00; font-weight: 600; }
        .loc  { background: #f0f0f0; font-weight: bold; font-size: 8.5pt; }
        .none { color: #666; font-style: italic; padding: 2mm 0; }

        .foot { margin-top: 8mm; font-size: 8pt; display: flex; gap: 12mm; }
        .foot div { flex: 1; border-top: .3mm solid #000; padding-top: 1mm; color: #555; }

        @media screen {
            body { max-width: 190mm; margin: 10mm auto; }
            .noprint { background: #eef; padding: 3mm; margin-bottom: 4mm; font-size: 9pt; }
        }
        @media print { .noprint { display: none; } }
    </style>
</head>
<body>
@php
    $n = fn (float $v): string => rtrim(rtrim(number_format($v, 3, ',', '.'), '0'), ',');
    $stock = $sections['stock'];
    $shortfalls = $sections['shortfalls'];
    $expiry = $sections['expiry'];
    $blocked = $sections['blocked'];
    $missing = $sections['missingEvidence'];
    $journal = $sections['journal'];
@endphp

<div class="noprint">{{ __('warehouse.inventory.hint') }}</div>

<h1>{{ __('warehouse.inventory.title') }}</h1>
<div class="meta">
    <strong>{{ $club }}</strong>
    &nbsp;·&nbsp; <strong>{{ __('warehouse.inventory.as_of', ['date' => $asOf->format('d.m.Y')]) }}</strong>
    &nbsp;·&nbsp; {{ __('warehouse.inventory.created', ['date' => now()->timezone(config('aeronance.organisation.timezone'))->format('d.m.Y H:i')]) }}
    @if ($location) &nbsp;·&nbsp; {{ $location->name }} @endif
</div>

{{-- 1 --}}
<h2>{{ __('warehouse.inventory.section.stock') }} <span class="count">({{ count($stock) }})</span></h2>
<p class="lead">{{ __('warehouse.inventory.stock_hint') }}</p>

<table>
    <thead>
        <tr>
            <th style="width:34%">{{ __('warehouse.counting.part') }}</th>
            <th style="width:12%">{{ __('warehouse.part_type.field.classification') }}</th>
            <th style="width:20%">{{ __('warehouse.part_type.field.compartment') }}</th>
            <th style="width:11%" class="num">{{ __('warehouse.inventory.available') }}</th>
            <th style="width:11%" class="num">{{ __('warehouse.inventory.blocked') }}</th>
            <th style="width:12%" class="num">{{ __('warehouse.inventory.total') }}</th>
        </tr>
    </thead>
    <tbody>
    @php $currentLocation = null; @endphp
    @forelse ($stock as $row)
        @php
            $part = $row['part'];
            $locationName = $part->storageCompartment?->location?->name ?? __('warehouse.counting.unassigned');
        @endphp
        @if ($locationName !== $currentLocation)
            @php $currentLocation = $locationName; @endphp
            <tr><td class="loc" colspan="6">{{ $locationName }}</td></tr>
        @endif
        <tr>
            <td>
                {{ $part->name }}
                @if ($part->ipc_part_number)<div class="sub">IPC {{ $part->ipc_part_number }}</div>@endif
            </td>
            <td class="sub">{{ $part->classification->label() }}</td>
            <td class="sub">{{ $part->storageCompartment?->name ?? '—' }}</td>
            <td class="num">{{ $n($row['available']) }}</td>
            <td class="num {{ $row['blocked'] > 0 ? 'warn' : '' }}">
                {{ $row['blocked'] > 0 ? $n($row['blocked']) : '—' }}
            </td>
            <td class="num"><strong>{{ $n($row['total']) }}</strong> {{ $part->unit_of_measure }}</td>
        </tr>
        {{-- Losgeführte Teile aufgeschlüsselt, sonst ist die Zahl nicht prüfbar --}}
        @foreach ($row['lots'] as $lotRow)
            @php $lot = $lotRow['lot']; @endphp
            <tr>
                <td class="sub" style="padding-left:5mm">
                    ↳ {{ $lot->label() }}
                    @if ($lot->document_reference) · {{ $lot->document_reference }} @endif
                </td>
                <td class="sub">
                    @if ($lot->state->value !== 'serviceable')
                        <span class="warn">{{ $lot->state->label() }}</span>
                    @endif
                </td>
                <td class="sub">
                    @if ($lot->expires_at)
                        <span class="{{ $lot->expires_at->lt($asOf) ? 'warn' : '' }}">
                            {{ __('warehouse.inventory.until') }} {{ $lot->expires_at->format('d.m.Y') }}
                        </span>
                    @endif
                </td>
                <td colspan="2"></td>
                <td class="num sub">{{ $n($lotRow['quantity']) }}</td>
            </tr>
        @endforeach
    @empty
        <tr><td colspan="6" class="none">{{ __('warehouse.inventory.no_stock') }}</td></tr>
    @endforelse
    </tbody>
</table>

{{-- 2 --}}
<h2>{{ __('warehouse.inventory.section.shortfalls') }} <span class="count">({{ count($shortfalls) }})</span></h2>
@if (count($shortfalls) === 0)
    <p class="none">{{ __('warehouse.inventory.no_shortfalls') }}</p>
@else
<table>
    <thead><tr>
        <th style="width:40%">{{ __('warehouse.counting.part') }}</th>
        <th style="width:28%">{{ __('warehouse.part_type.field.supplier') }}</th>
        <th style="width:16%" class="num">{{ __('warehouse.inventory.available') }}</th>
        <th style="width:16%" class="num">{{ __('warehouse.inventory.missing') }}</th>
    </tr></thead>
    <tbody>
    @foreach ($shortfalls as $row)
        <tr>
            <td>{{ $row['part']->name }}</td>
            <td class="sub">
                {{ $row['part']->supplier?->name ?? '—' }}
                @if ($row['part']->order_code) · {{ $row['part']->order_code }} @endif
            </td>
            <td class="num">{{ $n($row['available']) }} / {{ $row['part']->minimum_stock }}</td>
            <td class="num warn">{{ $n($row['missing']) }} {{ $row['part']->unit_of_measure }}</td>
        </tr>
    @endforeach
    </tbody>
</table>
@endif

{{-- 3 --}}
<h2>{{ __('warehouse.inventory.section.expiry') }}
    <span class="count">({{ $expiry['expired']->count() }} / {{ $expiry['soon']->count() }})</span></h2>

@if ($expiry['expired']->isEmpty() && $expiry['soon']->isEmpty())
    <p class="none">{{ __('warehouse.inventory.no_expiry') }}</p>
@else
<table>
    <thead><tr>
        <th style="width:38%">{{ __('warehouse.counting.part') }}</th>
        <th style="width:24%">{{ __('warehouse.lot.singular') }}</th>
        <th style="width:20%">{{ __('warehouse.lot.field.expires_at') }}</th>
        <th style="width:18%" class="num">{{ __('warehouse.lot.field.remaining') }}</th>
    </tr></thead>
    <tbody>
    @foreach (['expired', 'soon'] as $group)
        @if ($expiry[$group]->isNotEmpty())
            <tr><td class="loc" colspan="4">{{ __('warehouse.inventory.expiry_'.$group) }}</td></tr>
            @foreach ($expiry[$group] as $lot)
                <tr>
                    <td>{{ $lot->partType?->name }}</td>
                    <td class="sub">{{ $lot->label() }}</td>
                    <td class="{{ $group === 'expired' ? 'warn' : '' }}">{{ $lot->expires_at->format('d.m.Y') }}</td>
                    <td class="num">{{ $n($lot->remainingQuantityAsOf($asOf->toDateString())) }}</td>
                </tr>
            @endforeach
        @endif
    @endforeach
    </tbody>
</table>
@endif

{{-- 4 --}}
<h2>{{ __('warehouse.inventory.section.blocked') }} <span class="count">({{ $blocked->count() }})</span></h2>
@if ($blocked->isEmpty())
    <p class="none">{{ __('warehouse.inventory.no_blocked') }}</p>
@else
<table>
    <thead><tr>
        <th style="width:30%">{{ __('warehouse.counting.part') }}</th>
        <th style="width:16%">{{ __('warehouse.lot.field.state') }}</th>
        <th style="width:14%">{{ __('warehouse.lot.field.tag') }}</th>
        <th style="width:40%">{{ __('warehouse.lot.field.reason') }}</th>
    </tr></thead>
    <tbody>
    @foreach ($blocked as $lot)
        @php $change = $lot->stateChanges->first(); @endphp
        <tr>
            <td>{{ $lot->partType?->name }}<div class="sub">{{ $lot->label() }}</div></td>
            <td class="warn">{{ $lot->state->label() }}</td>
            <td class="sub">{{ $change?->quarantine_tag ?? '—' }}</td>
            <td class="sub">
                {{ $change?->reason }}
                @if ($change)
                    <div>{{ $change->occurred_at->format('d.m.Y') }}
                        @if ($change->certifierDescription()) · {{ $change->certifierDescription() }} @endif
                    </div>
                @endif
            </td>
        </tr>
    @endforeach
    </tbody>
</table>
@endif

{{-- 5 --}}
<h2>{{ __('warehouse.inventory.section.missing_evidence') }} <span class="count">({{ $missing->count() }})</span></h2>
<p class="lead">{{ __('warehouse.inventory.missing_evidence_hint') }}</p>
@if ($missing->isEmpty())
    <p class="none">{{ __('warehouse.inventory.no_missing_evidence') }}</p>
@else
<table>
    <thead><tr>
        <th style="width:40%">{{ __('warehouse.counting.part') }}</th>
        <th style="width:26%">{{ __('warehouse.lot.singular') }}</th>
        <th style="width:34%">{{ __('warehouse.lot.field.document') }}</th>
    </tr></thead>
    <tbody>
    @foreach ($missing as $lot)
        <tr>
            <td>{{ $lot->partType?->name }}</td>
            <td class="sub">{{ $lot->label() }}</td>
            <td class="{{ $lot->document_reference ? 'sub' : 'warn' }}">
                {{ $lot->document_reference ?: __('warehouse.inventory.no_reference_at_all') }}
            </td>
        </tr>
    @endforeach
    </tbody>
</table>
@endif

{{-- 6 --}}
@if ($journal !== null)
    <h2>{{ __('warehouse.inventory.section.journal') }} <span class="count">({{ $journal->count() }})</span></h2>
    <table>
        <thead><tr>
            <th style="width:12%">{{ __('warehouse.lot.field.when') }}</th>
            <th style="width:28%">{{ __('warehouse.counting.part') }}</th>
            <th style="width:14%">{{ __('warehouse.lot.field.movement') }}</th>
            <th style="width:12%" class="num">{{ __('warehouse.lot.field.quantity') }}</th>
            <th style="width:34%">{{ __('warehouse.inventory.destination') }}</th>
        </tr></thead>
        <tbody>
        @foreach ($journal as $movement)
            <tr>
                <td class="sub">{{ $movement->occurred_at->format('d.m.Y') }}</td>
                <td>{{ $movement->partType?->name }}
                    @if ($movement->lot)<div class="sub">{{ $movement->lot->lot_number }}</div>@endif
                </td>
                <td class="sub">{{ $movement->type->label() }}</td>
                <td class="num">{{ $movement->isInbound() ? '+' : '' }}{{ $n((float) $movement->quantity) }}</td>
                <td class="sub">
                    {{ $movement->aircraft_reference }}
                    @if ($movement->work_order_reference) · {{ $movement->work_order_reference }} @endif
                    @if ($movement->user) · {{ $movement->user->name }} @endif
                </td>
            </tr>
        @endforeach
        </tbody>
    </table>
@endif

<div class="foot">
    <div>{{ __('warehouse.inventory.compiled_by') }}</div>
    <div>{{ __('warehouse.counting.date') }}</div>
    <div>{{ __('warehouse.counting.signature') }}</div>
</div>

</body>
</html>
