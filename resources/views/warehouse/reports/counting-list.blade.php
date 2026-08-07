<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <title>{{ __('warehouse.counting.title') }}</title>
    <style>
        @page { size: A4 portrait; margin: 12mm 10mm 14mm; }
        * { box-sizing: border-box; }
        body { font: 9pt/1.35 "DejaVu Sans", Arial, sans-serif; margin: 0; color: #111; }

        h1 { font-size: 13pt; margin: 0 0 1mm; }
        .meta { font-size: 8pt; color: #555; margin-bottom: 4mm; }
        .meta strong { color: #111; }

        table { width: 100%; border-collapse: collapse; }
        thead { display: table-header-group; }   /* Kopf auf jeder Seite */
        th { text-align: left; font-size: 7.5pt; text-transform: uppercase;
             letter-spacing: .2pt; color: #444; border-bottom: .4mm solid #000;
             padding: 1.2mm 1mm; }
        td { padding: 1.4mm 1mm; border-bottom: .15mm solid #ddd; vertical-align: top; }
        tr { page-break-inside: avoid; }

        .loc { background: #f0f0f0; font-weight: bold; font-size: 8.5pt; }
        .num { text-align: right; white-space: nowrap; }
        .box { border: .3mm solid #000; height: 5mm; width: 20mm; display: inline-block; }
        .lot { font-size: 7.5pt; color: #555; }
        .warn { color: #b00; }

        .foot { margin-top: 6mm; font-size: 8pt; display: flex; gap: 12mm; }
        .foot div { flex: 1; border-top: .3mm solid #000; padding-top: 1mm; color: #555; }

        @media screen {
            body { max-width: 190mm; margin: 10mm auto; }
            .noprint { background: #eef; padding: 3mm; margin-bottom: 4mm; font-size: 9pt; }
        }
        @media print { .noprint { display: none; } }
    </style>
</head>
<body>

<div class="noprint">{{ __('warehouse.counting.hint') }}</div>

<h1>{{ __('warehouse.counting.title') }}</h1>
<div class="meta">
    <strong>{{ $club }}</strong>
    &nbsp;·&nbsp; {{ __('warehouse.counting.printed', ['date' => now()->timezone(config('aeronance.organisation.timezone'))->format('d.m.Y H:i')]) }}
    @if ($location) &nbsp;·&nbsp; {{ $location->name }} @endif
</div>

<table>
    <thead>
        <tr>
            <th style="width: 38%">{{ __('warehouse.counting.part') }}</th>
            <th style="width: 16%">{{ __('warehouse.counting.compartment') }}</th>
            <th style="width: 14%" class="num">{{ __('warehouse.counting.expected') }}</th>
            <th style="width: 22%">{{ __('warehouse.counting.counted') }}</th>
            <th style="width: 10%">{{ __('warehouse.counting.note') }}</th>
        </tr>
    </thead>
    <tbody>
        @php $currentLocation = null; @endphp

        @forelse ($parts as $part)
            @php $locationName = $part->storageCompartment?->location?->name ?? __('warehouse.counting.unassigned'); @endphp

            @if ($locationName !== $currentLocation)
                @php $currentLocation = $locationName; @endphp
                <tr><td class="loc" colspan="5">{{ $locationName }}</td></tr>
            @endif

            <tr>
                <td>
                    {{ $part->name }}
                    @if ($part->ipc_part_number)
                        <div class="lot">IPC {{ $part->ipc_part_number }}</div>
                    @endif
                </td>
                <td>{{ $part->storageCompartment?->name ?? '—' }}</td>
                <td class="num">
                    {{ rtrim(rtrim(number_format($part->currentStock(), 3, ',', '.'), '0'), ',') }}
                    {{ $part->unit_of_measure }}
                </td>
                <td><span class="box"></span></td>
                <td></td>
            </tr>

            {{-- Losgefuehrte Teile einzeln: hier wird je Los gezaehlt, weil ein
                 Ueberschuss spaeter NICHT auf ein vorhandenes Los gebucht werden
                 darf -- er gehoert nicht zu dessen Form 1. --}}
            @foreach ($part->lots as $lot)
                @continue($lot->remainingQuantity() <= 0)
                <tr>
                    <td style="padding-left: 6mm" class="lot">
                        ↳ {{ $lot->label() }}
                        @if ($lot->document_reference) · {{ $lot->document_reference }} @endif
                        @if ($lot->state->value !== 'serviceable')
                            <span class="warn">· {{ $lot->state->label() }}</span>
                        @endif
                    </td>
                    <td class="lot">
                        @if ($lot->expires_at)
                            <span class="{{ $lot->hasExpired() ? 'warn' : '' }}">
                                {{ $lot->expires_at->format('m/Y') }}
                            </span>
                        @endif
                    </td>
                    <td class="num lot">
                        {{ rtrim(rtrim(number_format($lot->remainingQuantity(), 3, ',', '.'), '0'), ',') }}
                    </td>
                    <td><span class="box"></span></td>
                    <td></td>
                </tr>
            @endforeach
        @empty
            <tr><td colspan="5">{{ __('warehouse.counting.empty') }}</td></tr>
        @endforelse
    </tbody>
</table>

<div class="foot">
    <div>{{ __('warehouse.counting.counted_by') }}</div>
    <div>{{ __('warehouse.counting.date') }}</div>
    <div>{{ __('warehouse.counting.signature') }}</div>
</div>

</body>
</html>
