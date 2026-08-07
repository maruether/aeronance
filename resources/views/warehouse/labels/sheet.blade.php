<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <title>{{ __('warehouse.label.title') }}</title>
    @include('warehouse.labels._styles')
</head>
<body>

<div class="noprint">
    <strong>{{ __('warehouse.label.print_hint_title') }}</strong>
    — {{ __('warehouse.label.variant.'.$variant) }}<br>
    {{ __('warehouse.label.print_hint') }}
    @if ($variant === 'roll')
        <br>{{ __('warehouse.label.roll_hint') }}
    @endif
    @if (count($skip) > 0)
        <br>{{ __('warehouse.label.skipped', ['positions' => implode(', ', $skip)]) }}
    @endif
    {{-- Der Weg zum Kalibrierbogen steht HIER, weil man ihn genau dann sucht:
         wenn man vor dem Drucker steht und die Etiketten nicht passen. --}}
    <br><a href="{{ route('warehouse.label.calibration', ['layout' => $variant]) }}"
           target="_blank">{{ __('warehouse.label.calibration_link') }}</a>
</div>

@php
    /*
     * EINE RECHNUNG FUER BEIDE BETRIEBSARTEN.
     *
     * Die Rolle ist in dieser Sicht nichts Eigenes, sondern ein Raster mit
     * einer Spalte und einer Zeile: Die Seite IST das Etikett. Damit gibt es
     * keinen zweiten Codepfad, der auseinanderlaufen kann -- und wer die Rolle
     * auf zwei Etiketten nebeneinander umstellen will (es gibt Drucker, die
     * das koennen), aendert eine Zahl in der Konfiguration.
     */
    $perSheet = max(1, $layout['columns'] * $layout['rows']);

    // Belegte Positionen ueberspringen -- beim A4-Bogen kostet ein einzelnes
    // Etikett sonst einen ganzen Bogen. Bei der Rolle gibt es nichts zu
    // ueberspringen, dort ist die Liste leer.
    $slots = [];
    for ($position = 1; $position <= $perSheet; $position++) {
        if (! in_array($position, $skip, true)) {
            $slots[] = $position;
        }
    }

    $chunks = count($slots) > 0 ? array_chunk($lots->all(), count($slots)) : [];
@endphp

@forelse ($chunks as $chunk)
    <div class="sheet">
        @foreach ($chunk as $index => $lot)
            @php
                $position = $slots[$index] ?? null;
            @endphp

            @if ($position !== null)
                @php
                    $column = ($position - 1) % $layout['columns'];
                    $row    = intdiv($position - 1, $layout['columns']);

                    $left = $layout['margin_left'] + $column * ($layout['label_width'] + $layout['gap_x']);
                    $top  = $layout['margin_top']  + $row    * ($layout['label_height'] + $layout['gap_y']);
                @endphp

                @include('warehouse.labels._label', ['lot' => $lot, 'left' => $left, 'top' => $top, 'withQr' => $withQr])
            @endif
        @endforeach
    </div>
@empty
    <div class="noprint">{{ __('warehouse.label.none') }}</div>
@endforelse

</body>
</html>
