{{--
    Massenübersicht Segelflugzeug -- das Druckblatt.

    Die GLIEDERUNG folgt dem klassischen Wägeformular (welche Felder, in
    welcher Reihenfolge), damit ein Prüfer das Blatt liest, ohne es zu
    übersetzen. Briefkopf, Logo, Fußzeile und die Zeichnungen des Vorbilds
    sind bewusst NICHT übernommen -- der Kopf trägt den eigenen Vereinsnamen
    aus der Konfiguration, die Skizze ist eine eigene, schematische
    Darstellung.

    Zwei Abweichungen vom Papier, beide absichtlich:

     1. EINHEITEN sind durchgehend kg und mm. Das Papier rechnet in kp/mkp;
        hier zeigen Datenbank und Ausdruck dieselbe Zahl, statt an einer
        Stelle stillschweigend umzurechnen.

     2. Die ZULADUNG in der M.N.T.-Spalte ist abgeleitet, kein Eingabewert:
        Im Flug gehört die Zuladung zu den nichttragenden Teilen, bei der
        Wägung steht der Flieger aber leer da. Die zulässige Zuladung ist
        deshalb das, was die Kennblattgrenze über den gewogenen Bauteilen
        noch frei lässt.

    Erwartet: $weighing, dazu optional $aircraft und $result (sonst werden
    sie aus der Wägung geholt).
--}}
@php
    $aircraft = $aircraft ?? $weighing->aircraft;
    $result = $result ?? $weighing->result();

    $variant = $weighing->sheet_variant?->label() ?? $weighing->kind->label();

    // Fehlende Werte ergeben leere Zellen -- ein halb ausgefülltes Blatt ist
    // ein normaler Zwischenstand und darf nie eine Ausnahme werfen.
    $num = fn (mixed $v, int $d = 2): string => $v === null || $v === ''
        ? ''
        : number_format((float) $v, $d, ',', '.');

    // In Fließtext und Formularzeilen steht statt der Leere eine Linie zum
    // Ausfüllen mit der Hand -- so, wie das Blatt auch auf Papier funktioniert.
    $feld = fn (mixed $v, int $d = 2): string => $v === null || $v === ''
        ? '________'
        : number_format((float) $v, $d, ',', '.');

    $text = fn (?string $v): string => $v === null || trim($v) === '' ? '________' : $v;

    $components = $weighing->entriesOf('component');
    $supports = $weighing->entriesOf('support');

    // G der Formel: was auf den Waagen stand. Beim zerlegt gewogenen
    // Segelflugzeug muss das nicht auf das Gramm der Bauteilsumme treffen --
    // deshalb steht hier die Summe DIESER Tabelle und nicht das Ergebnis oben.
    $supportTotal = $supports->sum(fn ($entry): float => $entry->netto());

    $nonLiftingSum = $result->nonLiftingMassKg;

    $allowedNonLiftingLoad = $weighing->max_non_lifting_kg === null
        ? null
        : (float) $weighing->max_non_lifting_kg - ($nonLiftingSum ?? 0.0);
