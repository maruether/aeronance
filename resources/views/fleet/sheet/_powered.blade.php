{{--
    Massenübersicht Motorsegler / Flugzeug — der Blattkörper.

    ─────────────────────────────────────────────────────────────────────────────
    Dieselbe Bauart wie das Segelflugblatt: `$editable` entscheidet, ob in den
    Zellen Werte oder Eingabefelder stehen. Eine Vorlage für Bildschirm und
    Papier, damit sie nicht auseinanderlaufen können.

    Der Aufbau ist ein anderer, weil das Blatt ein anderes ist:

      A — Kennblattdaten links, Zeichnung rechts. Dazu gehören Bezugsebene und
          Rumpfbezugsebene, die es beim Segelflugzeug nicht gibt, und die
          zugelassenen Konfigurationen (einsitzig, zweisitzig, …) mit je
          eigener Zuladung, Höchstmasse und Schwerpunktbereich.

      B — Wägung, Abzüge und Ergebnis in EINER durchlaufenden Rechnung:
          Auflagen mit Moment, Summe I, ausfliegbarer Kraft- und Schmierstoff,
          Summe II, Leermasse und Schwerpunktlage.

    Einheiten sind kg, mm und kgmm -- das Papier rechnet in kp/m/mkp, hier
    zeigen Datenbank und Ausdruck dieselbe Zahl.

    Erwartet: $weighing, $editable, $kopf, $auflagen, $abzuege, $konfigurationen
--}}
@php
    $aircraft = $weighing->aircraft;
    $result = $weighing->result();
    $variant = $weighing->sheet_variant?->label() ?? $weighing->kind->label();

    $zahl = fn (mixed $v, int $d = 2): string => $v === null || $v === ''
        ? ''
        : number_format((float) $v, $d, ',', '.');

    $feld = fn (mixed $v, int $d = 2): string => $v === null || $v === ''
        ? '________'
        : number_format((float) $v, $d, ',', '.');

    $zelle = function (string $model, mixed $wert, string $klasse = 'num', int $d = 2)
        use ($editable, $zahl): string {
        if (! $editable) {
            return e($zahl($wert, $d));
        }

        return sprintf(
            '<input type="text" inputmode="decimal" class="zelle %s" wire:model.blur="%s" value="%s">',
            e($klasse), e($model), e($wert ?? '')
        );
    };

    $textzelle = function (string $model, ?string $wert, string $klasse = 'weit') use ($editable): string {
        if (! $editable) {
            return e($wert ?? '');
        }

        return sprintf(
            '<input type="text" class="zelle %s" wire:model.blur="%s" value="%s">',
            e($klasse), e($model), e($wert ?? '')
        );
    };

    // Summe I: was auf den Waagen stand. Summe II: was davon ausfliegbar ist.
    $summeEins = 0.0;
    $momentEins = 0.0;

    foreach ($auflagen as $row) {
        $netto = (float) ($row['gross_kg'] ?? 0) - (float) ($row['tare_kg'] ?? 0);
        $summeEins += $netto;
        $momentEins += $netto * (float) ($row['arm_mm'] ?? 0);
    }

    $summeZwei = 0.0;
    $momentZwei = 0.0;

    foreach ($abzuege as $row) {
        $masse = (float) ($row['volume_litres'] ?? 0) * (float) ($row['density_kg_per_litre'] ?? 0);
        $summeZwei += $masse;
        $momentZwei += $masse * (float) ($row['arm_mm'] ?? 0);
    }
@endphp

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
            <th style="width:34mm">{{ __('fleet.sheet.serial_number') }}</th>
            <th style="width:34mm">{{ __('fleet.sheet.order_reference') }}</th>
        </tr>
    </thead>
    <tbody>
        <tr>
            <td>{{ $aircraft?->model }}</td>
            <td>{{ $aircraft?->serial_number }}</td>
            <td>{!! $textzelle('kopf.order_reference', $kopf['order_reference'] ?? null) !!}</td>
        </tr>
    </tbody>
</table>

