<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <title>{{ __('warehouse.tag.calibration_title') }}</title>
    @include('warehouse.tags._styles')
    <style>
        .measure { position: absolute; border: .4mm solid #000; }
        .measure span { position: absolute; bottom: 1mm; left: 2mm; font-size: 8pt; }
        .ruler { position: absolute; left: {{ $layout['margin_left'] }}mm; top: 10mm;
                 width: 100mm; height: 6mm; border-left: .3mm solid #000;
                 border-right: .3mm solid #000; border-bottom: .3mm solid #000; }
        .ruler span { position: absolute; top: -5mm; font-size: 7pt; }
        .note { position: absolute; left: {{ $layout['margin_left'] }}mm;
                top: {{ $layout['margin_top'] + $layout['tag_height'] * $layout['rows'] + 6 }}mm;
                width: 180mm; font-size: 9pt; line-height: 1.5; }
    </style>
</head>
<body>

<div class="noprint">
    <strong>{{ __('warehouse.tag.calibration_title') }}</strong><br>
    {{ __('warehouse.tag.calibration_hint') }}
</div>

<div class="sheet">
    {{-- Ein Lineal und ein Kasten in exakt der Etikettengroesse. Stimmen beide
         mit dem Massband ueberein, skaliert der Drucker nicht. --}}
    <div class="ruler"><span style="left:-1mm">0</span><span style="left:49mm">50</span><span style="left:97mm">100&nbsp;mm</span></div>

    @for ($row = 0; $row < $layout['rows']; $row++)
        @for ($column = 0; $column < $layout['columns']; $column++)
            <div class="measure"
                 style="left: {{ $layout['margin_left'] + $column * ($layout['tag_width'] + $layout['gap_x']) }}mm;
                        top: {{ $layout['margin_top'] + $row * ($layout['tag_height'] + $layout['gap_y']) }}mm;
                        width: {{ $layout['tag_width'] }}mm; height: {{ $layout['tag_height'] }}mm;">
                <span>{{ $row * $layout['columns'] + $column + 1 }}</span>
            </div>
        @endfor
    @endfor

    <div class="note">
        {!! __('warehouse.tag.calibration_note', [
            'template' => $template,
            'w' => $layout['tag_width'],
            'h' => $layout['tag_height'],
        ]) !!}
    </div>
</div>

</body>
</html>
