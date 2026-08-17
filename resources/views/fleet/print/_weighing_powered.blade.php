{{--
    Massenübersicht Motorsegler / Flugzeug — der Ausdruck.

    Nur Rahmen: Papierformat, Blatt-CSS, und der Blattkörper aus
    fleet.sheet._powered. Derselbe Körper trägt die Eingabemaske.
--}}
@php
    $aircraft = $aircraft ?? $weighing->aircraft;
    $variant = $weighing->sheet_variant?->label() ?? $weighing->kind->label();

    $auflagen = $weighing->entriesOf('support')
        ->map(fn ($e): array => [
            'label' => $e->label,
            'gross_kg' => $e->gross_kg,
            'tare_kg' => $e->tare_kg,
            'arm_mm' => $e->arm_mm,
        ])->all();

    $abzuege = $weighing->entriesOf('deduction')
        ->map(fn ($e): array => [
            'label' => $e->label,
            'volume_litres' => $e->volume_litres,
            'density_kg_per_litre' => $e->density_kg_per_litre,
            'arm_mm' => $e->arm_mm,
        ])->all();

    $konfigurationen = $weighing->entriesOf('configuration')
        ->map(fn ($e): array => [
            'label' => $e->label,
            'useful_load_kg' => $e->useful_load_kg,
            'max_mass_kg' => $e->max_mass_kg,
            'cg_from_mm' => $e->cg_from_mm,
            'cg_to_mm' => $e->cg_to_mm,
        ])->all();

    $kopf = [
        'order_reference' => $weighing->order_reference,
        'datum_reference' => $weighing->datum_reference,
        'reference_line' => $weighing->reference_line,
        'datum_plane' => $weighing->datum_plane,
        'fuselage_reference_plane' => $weighing->fuselage_reference_plane,
        'cg_range_from_mm' => $weighing->cg_range_from_mm,
        'cg_range_to_mm' => $weighing->cg_range_to_mm,
        'cg_range_at_mass_kg' => $weighing->cg_range_at_mass_kg,
        'remarks' => $weighing->remarks,
    ];
@endphp
<!doctype html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <title>{{ __('fleet.sheet.title', ['variant' => $variant]) }} {{ $aircraft?->registration }}</title>
    @include('fleet.print._sheet')
    <style>
        /* Hochformat -- steht NACH dem Include, weil _sheet Querformat setzt. */
        @page { size: A4 portrait; margin: 12mm 10mm; }
    </style>
    @include('fleet.sheet._styles')
</head>
<body>

@include('fleet.sheet._powered', ['editable' => false])

</body>
</html>