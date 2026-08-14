<!doctype html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <title>Massenübersicht {{ $aircraft->registration }}</title>
    @include('fleet.print._sheet')
    <style>@page { size: A4 portrait; margin: 12mm 10mm; }</style>
</head>
<body>

<div class="sheet-head">
    <div class="sheet-title">Massenübersicht {{ $weighing->kind->label() }}</div>
    <div class="sheet-org">{{ config('aeronance.organisation.name') }}</div>
</div>

<div class="sheet-ident">
    <div><b>Kennzeichen:</b> {{ $aircraft->registration }}</div>
    <div><b>Muster:</b> {{ $aircraft->model }}</div>
    <div><b>Werk-Nr.:</b> {{ $aircraft->serial_number ?? '—' }}</div>
    <div><b>Auftr.-Nr.:</b> {{ $weighing->order_reference ?? '—' }}</div>
</div>

<div class="sheet-ident">
    <div><b>Bezugspunkt B.P.:</b> {{ $weighing->datum_reference ?? '—' }}</div>
    <div><b>Bezugslinie B.L.:</b> {{ $weighing->reference_line ?? '—' }}</div>
</div>

@php($fmt = fn (?float $v, int $d = 2) => $v === null ? '' : number_format($v, $d, ',', '.'))

@if ($weighing->kind->usesComponents())
    <table style="margin-bottom:4mm">
        <thead>
            <tr>
                <th>WÄGUNG</th>
                <th style="width:26mm">Leermassen [kg]</th>
                <th style="width:26mm">M.N.T. [kg]</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($weighing->entriesOf('component') as $row)
                <tr>
                    <td>{{ $row->label }}</td>
                    <td class="num">{{ $fmt((float) $row->mass_kg) }}</td>
                    <td class="num">{{ $row->non_lifting_kg === null ? '' : $fmt((float) $row->non_lifting_kg) }}</td>
                </tr>
            @endforeach
            <tr>
                <td><b>ERGEBNIS</b></td>
                <td class="num"><b>{{ $fmt($result->emptyMassKg) }}</b></td>
                <td class="num"><b>{{ $fmt($result->nonLiftingMassKg) }}</b></td>
            </tr>
        </tbody>
    </table>
@endif

<table style="margin-bottom:4mm">
    <thead>
        <tr>
            <th>SCHWERPUNKTERMITTLUNG</th>
            <th style="width:22mm">Brutto [kg]</th>
            <th style="width:22mm">Tara [kg]</th>
            <th style="width:22mm">Netto [kg]</th>
            <th style="width:24mm">Hebelarm [mm]</th>
        </tr>
    </thead>
    <tbody>
        @foreach ($weighing->entriesOf('support') as $row)
            <tr>
                <td>{{ $row->label }}</td>
                <td class="num">{{ $fmt((float) $row->gross_kg) }}</td>
                <td class="num">{{ $fmt((float) $row->tare_kg) }}</td>
                <td class="num">{{ $fmt($row->netto()) }}</td>
                <td class="num">{{ $row->arm_mm === null ? '' : $fmt((float) $row->arm_mm, 0) }}</td>
            </tr>
        @endforeach
    </tbody>
</table>

@php($stuetzen = $weighing->entriesOf('support')->values())
@if ($stuetzen->count() >= 3 || ($weighing->kind->value === 'powered' && $stuetzen->count() >= 2))
    {{-- Motorflug: drei Auflagen, Momente statt Hebel -- eigene Zeichnung,
         weil es ein anderer Rechenweg ist. --}}
    @include('fleet.print._weighing_moment_sketch', [
        'supports' => $stuetzen->map(fn ($e): array => [
            'label' => (string) $e->label,
            'mass' => (float) $e->netto(),
            'arm' => $e->arm_mm === null ? null : (float) $e->arm_mm,
        ])->all(),
        'total' => (float) $stuetzen->sum(fn ($e): float => $e->netto()),
        'x' => $result->emptyCgMm !== null ? (float) $result->emptyCgMm : null,
        'fmt' => $fmt,
    ])
