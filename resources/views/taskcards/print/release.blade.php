<!doctype html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <title>{{ $release->number }} — {{ $release->aircraft_registration }}</title>
    <style>
        @page { size: A4; margin: 18mm 16mm; }
        body { font-family: "DejaVu Sans", Arial, sans-serif; font-size: 10pt; margin: 0; color: #000; }
        .frame { border: 0.5mm solid #000; padding: 6mm; }
        .head { display: flex; justify-content: space-between; margin-bottom: 4mm; }
        .title { font-size: 14pt; font-weight: 700; }
        .subtitle { font-size: 9pt; }
        .org { font-size: 9pt; text-align: right; }
        .number { font-size: 12pt; font-weight: 700; }
        table.meta { width: 100%; border-collapse: collapse; margin: 4mm 0; }
        table.meta td { border: 0.3mm solid #000; padding: 1.5mm 2mm; vertical-align: top; }
        table.meta td b { display: block; font-size: 7.5pt; font-weight: 700; text-transform: uppercase; }
        .statement { border: 0.3mm solid #000; padding: 3mm; margin: 4mm 0; min-height: 24mm; white-space: pre-line; }
        .cards { font-size: 8.5pt; margin: 3mm 0; }
        .banner { border: 1mm solid #000; padding: 3mm; margin-bottom: 4mm; font-weight: 700; font-size: 11pt; text-align: center; letter-spacing: 1px; }
        .correction { border: 0.3mm solid #000; padding: 2mm 3mm; margin: 3mm 0; font-size: 9pt; }
        .sig { margin-top: 10mm; display: flex; gap: 10mm; }
        .sig > div { flex: 1; border-top: 0.3mm solid #000; padding-top: 1.5mm; font-size: 8pt; }
        .note { margin-top: 5mm; font-size: 7pt; }
        .no-print { margin-bottom: 4mm; }
        @media print { .no-print { display: none; } }
    </style>
</head>
<body>

<div class="no-print"><button type="button" data-print>Drucken</button><script src="/js/print-button.js"></script></div>

@if ($supersededBy !== null)
    {{-- The one thing a stale printout must not do is look current. --}}
    <div class="banner">
        ERSETZT — gilt nicht mehr.<br>
        <span style="font-size:9pt; font-weight:400">
            Ersetzt durch {{ $supersededBy->number }} vom {{ $supersededBy->released_at->format('d.m.Y') }}.
            Grund: {{ $supersededBy->correction_reason }}
        </span>
    </div>
@endif

<div class="frame">
    <div class="head">
        <div>
            <div class="title">Freigabebescheinigung</div>
            <div class="subtitle">Certificate of Release to Service — VO (EU) 1321/2014</div>
        </div>
        <div class="org">
            {{ config('aeronance.organisation.name') }}<br>
            <span class="number">{{ $release->number }}</span>
        </div>
    </div>

    @if ($release->isCorrection())
        <div class="correction">
            <b>Korrektur:</b> Diese Freigabe ersetzt {{ $release->supersedes?->number ?? '—' }}.
            Grund: {{ $release->correction_reason }}
            — Die ersetzte Bescheinigung bleibt Teil der Aufzeichnungen.
        </div>
    @endif

    <table class="meta">
        <tr>
            <td style="width:30%"><b>Luftfahrzeug</b>{{ $release->aircraft_registration }}</td>
            <td style="width:30%"><b>Muster</b>{{ $release->aircraft_model ?? '—' }}</td>
            <td><b>Vorgang</b>{{ $workOrder?->number ?? '—' }} — {{ $workOrder?->title }}</td>
        </tr>
        <tr>
            <td><b>Freigegeben am</b>{{ $release->released_at->format('d.m.Y') }}</td>
            <td><b>Instandhaltungsunterlagen</b>{{ $release->maintenance_data ?? '—' }}</td>
            <td><b>Stände bei Freigabe</b>
                @forelse ($release->counters_at_release ?? [] as $kind => $value)
                    {{ __('fleet.counter.'.$kind) }}: {{ $value }}@if (! $loop->last) · @endif
                @empty
                    —
                @endforelse
            </td>
        </tr>
    </table>

    <div class="statement">{{ $release->statement }}</div>

    @if ($cards->isNotEmpty())
        <div class="cards">
            <b>Arbeitskarten dieses Vorgangs:</b>
            @foreach ($cards as $card)
                {{ $card->number }} {{ $card->title }}@if (! $loop->last) · @endif
            @endforeach
        </div>
    @endif

    <table class="meta">
        <tr>
            <td><b>Freigegeben von</b>{{ $release->released_by_name }}</td>
            <td><b>Berechtigung</b>
                {{-- Ueber die Sprachdatei, nicht per Literal-Vergleich: gespeichert
     wird part66_licence, und der alte Vergleich auf 'part66' erklaerte
     jede Part-66-Freigabe zum Pilot-Owner (Feldtest). --}}
                {{ __('qualifications.type.'.$release->qualification_type) }}
                {{ $release->qualification_reference }}
                {{ $release->qualification_category ? '('.$release->qualification_category.')' : '' }}</td>
            <td><b>Gültig bis</b>{{ $release->qualification_valid_until?->format('d.m.Y') ?? 'unbefristet' }}</td>
        </tr>
    </table>

    <div class="sig">
        <div>Ort, Datum</div>
        <div>{{ $release->released_by_name }}<br>Unterschrift</div>
    </div>
</div>

<div class="note">
    Erstellt aus Aeronance am {{ now()->format('d.m.Y H:i') }} —
    Inhalt bei Erteilung festgeschrieben; Korrekturen nur als neue, referenzierende Bescheinigung.
</div>

</body>
</html>
