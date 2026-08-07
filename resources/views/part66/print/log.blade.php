<!doctype html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <title>Erfahrungslogbuch {{ $person->name }}</title>
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
        td.num { text-align: right; white-space: nowrap; }
        .sum { margin-top: 4mm; display: flex; gap: 10mm; font-size: 8pt; }
        .note { margin-top: 3mm; font-size: 7pt; }
        .foot { margin-top: 8mm; display: flex; gap: 12mm; font-size: 8pt; }
        .sig { flex: 1; border-top: 0.3mm solid #000; padding-top: 1mm; }
        .no-print { margin-bottom: 4mm; }
        @media print { .no-print { display: none; } }
    </style>
</head>
<body>

<div class="no-print"><button onclick="window.print()">Drucken</button></div>

<div class="head">
    <div class="title">Erfahrungsnachweis nach Part-66</div>
    <div class="org">{{ config('aeronance.organisation.name') }}</div>
</div>

<div class="ident">
    <div><b>Name:</b> {{ $person->name }}</div>
    @foreach ($qualifications as $q)
        <div><b>{{ $q->type === 'part66' ? 'Lizenz' : 'Berechtigung' }}:</b>
            {{ $q->reference }}{{ $q->category ? ' ('.$q->category.')' : '' }}</div>
    @endforeach
    <div><b>Zeitraum:</b>
        {{ $span['from']?->format('d.m.Y') ?? '—' }} – {{ $span['to']?->format('d.m.Y') ?? '—' }}</div>
    <div><b>Erstellt:</b> {{ now()->format('d.m.Y') }}</div>
</div>

<table>
    <thead>
        <tr>
            <th style="width:20mm">Datum</th>
            <th style="width:20mm">Kennzeichen</th>
            <th style="width:26mm">Muster</th>
            <th style="width:12mm">ATA</th>
            <th style="width:24mm">Tätigkeit</th>
            <th>Ausgeführte Arbeit</th>
            <th style="width:16mm">Dauer</th>
            <th style="width:22mm">Mitwirkung</th>
            <th style="width:30mm">Abgezeichnet von</th>
            <th style="width:26mm">Freigabe</th>
        </tr>
    </thead>
    <tbody>
        @forelse ($entries as $entry)
            <tr>
                <td>{{ $entry->date->format('d.m.Y') }}</td>
                <td>{{ $entry->registration }}</td>
                <td>{{ $entry->model ?? '' }}</td>
                <td>{{ $entry->ataChapter ?? '' }}</td>
                <td>{{ $entry->activity->label() }}</td>
                <td>{{ $entry->workPerformed ?? '' }}</td>
                <td class="num">{{ $entry->duration() }}</td>
                <td>{{ $entry->participation->label() }}</td>
                <td>{{ $entry->certifiedByName ?? '' }}</td>
                {{-- A provisional line says so on the paper too: it belongs to a
                     visit that has not been released and can still change. --}}
                <td>{{ $entry->provisional ? __('part66.log.provisional') : ($entry->releaseNumber ?? '') }}</td>
            </tr>
        @empty
            <tr><td colspan="10">{{ __('part66.log.nothing') }}</td></tr>
        @endforelse
    </tbody>
</table>

<div class="sum">
    <div><b>Einträge:</b> {{ $entries->count() }}</div>
    <div><b>Gesamt:</b> {{ number_format($hours, 1, ',', '.') }} h</div>
    <div><b>Abgezeichnete Karten:</b> {{ $certifications }}</div>
    <div><b>Erteilte Freigaben:</b> {{ $releases }}</div>
    <div><b>Lufttüchtigkeitsprüfungen:</b> {{ $reviews->count() }}</div>
</div>

@if ($reviews->isNotEmpty())
    <div class="sum">
        <div><b>ARC:</b>
            {{ $reviews->map(fn ($r) => sprintf(
                '%s %s%s',
                $r->aircraft?->registration ?? '?',
                $r->issued_at->format('d.m.Y'),
                $r->certificate_reference ? ' ('.$r->certificate_reference.')' : '',
            ))->implode(' · ') }}</div>
    </div>
@endif

{{-- Built in PHP rather than with an inline @if after text: Blade cannot parse
     a directive glued to a literal, and the first version failed to compile. --}}
@php
    $fmtList = fn (array $values, ?callable $label = null): string => implode(' · ', array_map(
        fn ($key, $hours) => sprintf(
            '%s %s h',
            $label !== null ? $label($key) : $key,
            number_format($hours, 1, ',', '.'),
        ),
        array_keys($values),
        $values,
    ));
@endphp

<div class="sum">
    <div><b>Nach Tätigkeit:</b>
        {{ $fmtList($byActivity, fn ($kind) => __('taskcards.activity.'.$kind)) ?: '—' }}</div>
</div>

<div class="sum">
    <div><b>Nach Muster:</b> {{ $fmtList($byModel) ?: '—' }}</div>
</div>

<div class="note">
    <b>Aktualität (letzte {{ \App\Modules\Part66\Support\RecencyReport::WINDOW_MONTHS }} Monate):</b>
    {{ $recency['months'] }} Monate mit Arbeit, {{ $recency['days'] }} Tage,
    {{ number_format($recency['hours'], 1, ',', '.') }} h.
    @if ($recency['last_worked'])
        Letzte Arbeit am {{ $recency['last_worked']->format('d.m.Y') }}.
    @endif
    <br>
    {{ __('part66.recency.help.no_verdict') }}
    @foreach ($notes as $note)
        <br>{{ $note }}
    @endforeach
</div>

<div class="foot">
    <div class="sig">Ort und Datum</div>
    <div class="sig">{{ $person->name }}<br><span style="font-size:7pt">Unterschrift</span></div>
    <div class="sig"><br><span style="font-size:7pt">Bestätigung Werkstattleitung / Betrieb</span></div>
</div>

</body>
</html>