@elseif ($stuetzen->count() === 2)
    {{-- Zwei Auflagen, ein Hebel: die bebilderte Erklaerung dazu --
         Feldtest: "Optik ... an einem klassischen Waegeformular mit
         bebilderter Erklaerung abstuetzen." --}}
    @include('fleet.print._weighing_sketch', [
        'a' => $weighing->front_support_arm_mm !== null ? (float) $weighing->front_support_arm_mm : 0.0,
        'b' => $weighing->support_distance_mm !== null ? (float) $weighing->support_distance_mm : null,
        'g1' => (float) $stuetzen[0]->netto(),
        'g2' => (float) $stuetzen[1]->netto(),
        'g' => (float) $stuetzen[0]->netto() + (float) $stuetzen[1]->netto(),
        'x' => $result->emptyCgMm !== null ? (float) $result->emptyCgMm : null,
        'fmt' => $fmt,
    ])
@endif

@if ($weighing->kind->usesDeductions() && $weighing->entriesOf('deduction')->isNotEmpty())
    <table style="margin-bottom:4mm">
        <thead>
            <tr>
                <th>ABZÜGE (ausfliegbarer Kraft- und Schmierstoff)</th>
                <th style="width:22mm">Menge [l]</th>
                <th style="width:22mm">Dichte [kg/l]</th>
                <th style="width:22mm">Masse [kg]</th>
                <th style="width:24mm">Hebelarm [mm]</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($weighing->entriesOf('deduction') as $row)
                <tr>
                    <td>{{ $row->label }}</td>
                    <td class="num">{{ $fmt((float) $row->volume_litres) }}</td>
                    <td class="num">{{ $fmt((float) $row->density_kg_per_litre, 3) }}</td>
                    <td class="num">{{ $fmt($row->deductedMass()) }}</td>
                    <td class="num">{{ $row->arm_mm === null ? '' : $fmt((float) $row->arm_mm, 0) }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>
@endif

<table>
    <thead>
        <tr><th colspan="2">ERGEBNIS</th><th colspan="2">MASSENGRENZEN</th></tr>
    </thead>
    <tbody>
        <tr>
            <td>Leermasse</td><td class="num"><b>{{ $fmt($result->emptyMassKg) }} kg</b></td>
            <td>Höchstmasse ohne Wasserballast</td><td class="num">{{ $fmt((float) $weighing->max_mass_kg) }} kg</td>
        </tr>
        <tr>
            <td>Leermassen-Schwerpunktlage</td>
            <td class="num"><b>{{ $result->emptyCgMm === null ? '—' : $fmt($result->emptyCgMm, 1).' mm hinter B.P.' }}</b></td>
            <td>Höchstmasse mit Wasserballast</td><td class="num">{{ $fmt((float) $weighing->max_mass_water_kg) }} kg</td>
        </tr>
        <tr>
            <td>Zuladung</td><td class="num">{{ $fmt($result->usefulLoadKg) }} kg</td>
            <td>Höchstmasse der N.T.</td><td class="num">{{ $fmt((float) $weighing->max_non_lifting_kg) }} kg</td>
        </tr>
        <tr>
            <td>Schwerpunktbereich laut Flughandbuch</td>
            <td class="num">{{ $fmt((float) $weighing->cg_range_from_mm, 0) }} – {{ $fmt((float) $weighing->cg_range_to_mm, 0) }} mm</td>
            <td>Zuladung im Cockpit</td>
            <td class="num">{{ $fmt((float) $weighing->cockpit_load_min_kg) }} – {{ $fmt((float) $weighing->cockpit_load_max_kg) }} kg</td>
        </tr>
    </tbody>
</table>

@php($plan = $weighing->loadingPlan())

@if ($plan->computable)
    <table style="margin-top:4mm">
        <thead>
            <tr><th colspan="4">BELADEPLAN</th></tr>
            <tr>
                <th>Sitzplatz</th>
                <th style="width:24mm">Hebelarm</th>
                <th style="width:24mm">Zuladung min</th>
                <th style="width:24mm">Zuladung max</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($plan->seats as $seat)
                <tr>
                    <td>{{ $seat['seat'] }} <span style="font-size:7pt">({{ __('fleet.loading.limited_by.'.$seat['limited_by']) }})</span></td>
                    <td class="num">{{ $fmt($seat['arm'], 0) }} mm</td>
                    <td class="num">{{ $fmt($seat['min'], 1) }} kg</td>
                    <td class="num"><b>{{ $fmt($seat['max'], 1) }} kg</b></td>
                </tr>
            @endforeach
        </tbody>
    </table>

    @if ($plan->combinations !== [])
        {{-- What a loading plan looks like in a cockpit: read across from the
             weight of the person in the back. --}}
        <table style="margin-top:3mm">
            <thead>
                <tr>
                    <th style="width:30mm">{{ __('fleet.loading.rear') }} [kg]</th>
                    <th>{{ __('fleet.loading.front') }} — zulässig [kg]</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($plan->combinations as $row)
                    <tr>
                        <td class="num">{{ $fmt($row['rear'], 0) }}</td>
                        <td class="num">
                            @if ($row['possible'])
                                {{ $fmt($row['front_min'], 1) }} – {{ $fmt($row['front_max'], 1) }}
                            @else
                                {{ __('fleet.loading.not_possible') }}
                            @endif
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif

    <div class="note">{{ __('fleet.loading.check_manual') }}</div>
@endif

@unless ($weighing->figuresMatchRows())
    <div class="note" style="margin-top:3mm"><b>{{ __('fleet.weighing.figures_drifted') }}</b></div>
@endunless

<div class="note" style="margin-top:4mm">
    @if ($result->isAcceptable())
        <b>{{ __('fleet.weighing.in_range') }}</b><br>
    @else
        @foreach ($result->findings as $finding)
            <b>{{ $finding }}</b><br>
        @endforeach
    @endif
    Die Ausrüstung bei der Wägung siehe Ausrüstungsliste vom
    {{ $weighing->equipment_list_dated?->format('d.m.Y') ?? '____________' }}<br>
    Der Beladeplan im Flughandbuch wurde berichtigt bzw. stimmt mit diesem Ergebnis überein.
    @if ($weighing->remarks)
        <br>{{ $weighing->remarks }}
    @endif
</div>

<div class="sheet-foot">
    <div class="sig">{{ $weighing->place }}, {{ $weighing->weighed_at->format('d.m.Y') }}<br><span style="font-size:7pt">Ort und Datum</span></div>
    <div class="sig">{{ $weighing->signed_by_name }}<br><span style="font-size:7pt">Name in Druckbuchstaben</span></div>
    <div class="sig">{{ $weighing->signed_by_approval }}<br><span style="font-size:7pt">Stempel</span></div>
    <div class="sig"><br><span style="font-size:7pt">Freigabeberechtigter</span></div>
</div>

</body>
</html>
