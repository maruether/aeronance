{{--
    Massenübersicht Segelflugzeug — der Blattkörper.

    ─────────────────────────────────────────────────────────────────────────────
    EINE VORLAGE FÜR EINGABE UND DRUCK.

    Feldtest: „Wichtig ist das ich sowohl in der eingabe als auch im druck ein
    solches formular sehen will, mit den tabellen etc. Ich will auf keinen fall
    die aktuellen Kacheln die es sind."

    Deshalb rendert diese Datei beides. `$editable = true` setzt Eingabefelder in
    die Zellen, sonst steht dort der Wert. Zwei getrennte Vorlagen wären zwei
    Orte, an denen sich dasselbe Blatt unterschiedlich entwickelt — und der
    Unterschied fiele erst auf, wenn jemand das Ausgedruckte neben den
    Bildschirm legt.

    Erwartet:
      $weighing   die Wägung (für abgeleitete Werte und den Kopf)
      $editable   ob Felder oder Werte
      $bauteile   Zeilen der Wägung   [['label','mass_kg','non_lifting_kg'], …]
      $auflagen   Zeilen der Auflagen [['label','gross_kg','tare_kg','arm_mm'], …]
      $kopf       skalare Felder      ['order_reference','datum_reference', …]
    ─────────────────────────────────────────────────────────────────────────────
--}}
@php
    $aircraft = $weighing->aircraft;
    $result = $weighing->result();
    $variant = $weighing->sheet_variant?->label() ?? $weighing->kind->label();

    $zahl = fn (mixed $v, int $d = 2): string => $v === null || $v === ''
        ? ''
        : number_format((float) $v, $d, ',', '.');

    // Im Druck steht statt der Leere eine Linie zum Ausfüllen mit der Hand --
    // so, wie das Blatt auch auf Papier funktioniert.
    $feld = fn (mixed $v, int $d = 2): string => $v === null || $v === ''
        ? '________'
        : number_format((float) $v, $d, ',', '.');

    /*
     * Die Zelle: Eingabefeld oder Wert. Zahlen werden im Eingabemodus ROH
     * gezeigt, nicht formatiert -- ein Feld, das „1.234,50" enthält und beim
     * Speichern über den Punkt stolpert, ist schlimmer als eines ohne
     * Tausenderpunkt.
     */
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

    $textzelle = function (string $model, ?string $wert, string $klasse = '') use ($editable): string {
        if (! $editable) {
            return e($wert ?? '');
        }

        return sprintf(
            '<input type="text" class="zelle %s" wire:model.blur="%s" value="%s">',
            e($klasse), e($model), e($wert ?? '')
        );
    };

    $summeNichtTragend = $result->nonLiftingMassKg;
    $zuladungNichtTragend = $result->nonLiftingHeadroomKg;
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

{{-- 3 — Bezugspunkt und Bezugslinie: ohne sie ist jede Zahl unten ortlos --}}
<table style="margin-bottom:3mm">
    <tbody>
        <tr>
            <th style="width:52mm">{{ __('fleet.sheet.datum') }}</th>
            <td>{!! $textzelle('kopf.datum_reference', $kopf['datum_reference'] ?? null, 'weit') !!}</td>
        </tr>
        <tr>
            <th>{{ __('fleet.sheet.reference_line') }}</th>
            <td>{!! $textzelle('kopf.reference_line', $kopf['reference_line'] ?? null, 'weit') !!}</td>
        </tr>
    </tbody>
</table>

