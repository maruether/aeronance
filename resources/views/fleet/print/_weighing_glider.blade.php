{{--
    Massenübersicht Segelflugzeug — der Ausdruck.

    Nur noch Rahmen: Papierformat, Blatt-CSS, und der Blattkörper aus
    fleet.sheet._glider. Derselbe Körper trägt die Eingabemaske — deshalb sehen
    Bildschirm und Ausdruck gleich aus, und zwar nicht, weil jemand aufpasst,
    sondern weil es dieselbe Datei ist.
--}}
@php
    $aircraft = $aircraft ?? $weighing->aircraft;
    $variant = $weighing->sheet_variant?->label() ?? $weighing->kind->label();

    // Der Blattkörper liest Zeilen und Kopffelder als schlichte Felder -- im
    // Ausdruck aus dem Datensatz, in der Maske aus dem Formularzustand.
    $bauteile = $weighing->entriesOf('component')
        ->map(fn ($e): array => [
            'label' => $e->label,
            'mass_kg' => $e->mass_kg,
            'non_lifting_kg' => $e->non_lifting_kg,
        ])->all();

    $auflagen = $weighing->entriesOf('support')
        ->map(fn ($e): array => [
            'label' => $e->label,
            'gross_kg' => $e->gross_kg,
            'tare_kg' => $e->tare_kg,
            'arm_mm' => $e->arm_mm,
        ])->all();

    $kopf = [
        'order_reference' => $weighing->order_reference,
        'datum_reference' => $weighing->datum_reference,
        'reference_line' => $weighing->reference_line,
        'front_support_arm_mm' => $weighing->front_support_arm_mm,
        'support_distance_mm' => $weighing->support_distance_mm,
        'max_mass_kg' => $weighing->max_mass_kg,
        'max_mass_water_kg' => $weighing->max_mass_water_kg,
        'max_non_lifting_kg' => $weighing->max_non_lifting_kg,
        'cockpit_load_min_kg' => $weighing->cockpit_load_min_kg,
        'cockpit_load_max_kg' => $weighing->cockpit_load_max_kg,
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
        /* Hochformat -- steht NACH dem Include, weil _sheet Querformat setzt
           und die spätere Regel gewinnt. */
        @page { size: A4 portrait; margin: 12mm 10mm; }
    </style>
    @include('fleet.sheet._styles')
</head>
<body>

@include('fleet.sheet._glider', ['editable' => false])

</body>
</html>