{{-- 3 — Block A: Kennblattdaten links, Zeichnung rechts --}}
<div class="cols">
    <div class="col">
        <table>
            <thead>
                <tr><th colspan="2">{{ __('fleet.sheet.type_data') }}</th></tr>
            </thead>
            <tbody>
                <tr>
                    <th style="width:48mm">{{ __('fleet.sheet.datum') }}</th>
                    <td>{!! $textzelle('kopf.datum_reference', $kopf['datum_reference'] ?? null) !!}</td>
                </tr>
                <tr>
                    <th>{{ __('fleet.sheet.reference_plane') }}</th>
                    <td>{!! $textzelle('kopf.datum_plane', $kopf['datum_plane'] ?? null) !!}</td>
                </tr>
                <tr>
                    <th>{{ __('fleet.sheet.reference_line') }}</th>
                    <td>{!! $textzelle('kopf.reference_line', $kopf['reference_line'] ?? null) !!}</td>
                </tr>
                <tr>
                    <th>{{ __('fleet.sheet.fuselage_plane') }}</th>
                    <td>{!! $textzelle('kopf.fuselage_reference_plane', $kopf['fuselage_reference_plane'] ?? null) !!}</td>
                </tr>
                <tr>
                    <th>{{ __('fleet.sheet.empty_mass') }} [kg]</th>
                    <td class="num">{{ $zahl($result->emptyMassKg) }}</td>
                </tr>
                <tr>
                    <th>{{ __('fleet.sheet.empty_cg_from_plane') }}</th>
                    <td class="num">{{ $zahl($result->emptyCgMm, 1) }}</td>
                </tr>
            </tbody>
        </table>

        {{-- Die zugelassenen Konfigurationen: auf dem Papier zwei Tabellen mit
             denselben Zeilen, hier eine. --}}
        <table style="margin-top:2mm">
            <thead>
                <tr>
                    <th>{{ __('fleet.sheet.airworthiness') }}</th>
                    <th style="width:20mm">{{ __('fleet.sheet.useful_load') }}</th>
                    <th style="width:20mm">{{ __('fleet.sheet.max_flight_mass') }}</th>
                    <th style="width:18mm">xv</th>
                    <th style="width:18mm">xh</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($konfigurationen as $i => $row)
                    <tr>
                        <td>{!! $textzelle("konfigurationen.$i.label", $row['label'] ?? null) !!}</td>
                        <td class="num">{!! $zelle("konfigurationen.$i.useful_load_kg", $row['useful_load_kg'] ?? null) !!}</td>
                        <td class="num">{!! $zelle("konfigurationen.$i.max_mass_kg", $row['max_mass_kg'] ?? null) !!}</td>
                        <td class="num">{!! $zelle("konfigurationen.$i.cg_from_mm", $row['cg_from_mm'] ?? null, 'num', 0) !!}</td>
                        <td class="num">{!! $zelle("konfigurationen.$i.cg_to_mm", $row['cg_to_mm'] ?? null, 'num', 0) !!}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>

        @if ($editable)
            <button type="button" wire:click="zeileHinzufuegen('konfigurationen')" class="zeile-plus">
                + {{ __('fleet.sheet.add_configuration') }}
            </button>
        @endif
    </div>

    <div class="col">
        @include('fleet.sheet._sketch_moments')
    </div>
</div>

{{-- Bemerkungen --}}
<table style="margin-bottom:3mm">
    <thead>
        <tr><th>{{ __('fleet.sheet.remarks') }}</th></tr>
    </thead>
    <tbody>
        <tr>
            <td style="height:12mm; vertical-align:top">
                @if ($editable)
                    <textarea wire:model.blur="kopf.remarks" class="zelle weit" rows="2">{{ $kopf['remarks'] ?? '' }}</textarea>
                @else
                    {{ $weighing->remarks }}
                @endif
            </td>
        </tr>
    </tbody>
</table>