@endphp
<!doctype html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <title>{{ __('fleet.sheet.title', ['variant' => $variant]) }} {{ $aircraft?->registration }}</title>
    @include('fleet.print._sheet')
    <style>
        /* Hochformat -- steht NACH dem Include, weil _sheet Querformat setzt
           und die spätere Regel gewinnt. */
        @page { size: A4 portrait; margin: 12mm 10mm; }

        .sheet-orgname { font-size: 9pt; margin-bottom: 3mm; }
        .cols { display: flex; gap: 4mm; align-items: flex-start; margin-bottom: 3mm; }
        .col { flex: 1; min-width: 0; }
        .grey { background: #e8e8e8; }
        .bar { border: 0.3mm solid #000; padding: 1.5mm 2mm; margin-bottom: 3mm; }
        .bar-title { font-size: 7.5pt; font-weight: 700; margin-bottom: 1mm; }
        .line { margin-bottom: 3mm; font-size: 8.5pt; }
        .centered { text-align: center; }
        .confirm { margin-top: 3mm; font-size: 8pt; line-height: 1.5; }
        .stamp { min-height: 10mm; }
        .sheet-id { margin-top: 5mm; border-top: 0.3mm solid #000; padding-top: 1mm;
                    font-size: 6.5pt; display: flex; justify-content: space-between; }
        tr { page-break-inside: avoid; }
    </style>
</head>
<body>

{{-- 1 — Kopf: eigener Vereinsname, kein fremder Briefkopf --}}
<div class="sheet-head">
    <div class="sheet-title">{{ __('fleet.sheet.title', ['variant' => $variant]) }}</div>
    <div class="sheet-org"><b>{{ __('fleet.sheet.registration') }}:</b> {{ $aircraft?->registration }}</div>
</div>
<div class="sheet-orgname">{{ config('aeronance.organisation.name') }}</div>

{{-- 2 — Muster, Werk-Nr., Auftr.-Nr. --}}
<table style="margin-bottom:2mm">
    <thead>
        <tr>
            <th>{{ __('fleet.sheet.model') }}</th>
            <th style="width:40mm">{{ __('fleet.sheet.serial_number') }}</th>
            <th style="width:40mm">{{ __('fleet.sheet.order_reference') }}</th>
        </tr>
    </thead>
    <tbody>
        <tr>
            <td>{{ $aircraft?->model }}</td>
            <td>{{ $aircraft?->serial_number }}</td>
            <td>{{ $weighing->order_reference }}</td>
        </tr>
    </tbody>
</table>

{{-- 3 — Bezugspunkt und Bezugslinie: ohne sie ist jede Zahl unten ortlos --}}
<table style="margin-bottom:3mm">
    <tbody>
        <tr>
            <th style="width:52mm">{{ __('fleet.sheet.datum') }}</th>
            <td>{{ $weighing->datum_reference }}</td>
        </tr>
        <tr>
            <th>{{ __('fleet.sheet.reference_line') }}</th>
            <td>{{ $weighing->reference_line }}</td>
        </tr>
    </tbody>
</table>

{{-- 4 — Wägung und Massengrenzen nebeneinander, wie auf dem Blatt --}}
<div class="cols">
    <div class="col">
        <table>
            <thead>
                <tr>
                    <th>{{ __('fleet.sheet.weighing') }}</th>
                    <th style="width:22mm">{{ __('fleet.sheet.empty_masses') }}</th>
                    <th style="width:20mm">{{ __('fleet.sheet.non_lifting') }}</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($components as $row)
                    {{-- Ohne eingetragene M.N.T. ist die Zeile ein tragendes Teil;
                         das Papier hinterlegt diese Zelle grau, statt sie leer
                         und damit wie „vergessen" aussehen zu lassen. --}}
                    @php($lifting = $row->non_lifting_kg === null)
                    <tr>
                        <td>{{ $row->label }}</td>
                        <td class="num">{{ $num($row->mass_kg) }}</td>
                        <td class="num {{ $lifting ? 'grey' : '' }}">{{ $lifting ? '' : $num($row->non_lifting_kg) }}</td>
                    </tr>
                @endforeach

                <tr>
                    <td>{{ __('fleet.sheet.useful_load') }}</td>
                    <td class="grey"></td>
                    <td class="num">{{ $num($allowedNonLiftingLoad) }}</td>
                </tr>

                <tr>
                    <td><b>{{ __('fleet.sheet.result') }}</b></td>
                    <td class="num"><b>{{ $num($result->emptyMassKg) }}</b></td>
                    <td class="num"><b>{{ $num($nonLiftingSum) }}</b></td>
                </tr>
            </tbody>
        </table>
        <div class="note">{{ __('fleet.sheet.non_lifting_note') }}</div>
    </div>

    <div class="col">
        <table>
            <thead>
                <tr>
                    <th>{{ __('fleet.sheet.limits') }}</th>
                    <th style="width:22mm">[kg]</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td>{{ __('fleet.sheet.empty_mass') }}</td>
                    <td class="num">{{ $num($result->emptyMassKg) }}</td>
                </tr>
                <tr>
                    <td>{{ __('fleet.sheet.useful_load') }}</td>
                    <td class="num">{{ $num($result->usefulLoadKg) }}</td>
                </tr>
                <tr>
                    <td>{{ __('fleet.sheet.max_mass') }}</td>
                    <td class="num">{{ $num($weighing->max_mass_kg) }}</td>
                </tr>
                <tr>
                    <td>{{ __('fleet.sheet.max_mass_water') }}</td>
                    <td class="num">{{ $num($weighing->max_mass_water_kg) }}</td>
                </tr>
                <tr>
                    <td>{{ __('fleet.sheet.max_non_lifting') }}</td>
                    <td class="num">{{ $num($weighing->max_non_lifting_kg) }}</td>
                </tr>
            </tbody>
        </table>

        <div class="note" style="margin-top:2mm"><b>{{ __('fleet.sheet.load_distribution') }}</b></div>

        <table style="margin-top:2mm">
            <tbody>
                <tr>
                    <th>{{ __('fleet.sheet.cockpit_load') }}</th>
                    <td class="num" style="width:20mm">{{ __('fleet.sheet.min') }} {{ $num($weighing->cockpit_load_min_kg) }}</td>
                    <td class="num" style="width:20mm">{{ __('fleet.sheet.max') }} {{ $num($weighing->cockpit_load_max_kg) }}</td>
                </tr>
            </tbody>
        </table>

        <table style="margin-top:2mm">
            <thead>
                <tr><th>{{ __('fleet.sheet.remarks') }}</th></tr>
            </thead>
            <tbody>
                <tr><td style="height:14mm">{{ $weighing->remarks }}</td></tr>
            </tbody>
        </table>
    </div>
</div>

{{-- 5 — Schwerpunktermittlung: was auf den Waagen stand --}}
<table style="margin-bottom:3mm">
    <thead>
        <tr>
            <th>{{ __('fleet.sheet.cg_determination') }}</th>
            <th style="width:20mm">{{ __('fleet.sheet.gross') }}</th>
            <th style="width:20mm">{{ __('fleet.sheet.tare') }}</th>
            <th style="width:20mm">{{ __('fleet.sheet.netto') }}</th>
            <th style="width:34mm">{{ __('fleet.sheet.arm') }}</th>
        </tr>
    </thead>
    <tbody>
        @foreach ($supports as $index => $row)
            <tr>
                <td>{{ $row->label }}</td>
                <td class="num">{{ $num($row->gross_kg) }}</td>
                <td class="num">{{ $num($row->tare_kg) }}</td>
                <td class="num">{{ $num($row->netto()) }}</td>
                <td class="num">
                    @if ($index === 0)
                        a = {{ $feld($weighing->front_support_arm_mm, 0) }}
                    @elseif ($index === 1)
                        b = {{ $feld($weighing->support_distance_mm, 0) }}
                    @else
                        {{ $num($row->arm_mm, 0) }}
                    @endif
                </td>
            </tr>
        @endforeach
        <tr>
            <td colspan="5" class="centered">
                {{ __('fleet.sheet.empty_mass') }} <b>{{ $num($supportTotal) }} kg</b>
            </td>
        </tr>
    </tbody>
</table>

{{-- 6 — Die eigene Skizze: was a, b, G1, G2 und X am Flugzeug sind --}}
@include('fleet.sheet._sketch_lever')

{{-- 7 — Das Ergebnis der Hebelrechnung, ausgeschrieben --}}
<div class="bar">
    <div class="bar-title">{{ __('fleet.sheet.empty_cg_bar') }}</div>
    @if ($result->emptyCgMm !== null && $supports->count() >= 2 && $supportTotal > 0)
        <div class="centered">
            X = (G2 · b) / G + a
            = ({{ $num($supports[1]->netto()) }} · {{ $num($weighing->support_distance_mm, 0) }})
            / {{ $num($supportTotal) }}
            {{ (float) ($weighing->front_support_arm_mm ?? 0) < 0 ? '−' : '+' }}
            {{ $num(abs((float) ($weighing->front_support_arm_mm ?? 0)), 0) }}
            = <b>{{ $num($result->emptyCgMm, 1) }} {{ __('fleet.sheet.behind_datum') }}</b>
        </div>
    @else
        <div class="note">{{ __('fleet.sheet.cg_not_computable') }}</div>
    @endif
</div>

{{-- 8 — Der zulässige Bereich, gegen den das Ergebnis gelesen wird --}}
<div class="line">
    {{ __('fleet.sheet.cg_range_line', [
        'from' => $feld($weighing->cg_range_from_mm, 0),
        'to' => $feld($weighing->cg_range_to_mm, 0),
        'mass' => $feld($weighing->cg_range_at_mass_kg, 1),
    ]) }}
</div>

{{-- 9 — Die drei Bestätigungen des Blattes --}}
<div class="confirm">
    @unless ($weighing->figuresMatchRows())
        <b>{{ __('fleet.weighing.figures_drifted') }}</b><br>
    @endunless

    @if ($result->isAcceptable())
        {{ __('fleet.sheet.confirm.cg_in_range') }}<br>
    @else
        <b>{{ __('fleet.sheet.confirm.cg_out_of_range') }}</b><br>
        @foreach ($result->findings as $finding)
            <b>{{ $finding }}</b><br>
        @endforeach
    @endif

    {{ __('fleet.sheet.confirm.equipment', [
        'date' => $weighing->equipment_list_dated?->format('d.m.Y') ?? '________',
    ]) }}<br>

    {{ __('fleet.sheet.confirm.loading_plan') }}
</div>

{{-- 10 — Unterschriften --}}
<div class="sheet-foot">
    <div class="sig">
        {{ $text($weighing->place) }}, {{ $weighing->weighed_at?->format('d.m.Y') ?? '________' }}<br>
        <span style="font-size:7pt">{{ __('fleet.sheet.sign.place_date') }}</span>
    </div>
    <div class="sig stamp">
        {{ $weighing->signed_by_approval }}<br>
        <span style="font-size:7pt">{{ __('fleet.sheet.sign.stamp') }}</span>
    </div>
    <div class="sig">
        {{ $weighing->signed_off_by_name ?: $weighing->signed_by_name }}<br>
        <span style="font-size:7pt">{{ __('fleet.sheet.sign.certifying_staff') }}</span>
    </div>
</div>

{{-- 11 — Eigene Blattbezeichnung, eigener Stand --}}
<div class="sheet-id">
    <span>{{ __('fleet.sheet.foot.glider') }}</span>
    <span>{{ __('fleet.sheet.foot.revision') }}</span>
</div>

</body>
</html>