{{-- 4 — Wägung und Massengrenzen NEBENEINANDER, wie auf dem Blatt --}}
<div class="cols">
    <div class="col">
        <table>
            <thead>
                <tr>
                    <th>{{ __('fleet.sheet.weighing') }}</th>
                    <th style="width:24mm">{{ __('fleet.sheet.empty_masses') }} [kg]</th>
                    <th style="width:22mm">{{ __('fleet.sheet.non_lifting') }} [kg]</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($bauteile as $i => $row)
                    {{-- Ohne eingetragene M.N.T. ist die Zeile ein tragendes Teil;
                         das Blatt hinterlegt diese Zelle grau, statt sie leer und
                         damit wie „vergessen" aussehen zu lassen. --}}
                    @php($traegt = ($row['non_lifting_kg'] ?? null) === null || ($row['non_lifting_kg'] ?? '') === '')
                    <tr>
                        <td>{!! $textzelle("bauteile.$i.label", $row['label'] ?? null, 'weit') !!}</td>
                        <td class="num">{!! $zelle("bauteile.$i.mass_kg", $row['mass_kg'] ?? null) !!}</td>
                        <td class="num {{ $traegt && ! $editable ? 'grau' : '' }}">
                            {!! $zelle("bauteile.$i.non_lifting_kg", $row['non_lifting_kg'] ?? null) !!}
                        </td>
                    </tr>
                @endforeach

                {{-- Abgeleitet, kein Eingabewert: Im Flug gehört die Zuladung zu
                     den nichttragenden Teilen, bei der Wägung steht der Flieger
                     leer da. --}}
                <tr>
                    <td>{{ __('fleet.sheet.useful_load') }}</td>
                    <td class="grau"></td>
                    <td class="num">{{ $zahl($zuladungNichtTragend) }}</td>
                </tr>

                <tr>
                    <td><b>{{ __('fleet.sheet.result') }}</b></td>
                    <td class="num"><b>{{ $zahl($result->emptyMassKg) }}</b></td>
                    <td class="num"><b>{{ $zahl($summeNichtTragend) }}</b></td>
                </tr>
            </tbody>
        </table>

        @if ($editable)
            <button type="button" wire:click="zeileHinzufuegen('bauteile')" class="zeile-plus">
                + {{ __('fleet.weighing.add_component') }}
            </button>
        @endif

        <div class="note">{{ __('fleet.sheet.non_lifting_note') }}</div>
    </div>

    <div class="col">
        <table>
            <thead>
                <tr>
                    <th>{{ __('fleet.sheet.limits') }}</th>
                    <th style="width:24mm">[kg]</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td>{{ __('fleet.sheet.empty_mass') }}</td>
                    <td class="num">{{ $zahl($result->emptyMassKg) }}</td>
                </tr>
                <tr>
                    <td>{{ __('fleet.sheet.useful_load') }}</td>
                    <td class="num">{{ $zahl($result->usefulLoadKg) }}</td>
                </tr>
                <tr>
                    <td>{{ __('fleet.sheet.max_mass') }}</td>
                    <td class="num">{!! $zelle('kopf.max_mass_kg', $kopf['max_mass_kg'] ?? null) !!}</td>
                </tr>
                <tr>
                    <td>{{ __('fleet.sheet.max_mass_water') }}</td>
                    <td class="num">{!! $zelle('kopf.max_mass_water_kg', $kopf['max_mass_water_kg'] ?? null) !!}</td>
                </tr>
                <tr>
                    <td>{{ __('fleet.sheet.max_non_lifting') }}</td>
                    <td class="num">{!! $zelle('kopf.max_non_lifting_kg', $kopf['max_non_lifting_kg'] ?? null) !!}</td>
                </tr>
            </tbody>
        </table>

        <div class="note" style="margin-top:2mm"><b>{{ __('fleet.sheet.load_distribution') }}</b></div>

        <table style="margin-top:2mm">
            <tbody>
                <tr>
                    <th>{{ __('fleet.sheet.cockpit_load') }}</th>
                    <td class="num" style="width:22mm">
                        {{ __('fleet.sheet.min') }}
                        {!! $zelle('kopf.cockpit_load_min_kg', $kopf['cockpit_load_min_kg'] ?? null, 'num schmal') !!}
                    </td>
                    <td class="num" style="width:22mm">
                        {{ __('fleet.sheet.max') }}
                        {!! $zelle('kopf.cockpit_load_max_kg', $kopf['cockpit_load_max_kg'] ?? null, 'num schmal') !!}
                    </td>
                </tr>
            </tbody>
        </table>

        <table style="margin-top:2mm">
            <thead>
                <tr><th>{{ __('fleet.sheet.remarks') }}</th></tr>
            </thead>
            <tbody>
                <tr>
                    <td style="height:14mm; vertical-align:top">
                        @if ($editable)
                            <textarea wire:model.blur="kopf.remarks" class="zelle weit" rows="3">{{ $kopf['remarks'] ?? '' }}</textarea>
                        @else
                            {{ $weighing->remarks }}
                        @endif
                    </td>
                </tr>
            </tbody>
        </table>
    </div>
</div>

{{-- 5 — Schwerpunktermittlung: was auf den Waagen stand --}}
<table style="margin-bottom:2mm">
    <thead>
        <tr>
            <th>{{ __('fleet.sheet.cg_determination') }}</th>
            <th style="width:22mm">{{ __('fleet.sheet.gross') }} [kg]</th>
            <th style="width:22mm">{{ __('fleet.sheet.tare') }} [kg]</th>
            <th style="width:22mm">{{ __('fleet.sheet.netto') }} [kg]</th>
            <th style="width:36mm">{{ __('fleet.sheet.arm') }} [mm]</th>
        </tr>
    </thead>
    <tbody>
        @foreach ($auflagen as $i => $row)
            @php($netto = max(0.0, (float) ($row['gross_kg'] ?? 0) - (float) ($row['tare_kg'] ?? 0)))
            <tr>
                <td>{!! $textzelle("auflagen.$i.label", $row['label'] ?? null, 'weit') !!}</td>
                <td class="num">{!! $zelle("auflagen.$i.gross_kg", $row['gross_kg'] ?? null) !!}</td>
                <td class="num">{!! $zelle("auflagen.$i.tare_kg", $row['tare_kg'] ?? null) !!}</td>
                <td class="num">{{ $zahl($netto) }}</td>
                <td class="num">
                    {{-- a und b stehen auf dem Blatt IN den Hebelarm-Zellen der
                         beiden Auflagenzeilen -- nicht in einem Feld daneben. --}}
                    @if ($i === 0)
                        a = {!! $editable
                            ? $zelle('kopf.front_support_arm_mm', $kopf['front_support_arm_mm'] ?? null, 'num schmal', 0)
                            : $feld($weighing->front_support_arm_mm, 0) !!}
                    @elseif ($i === 1)
                        b = {!! $editable
                            ? $zelle('kopf.support_distance_mm', $kopf['support_distance_mm'] ?? null, 'num schmal', 0)
                            : $feld($weighing->support_distance_mm, 0) !!}
                    @else
                        {!! $zelle("auflagen.$i.arm_mm", $row['arm_mm'] ?? null, 'num', 0) !!}
                    @endif
                </td>
            </tr>
        @endforeach

        <tr>
            <td colspan="3" class="centered"><b>{{ __('fleet.sheet.empty_mass') }}</b></td>
            <td class="num"><b>{{ $zahl($result->emptyMassKg) }}</b></td>
            <td></td>
        </tr>
    </tbody>
</table>

@if ($editable)
    <button type="button" wire:click="zeileHinzufuegen('auflagen')" class="zeile-plus">
        + {{ __('fleet.weighing.add_support') }}
    </button>
@endif

{{-- 6 — Die Zeichnung --}}
@include('fleet.sheet._sketch_lever')

{{-- 7 — Leergewichts-Schwerpunktlage --}}
<div class="bar">
    <div class="bar-title">{{ __('fleet.sheet.empty_cg_bar') }}</div>
    <div>
        @if ($result->emptyCgMm === null)
            {{ __('fleet.sheet.cg_not_computable') }}
        @else
            X = <b>{{ $zahl($result->emptyCgMm, 1) }} {{ __('fleet.sheet.behind_datum') }}</b>
        @endif
    </div>
</div>

{{-- 8 — Der zulässige Bereich, gegen den das Ergebnis gelesen wird --}}
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

{{-- 9 — Die Bestätigungen des Blattes --}}
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
<table style="margin-top:5mm">
    <thead>
        <tr>
            <th>{{ __('fleet.sheet.sign.place_date') }}</th>
            <th>{{ __('fleet.sheet.sign.stamp') }}</th>
            <th>{{ __('fleet.sheet.sign.certifying_staff') }}</th>
        </tr>
    </thead>
    <tbody>
        <tr>
            <td class="stamp">
                {{ $weighing->place }}{{ $weighing->place && $weighing->weighed_at ? ', ' : '' }}{{ $weighing->weighed_at?->format('d.m.Y') }}
            </td>
            <td class="stamp"></td>
            <td class="stamp">{{ $weighing->signed_off_by_name ?? $weighing->signed_by_name }}</td>
        </tr>
    </tbody>
</table>

<div class="sheet-id">
    <span>{{ __('fleet.sheet.foot.glider') }}</span>
    <span>{{ __('fleet.sheet.foot.revision') }}</span>
</div>
