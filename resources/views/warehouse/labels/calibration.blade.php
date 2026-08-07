<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <title>{{ __('warehouse.label.calibration_title') }}</title>
    @include('warehouse.labels._styles')
    <style>
        .measure { position: absolute; border: .4mm solid #000; }
        .measure span { position: absolute; bottom: .8mm; left: 1.5mm; font-size: 7pt; }

        /*
         * Das Lineal ist so lang wie das Etikett breit, hoechstens aber 100 mm.
         * Auf einer 62-mm-Rolle waere ein 100-mm-Lineal ausserhalb der Seite --
         * und ein Lineal, das man nicht sieht, misst nichts.
         */
        .ruler { position: absolute;
                 left: {{ $layout['margin_left'] }}mm;
                 top: {{ max(1.0, $layout['label_height'] * $layout['rows'] + $layout['margin_top'] + 2) }}mm;
                 width: {{ $rulerLength }}mm; height: 4mm;
                 border-left: .3mm solid #000; border-right: .3mm solid #000;
                 border-bottom: .3mm solid #000; }
        .ruler span { position: absolute; top: -4mm; font-size: 6pt; }
    </style>
</head>
<body>

<div class="noprint">
    <strong>{{ __('warehouse.label.calibration_title') }}</strong><br>
    {{ __('warehouse.label.calibration_hint', ['w' => $layout['label_width'], 'h' => $layout['label_height']]) }}
</div>

<div class="sheet">
    {{-- Ein Kasten in exakt der Etikettengroesse, dazu ein Lineal. Stimmen
         beide mit dem Massband ueberein, skaliert der Drucker nicht.

         BEI DER ROLLE IST DAS DER EIGENTLICHE PRUEFSCHRITT: Etikettendrucker
         haben einen nicht bedruckbaren Rand, und ob die 62 mm der Rolle
         wirklich 62 mm ergeben, sieht man erst auf dem Etikett. --}}
    @for ($row = 0; $row < $layout['rows']; $row++)
        @for ($column = 0; $column < $layout['columns']; $column++)
            <div class="measure"
                 style="left: {{ $layout['margin_left'] + $column * ($layout['label_width'] + $layout['gap_x']) }}mm;
                        top: {{ $layout['margin_top'] + $row * ($layout['label_height'] + $layout['gap_y']) }}mm;
                        width: {{ $layout['label_width'] }}mm; height: {{ $layout['label_height'] }}mm;">
                <span>{{ $layout['label_width'] }} × {{ $layout['label_height'] }} mm</span>
            </div>
        @endfor
    @endfor

    @if ($rulerFits)
        <div class="ruler">
            <span style="left:-1mm">0</span>
            <span style="left:{{ $rulerLength - 13 }}mm">{{ $rulerLength }}&nbsp;mm</span>
        </div>
    @endif
</div>

</body>
</html>