{{-- 4 — Block B: eine durchlaufende Rechnung --}}
<table style="margin-bottom:2mm">
    <thead>
        <tr>
            <th style="width:26mm"></th>
            <th>{{ __('fleet.sheet.support') ?? 'Auflage' }}</th>
            <th style="width:20mm">{{ __('fleet.sheet.gross') }} [kg]</th>
            <th style="width:18mm">{{ __('fleet.sheet.tare') }} [kg]</th>
            <th style="width:20mm">{{ __('fleet.sheet.netto') }} [kg]</th>
            <th style="width:22mm">{{ __('fleet.sheet.arm') }} [mm]</th>
            <th style="width:26mm">{{ __('fleet.sheet.moment') }} [kgmm]</th>
        </tr>
    </thead>
    <tbody>
        @foreach ($auflagen as $i => $row)
            @php($netto = (float) ($row['gross_kg'] ?? 0) - (float) ($row['tare_kg'] ?? 0))
            <tr>
                @if ($loop->first)
                    <th rowspan="{{ count($auflagen) }}">{{ __('fleet.sheet.weighing') }}</th>
                @endif
                <td>{!! $textzelle("auflagen.$i.label", $row['label'] ?? null) !!}</td>
                <td class="num">{!! $zelle("auflagen.$i.gross_kg", $row['gross_kg'] ?? null) !!}</td>
                <td class="num">{!! $zelle("auflagen.$i.tare_kg", $row['tare_kg'] ?? null) !!}</td>
                <td class="num">{{ $zahl($netto) }}</td>
                <td class="num">{!! $zelle("auflagen.$i.arm_mm", $row['arm_mm'] ?? null, 'num', 0) !!}</td>
                <td class="num">{{ $zahl($netto * (float) ($row['arm_mm'] ?? 0), 0) }}</td>
            </tr>
        @endforeach

        <tr>
            <th>{{ __('fleet.sheet.sum_one') }}</th>
            <td colspan="3" class="num"><b>{{ $zahl($summeEins) }}</b></td>
            <td></td>
            <td class="num"><b>{{ $zahl($momentEins, 0) }}</b></td>
        </tr>

        @foreach ($abzuege as $i => $row)
            @php($masse = (float) ($row['volume_litres'] ?? 0) * (float) ($row['density_kg_per_litre'] ?? 0))
            <tr>
                @if ($loop->first)
                    <th rowspan="{{ count($abzuege) }}">{{ __('fleet.sheet.deductions') }}</th>
                @endif
                <td>{!! $textzelle("abzuege.$i.label", $row['label'] ?? null) !!}</td>
                <td class="num">
                    {!! $zelle("abzuege.$i.volume_litres", $row['volume_litres'] ?? null) !!}
                    <span class="einheit">l</span>
                </td>
                <td class="num">{!! $zelle("abzuege.$i.density_kg_per_litre", $row['density_kg_per_litre'] ?? null, 'num', 3) !!}</td>
                <td class="num">{{ $zahl($masse) }}</td>
                <td class="num">{!! $zelle("abzuege.$i.arm_mm", $row['arm_mm'] ?? null, 'num', 0) !!}</td>
                <td class="num">{{ $zahl($masse * (float) ($row['arm_mm'] ?? 0), 0) }}</td>
            </tr>
        @endforeach

        <tr>
            <th>{{ __('fleet.sheet.sum_two') }}</th>
            <td colspan="3" class="num"><b>{{ $zahl($summeZwei) }}</b></td>
            <td></td>
            <td class="num"><b>{{ $zahl($momentZwei, 0) }}</b></td>
        </tr>

        <tr>
            <th>{{ __('fleet.sheet.empty_mass_and_cg') }}</th>
            <td colspan="3" class="num"><b>{{ $zahl($result->emptyMassKg) }} kg</b></td>
            <td colspan="2" class="num">
                <b>{{ $result->emptyCgMm === null ? '' : $zahl($result->emptyCgMm, 1).' mm' }}</b>
            </td>
        </tr>
    </tbody>
</table>

@if ($editable)
    <button type="button" wire:click="zeileHinzufuegen('auflagen')" class="zeile-plus">
        + {{ __('fleet.weighing.add_support') }}
    </button>
    <button type="button" wire:click="zeileHinzufuegen('abzuege')" class="zeile-plus">
        + {{ __('fleet.weighing.add_deduction') }}
    </button>
@endif

{{-- 5 — Der zulässige Bereich --}}
<div class="line">
    @if ($editable)
        {{ __('fleet.sheet.cg_range') }}
        {{ __('fleet.sheet.min') }} {!! $zelle('kopf.cg_range_from_mm', $kopf['cg_range_from_mm'] ?? null, 'num schmal', 0) !!} mm
        {{ __('fleet.sheet.max') }} {!! $zelle('kopf.cg_range_to_mm', $kopf['cg_range_to_mm'] ?? null, 'num schmal', 0) !!} mm
        {{ __('fleet.sheet.at_empty_mass') }} {!! $zelle('kopf.cg_range_at_mass_kg', $kopf['cg_range_at_mass_kg'] ?? null, 'num schmal', 1) !!} kg
    @else
        {{ __('fleet.sheet.cg_range_line', [
            'from' => $feld($weighing->cg_range_from_mm, 0),
            'to' => $feld($weighing->cg_range_to_mm, 0),
            'mass' => $feld($weighing->cg_range_at_mass_kg, 1),
        ]) }}
    @endif
</div>

{{-- 6 — Die Bestätigungen --}}
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

{{-- 7 — Unterschriften: beim Motorflugblatt VIER Felder --}}
<table style="margin-top:5mm">
    <thead>
        <tr>
            <th>{{ __('fleet.sheet.sign.place_date') }}</th>
            <th>{{ __('fleet.sheet.sign.printed_name') }}</th>
            <th>{{ __('fleet.sheet.sign.stamp') }}</th>
            <th>{{ __('fleet.sheet.sign.certifying_staff') }}</th>
        </tr>
    </thead>
    <tbody>
        <tr>
            <td class="stamp">
                {{ $weighing->place }}{{ $weighing->place && $weighing->weighed_at ? ', ' : '' }}{{ $weighing->weighed_at?->format('d.m.Y') }}
            </td>
            <td class="stamp">{{ $weighing->signed_by_name }}</td>
            <td class="stamp"></td>
            <td class="stamp">{{ $weighing->signed_off_by_name ?? $weighing->signed_by_name }}</td>
        </tr>
    </tbody>
</table>

<div class="sheet-id">
    <span>{{ __('fleet.sheet.foot.powered') }}</span>
    <span>{{ __('fleet.sheet.foot.revision') }}</span>
</div>
