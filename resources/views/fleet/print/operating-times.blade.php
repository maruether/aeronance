<!doctype html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <title>Betriebszeitenübersicht {{ $aircraft->registration }}</title>
    @include('fleet.print._sheet')
</head>
<body>

<div class="sheet-head">
    <div class="sheet-title">Betriebszeitenübersicht</div>
    <div class="sheet-org">{{ config('aeronance.organisation.name') }}</div>
</div>

<div class="sheet-ident">
    <div><b>Kennzeichen:</b> {{ $aircraft->registration }}</div>
    <div><b>Muster:</b> {{ $aircraft->model }}</div>
    <div><b>Werk-Nr.:</b> {{ $aircraft->serial_number ?? '—' }}</div>
</div>

<table>
    <thead>
        <tr>
            <th rowspan="2">Benennung des Geräts oder Teils,<br>Teilenummer, Werk-Nummer</th>
            <th rowspan="2" style="width: 34mm">zulässige Betriebszeit,<br>Kalenderzeit, Starts u. a.</th>
            <th colspan="2">Betriebsdaten des Teils</th>
            <th colspan="3">Betriebsdaten des Luftfahrzeugs</th>
            <th colspan="3">Eintragungsvermerke</th>
        </tr>
        <tr>
            <th>beim Einbau</th>
            <th>beim Ausbau</th>
            <th>beim Einbau</th>
            <th>fälliger Ausbau</th>
            <th>beim Ausbau</th>
            <th>Datum Einbau</th>
            <th>Datum Ausbau</th>
            <th>Kurzz.</th>
        </tr>
    </thead>
    <tbody>
        @forelse ($rows as $row)
            @php
                $counter = $row->aircraft?->keeps(\App\Modules\Fleet\Enums\CounterKind::EngineHours)
                    ? \App\Modules\Fleet\Enums\CounterKind::EngineHours
                    : \App\Modules\Fleet\Enums\CounterKind::FlightHours;
                $fmt = fn (?float $v) => $v === null ? '' : rtrim(rtrim(number_format($v, 2, ',', '.'), '0'), ',');
            @endphp
            <tr>
                <td>
                    <b>{{ $row->part_name }}</b>
                    @if ($row->part_number || $row->serial_number)
                        <br><span style="font-size:7pt">
                            {{ $row->part_number }}{{ $row->part_number && $row->serial_number ? ' · ' : '' }}{{ $row->serial_number }}
                        </span>
                    @endif
                </td>

                {{-- Every limit on one line, which is how the BWLV column is
                     headed: "Kalenderzeit, Starts u. a." One component, several
                     limits, earliest wins. --}}
                <td style="font-size:7.5pt">
                    @forelse ($row->limits as $limit)
                        {{ $limit->describe() }}@if ($limit->tolerance() > 0) <span style="font-size:6.5pt">(±{{ rtrim(rtrim(number_format($limit->tolerance(), 2, ',', '.'), '0'), ',') }})</span>@endif<br>
                    @empty
                        —
                    @endforelse
                </td>

                <td class="num">{{ $fmt($row->carried_since_new[$counter->value] ?? null) }}</td>
                <td class="num">{{ $row->removed_at ? $fmt($row->usage($counter, \App\Modules\Fleet\Enums\UsageBasis::SinceNew)) : '' }}</td>

                <td class="num">{{ $fmt($row->counters_at_installation[$counter->value] ?? null) }}</td>

                {{-- "fälliger Ausbau": the AIRCRAFT reading it falls due at, not
                     the component's remaining life. At the hangar there is an
                     instrument with a number on it, and this is the number to
                     compare it against. --}}
                <td class="num">
                    @foreach ($row->limits as $limit)
                        @if ($limit->kind->isCalendar())
                            {{ $limit->dueDate()?->format('d.m.Y') }}<br>
                        @else
                            {{ $fmt($limit->dueAtAircraftValue()) }}<br>
                        @endif
                    @endforeach
                </td>

                <td class="num">{{ $fmt($row->counters_at_removal[$counter->value] ?? null) }}</td>

                <td class="num">{{ $row->installed_at?->format('d.m.Y') }}</td>
                <td class="num">{{ $row->removed_at?->format('d.m.Y') }}</td>
                <td>{{ $row->installed_by_name ? \Illuminate\Support\Str::of($row->installed_by_name)->explode(' ')->map(fn ($p) => mb_substr($p, 0, 1))->implode('') : '' }}</td>
            </tr>
        @empty
            <tr><td colspan="10">Keine Einbauten erfasst.</td></tr>
        @endforelse

        @for ($i = 0; $i < max(0, 8 - $rows->count()); $i++)
            <tr>
                <td>&nbsp;</td><td></td><td></td><td></td><td></td><td></td><td></td><td></td><td></td><td></td>
            </tr>
        @endfor
    </tbody>
</table>

<div class="sheet-foot">
    <div class="sig">Datum der Prüfung</div>
    <div class="sig">Betriebszeit des Luftfahrzeugs</div>
    <div class="sig">Starts</div>
    <div class="sig">Unterschrift / Stempel Freigabeberechtigter</div>
</div>

</body>
</html>
