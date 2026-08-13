<!doctype html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <title>LTA/TM {{ $aircraft->registration }}</title>
    <style>
        @page { size: A4 landscape; margin: 10mm 8mm; }
        body { font-family: "DejaVu Sans", Arial, sans-serif; font-size: 8.5pt; margin: 0; }
        .head { display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 3mm; }
        .title { font-size: 13pt; font-weight: 700; }
        .org { font-size: 8pt; text-align: right; }
        .ident { display: flex; gap: 8mm; margin-bottom: 3mm; font-size: 9.5pt; }
        table { width: 100%; border-collapse: collapse; }
        th, td { border: 0.3mm solid #000; padding: 1mm 1.5mm; vertical-align: top; }
        th { font-size: 7.5pt; font-weight: 700; text-align: left; background: #f0f0f0; }
        .flag { border: 0.6mm solid #000; padding: 2mm 3mm; margin-bottom: 3mm; font-weight: 700; }
        /* Heavier than .flag, and shaded: this one has to survive a black-and-white
           photocopy of a photocopy, which is what ends up in an aircraft file. */
        .alarm { border: 1.2mm solid #000; background: #e6e6e6; padding: 3mm; margin-bottom: 3mm; }
        .alarm .alarm-head { font-size: 11pt; font-weight: 700; text-transform: uppercase; }
        .alarm .alarm-body { margin-top: 1.5mm; font-size: 8.5pt; }
        .sum { margin-top: 4mm; display: flex; gap: 10mm; font-size: 8pt; }
        .foot { margin-top: 8mm; display: flex; gap: 12mm; font-size: 8pt; }
        .sig { flex: 1; border-top: 0.3mm solid #000; padding-top: 1mm; }
        .note { margin-top: 3mm; font-size: 7pt; }
        .no-print { margin-bottom: 4mm; }
        @media print { .no-print { display: none; } }
    </style>
</head>
<body>

<div class="no-print"><button type="button" data-print>Drucken</button><script src="/js/print-button.js"></script></div>

<div class="head">
    <div class="title">Übersicht LTA / TM</div>
    <div class="org">{{ config('aeronance.organisation.name') }}</div>
</div>

<div class="ident">
    <div><b>Luftfahrzeug:</b> {{ $aircraft->registration }}</div>
    <div><b>Muster:</b> {{ $aircraft->model }}</div>
    @if ($aircraft->serial_number)
        <div><b>Werk-Nr.:</b> {{ $aircraft->serial_number }}</div>
    @endif
    <div><b>Erstellt:</b> {{ now()->format('d.m.Y H:i') }}</div>
</div>

{{-- Above everything, including the unassessed count.

     A short list on paper reads as "little was published". For a type whose
     support lapsed it means the opposite: nobody publishes anything at all, and
     whatever is missing from this sheet is missing because the club had to find
     it itself. Left off the print, the sheet claims a completeness it has not
     got -- and this is the copy that goes into the aircraft file. --}}
@if ($aircraftType?->isOrphaned())
    {{-- Through the language files, unlike the fixed labels around it: these two
         sentences say the same thing on paper as on the screen, and two copies of
         a warning drift apart exactly once. --}}
    <div class="alarm">
        <div class="alarm-head">{{ __('directives.orphaned.headline') }}</div>
        <div class="alarm-body">
            {{ __('directives.orphaned.body', ['type' => $aircraftType->designation]) }}
        </div>
    </div>
@endif

{{-- Said at the top, because it is the one thing somebody reading this has to
     know before they read anything else. --}}
@if ($unassessed > 0)
    <div class="flag">
        {{ $unassessed }} Zeile(n) nicht beurteilt — das verhindert die Freigabe.
    </div>
@endif

<table>
    <thead>
        <tr>
            <th style="width:26mm">Nummer</th>
            <th style="width:14mm">Art</th>
            <th style="width:20mm">Verbindlich</th>
            <th>Titel</th>
            <th style="width:20mm">Frist</th>
            <th style="width:24mm">Beurteilung</th>
            <th style="width:20mm">Datum</th>
            <th style="width:30mm">Von</th>
            <th>Begründung / Ausführung</th>
            <th style="width:22mm">Wieder fällig</th>
        </tr>
    </thead>
    <tbody>
        @forelse ($lines as $line)
            @php($d = $line['directive'])
            @php($a = $line['application'])
            <tr>
                <td>{{ $d->number }}</td>
                <td>{{ $d->kind->label() }}</td>
                <td>{{ $d->bindingness->label() }}</td>
                <td>{{ $d->title }}</td>
                <td>{{ $d->comply_before?->format('d.m.Y') ?? '' }}</td>
                <td>{{ ($a?->state ?? \App\Modules\Directives\Enums\ComplianceState::Open)->label() }}</td>
                <td>{{ $a?->assessed_at?->format('d.m.Y') ?? '' }}</td>
                <td>{{ $a?->assessed_by_name ?? '' }}{{ $a?->qualification_reference ? ' ('.$a->qualification_reference.')' : '' }}</td>
                <td>{{ $a?->reason ?? $a?->method ?? '' }}{{ $a?->task_card_reference ? ' · '.$a->task_card_reference : '' }}</td>
                <td>{{ $a?->next_due_at?->format('d.m.Y') ?? '' }}</td>
            </tr>
        @empty
            {{-- An empty table on paper is the most dangerous thing this module
                 can print, so the cell says which of the readings applies. --}}
            <tr><td colspan="10">
                {{ $aircraftType?->isOrphaned()
                    ? __('directives.empty.orphaned')
                    : __('directives.empty.ambiguous') }}
            </td></tr>
        @endforelse
    </tbody>
</table>

<div class="sum">
    <div><b>Zeilen:</b> {{ $lines->count() }}</div>
    <div><b>Nicht beurteilt:</b> {{ $unassessed }}</div>
    <div><b>Offen:</b> {{ $outstanding }}</div>
</div>

<div class="note">
    Nicht zutreffende Zeilen bleiben mit Begründung in der Liste — eine fehlende Zeile
    beweist nicht, dass hingeschaut wurde. „Nicht durchgeführt" gibt es nur für optionale
    Anweisungen; eine verbindliche ist durchgeführt, trifft nicht zu, oder steht im Weg.
</div>

<div class="foot">
    <div class="sig">Ort und Datum</div>
    <div class="sig">Name<br><span style="font-size:7pt">Unterschrift</span></div>
    <div class="sig"><br><span style="font-size:7pt">Lizenz / Berechtigung</span></div>
</div>

</body>
</html>
