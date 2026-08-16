{{--
    Massenübersicht Motorflugzeug / Motorsegler -- das Druckblatt.

    Gliederung wie das klassische Wägeformular: Kennblattdaten oben,
    darunter EINE durchlaufende Rechnung von den Auflagen über die Abzüge
    zur Leermasse. Briefkopf, Logo und die Zeichnungen des Vorbilds sind
    nicht übernommen; der Kopf trägt den eigenen Vereinsnamen, die Skizze
    ist eine eigene, schematische Darstellung.

    Der Rechenweg ist ein anderer als beim Segelflugzeug -- nicht der Hebel,
    sondern das Moment: jede Auflage mit ihrem Arm ab Bezugspunkt, davon die
    Momente des ausfliegbaren Kraft- und Schmierstoffs abgezogen. Kraftstoff
    aus einem Flügeltank zu nehmen verschiebt den Schwerpunkt und macht nicht
    nur leichter; deshalb tragen auch die Abzüge einen Hebelarm.

    EINHEITEN sind kg, mm und kgmm -- auch hier. Das Papier rechnet in kp und
    mkp; die bewusste Abweichung sorgt dafür, dass Datenbank und Ausdruck
    dieselbe Zahl zeigen.

    Erwartet: $weighing, dazu optional $aircraft und $result.
--}}
@php
    $aircraft = $aircraft ?? $weighing->aircraft;
    $result = $result ?? $weighing->result();

    $variant = $weighing->sheet_variant?->label() ?? $weighing->kind->label();

    $num = fn (mixed $v, int $d = 2): string => $v === null || $v === ''
        ? ''
        : number_format((float) $v, $d, ',', '.');

    $feld = fn (mixed $v, int $d = 2): string => $v === null || $v === ''
        ? '________'
        : number_format((float) $v, $d, ',', '.');

    $text = fn (?string $v): string => $v === null || trim($v) === '' ? '________' : $v;

    $supports = $weighing->entriesOf('support');
    $deductions = $weighing->entriesOf('deduction');

    // Summe I -- was auf den Waagen stand, samt Moment.
    $massOne = $supports->sum(fn ($entry): float => $entry->netto());
    $momentOne = $supports->sum(
        fn ($entry): float => $entry->arm_mm === null ? 0.0 : $entry->netto() * (float) $entry->arm_mm
    );

    // Summe II -- was ausgeflogen werden kann und deshalb nicht zur Leermasse zählt.
    $massTwo = $deductions->sum(fn ($entry): float => $entry->deductedMass());
    $momentTwo = $deductions->sum(
        fn ($entry): float => $entry->arm_mm === null ? 0.0 : $entry->deductedMass() * (float) $entry->arm_mm
    );
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
        .sum td, .sum th { background: #f0f0f0; }
        .confirm { margin-top: 3mm; font-size: 8pt; line-height: 1.5; }
        .stamp { min-height: 10mm; }
        .sheet-id { margin-top: 5mm; border-top: 0.3mm solid #000; padding-top: 1mm;
                    font-size: 6.5pt; display: flex; justify-content: space-between; }
        tr { page-break-inside: avoid; }
    </style>
</head>
<body>

{{-- 1 — Kopf --}}
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

{{-- Block A — links, was das Kennblatt vorgibt; rechts, wie gerechnet wird --}}
<div class="cols">
    <div class="col">
        <table>
            <thead>
                <tr><th colspan="2">{{ __('fleet.sheet.type_data') }}</th></tr>
            </thead>
            <tbody>
                <tr>
                    <th style="width:46mm">{{ __('fleet.sheet.datum') }}</th>
                    <td>{{ $weighing->datum_reference }}</td>
                </tr>
                <tr>
                    <th>{{ __('fleet.sheet.reference_plane') }}</th>
                    <td>{{ $weighing->reference_line }}</td>
                </tr>
                <tr>
                    <th>{{ __('fleet.sheet.max_mass') }}</th>
                    <td class="num">{{ $num($weighing->max_mass_kg) }} kg</td>
                </tr>
                @if ($weighing->max_mass_water_kg !== null)
                    <tr>
                        <th>{{ __('fleet.sheet.max_mass_water') }}</th>
                        <td class="num">{{ $num($weighing->max_mass_water_kg) }} kg</td>
                    </tr>
                @endif
                @if ($weighing->max_non_lifting_kg !== null)
                    <tr>
                        <th>{{ __('fleet.sheet.max_non_lifting') }}</th>
                        <td class="num">{{ $num($weighing->max_non_lifting_kg) }} kg</td>
                    </tr>
                @endif
                <tr>
                    <th>{{ __('fleet.sheet.cg_range') }}</th>
                    <td class="num">
                        {{ $feld($weighing->cg_range_from_mm, 0) }} – {{ $feld($weighing->cg_range_to_mm, 0) }} mm
                    </td>
                </tr>
                <tr>
                    <th>{{ __('fleet.sheet.at_empty_mass') }}</th>
                    <td class="num">{{ $feld($weighing->cg_range_at_mass_kg, 1) }} kg</td>
                </tr>
                <tr>
                    <th>{{ __('fleet.sheet.useful_load') }}</th>
                    <td class="num">{{ $num($result->usefulLoadKg) }} kg</td>
                </tr>
                <tr>
                    <th>{{ __('fleet.sheet.cockpit_load') }}</th>
                    <td class="num">
                        {{ __('fleet.sheet.min') }} {{ $num($weighing->cockpit_load_min_kg) }} /
                        {{ __('fleet.sheet.max') }} {{ $num($weighing->cockpit_load_max_kg) }} kg
                    </td>
                </tr>
                <tr>
                    <th>{{ __('fleet.sheet.equipment_list_dated') }}</th>
                    <td>{{ $weighing->equipment_list_dated?->format('d.m.Y') }}</td>
                </tr>
            </tbody>
        </table>
    </div>

    <div class="col">
        @include('fleet.sheet._sketch_moments')
    </div>
</div>

{{-- Block B — eine Tabelle: Auflagen, Summe I, Abzüge, Summe II, Leermasse --}}
<table style="margin-bottom:3mm">
    <thead>
        <tr>
            <th>{{ __('fleet.sheet.cg_determination') }}</th>
            <th style="width:18mm">{{ __('fleet.sheet.gross') }}</th>
            <th style="width:18mm">{{ __('fleet.sheet.tare') }}</th>
            <th style="width:18mm">{{ __('fleet.sheet.netto') }}</th>
            <th style="width:20mm">{{ __('fleet.sheet.arm') }}</th>
            <th style="width:26mm">{{ __('fleet.sheet.moment') }}</th>
        </tr>
    </thead>
    <tbody>
        @foreach ($supports as $row)
            <tr>
                <td>{{ $row->label }}</td>
                <td class="num">{{ $num($row->gross_kg) }}</td>
                <td class="num">{{ $num($row->tare_kg) }}</td>
                <td class="num">{{ $num($row->netto()) }}</td>
                <td class="num">{{ $num($row->arm_mm, 0) }}</td>
                <td class="num">{{ $row->arm_mm === null ? '' : $num($row->netto() * (float) $row->arm_mm, 0) }}</td>
            </tr>
        @endforeach

        <tr class="sum">
            <td colspan="3"><b>{{ __('fleet.sheet.sum_one') }}</b></td>
            <td class="num"><b>{{ $num($massOne) }}</b></td>
            <td></td>
            <td class="num"><b>{{ $num($momentOne, 0) }}</b></td>
        </tr>

        {{-- Die Abzugszeilen füllen dieselben Spalten mit anderen Größen --
             deshalb beschriftet die Zwischenüberschrift sie neu, statt den
             Leser raten zu lassen, was in „Brutto" jetzt steht. --}}
        <tr>
            <th>{{ __('fleet.sheet.deductions') }}</th>
            <th>{{ __('fleet.sheet.volume') }}</th>
            <th>{{ __('fleet.sheet.density') }}</th>
            <th>{{ __('fleet.sheet.mass') }}</th>
            <th>{{ __('fleet.sheet.arm') }}</th>
            <th>{{ __('fleet.sheet.moment') }}</th>
        </tr>

        @foreach ($deductions as $row)
            <tr>
                <td>{{ $row->label }}</td>
                <td class="num">{{ $num($row->volume_litres) }}</td>
                <td class="num">{{ $num($row->density_kg_per_litre, 3) }}</td>
                <td class="num">{{ $num($row->deductedMass()) }}</td>
                <td class="num">{{ $num($row->arm_mm, 0) }}</td>
                <td class="num">{{ $row->arm_mm === null ? '' : $num($row->deductedMass() * (float) $row->arm_mm, 0) }}</td>
            </tr>
        @endforeach

        <tr class="sum">
            <td colspan="3"><b>{{ __('fleet.sheet.sum_two') }}</b></td>
            <td class="num"><b>{{ $num($massTwo) }}</b></td>
            <td></td>
            <td class="num"><b>{{ $num($momentTwo, 0) }}</b></td>
        </tr>

        {{-- Die Schlusszeile zeigt die festgeschriebenen Werte der Wägung,
             nicht die hier aufaddierten -- ein unterschriebenes Blatt behält
             seine Zahlen, auch wenn sich die Rechnung später ändert. --}}
        <tr class="sum">
            <td colspan="3"><b>{{ __('fleet.sheet.empty_mass_and_cg') }}</b></td>
            <td class="num"><b>{{ $num($result->emptyMassKg) }}</b></td>
            <td colspan="2" class="num">
                <b>{{ $result->emptyCgMm === null ? '' : $num($result->emptyCgMm, 1).' '.__('fleet.sheet.behind_datum') }}</b>
            </td>
        </tr>
    </tbody>
</table>

{{-- Der zulässige Bereich, gegen den das Ergebnis gelesen wird --}}
<div style="margin-bottom:3mm; font-size:8.5pt">
    {{ __('fleet.sheet.cg_range_line', [
        'from' => $feld($weighing->cg_range_from_mm, 0),
        'to' => $feld($weighing->cg_range_to_mm, 0),
        'mass' => $feld($weighing->cg_range_at_mass_kg, 1),
    ]) }}
</div>

{{-- Bemerkungen --}}
<table>
    <thead>
        <tr><th>{{ __('fleet.sheet.remarks') }}</th></tr>
    </thead>
    <tbody>
        <tr><td style="height:12mm">{{ $weighing->remarks }}</td></tr>
    </tbody>
</table>

{{-- Bestätigungen --}}
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

{{-- Unterschriften --}}
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

{{-- Eigene Blattbezeichnung, eigener Stand --}}
<div class="sheet-id">
    <span>{{ __('fleet.sheet.foot.powered') }}</span>
    <span>{{ __('fleet.sheet.foot.revision') }}</span>
</div>

</body>
</html>
