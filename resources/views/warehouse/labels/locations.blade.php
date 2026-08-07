<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <title>{{ __('warehouse.label.location_title') }}</title>
    @include('warehouse.labels._styles')
    <style>
        /*
         * Das Regalschild ist keine Datenzeile, sondern ein SCHILD: Der Name
         * muss aus ein paar Metern lesbar sein, alles andere ist Beiwerk.
         * Deshalb hier eigene Groessen statt der des Losaufklebers.
         */
        .label .place { font-size: 16pt; font-weight: bold; line-height: 1.05;
                        overflow: hidden; }
        .label .note  { font-size: 6.5pt; color: #444; margin-top: .8mm;
                        overflow: hidden; }
        .label .hint  { margin-top: auto; font-size: 5.5pt; color: #666;
                        border-top: .2mm solid #999; padding-top: .5mm; }
    </style>
</head>
<body>

<div class="noprint">
    <strong>{{ __('warehouse.label.print_hint_title') }}</strong>
    — {{ __('warehouse.label.variant.'.$variant) }}<br>
    {{ __('warehouse.label.print_hint') }}
    @if ($variant === 'roll')
        <br>{{ __('warehouse.label.roll_hint') }}
    @endif
    <br><a href="{{ route('warehouse.label.calibration', ['layout' => $variant]) }}"
           target="_blank">{{ __('warehouse.label.calibration_link') }}</a>
</div>

@php
    $perSheet = max(1, $layout['columns'] * $layout['rows']);

    $slots = [];
    for ($position = 1; $position <= $perSheet; $position++) {
        if (! in_array($position, $skip, true)) {
            $slots[] = $position;
        }
    }

    $chunks = count($slots) > 0 ? array_chunk($locations->all(), count($slots)) : [];
@endphp

@forelse ($chunks as $chunk)
    <div class="sheet">
        @foreach ($chunk as $index => $location)
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

                <div class="label" style="left: {{ $left }}mm; top: {{ $top }}mm;">
                    <div class="text">
                        <div class="place">{{ Str::limit($location->name, 26) }}</div>

                        @if (filled($location->description))
                            <div class="note">{{ Str::limit($location->description, 46) }}</div>
                        @endif

                        {{-- Sagt, wozu der Code da ist. Ohne diesen Satz haelt ihn
                             jemand fuer eine Adresse und haelt die Kamera-App
                             davor -- die dann nichts Sinnvolles anzeigt. --}}
                        @if ($withQr)
                            <div class="hint">{{ __('warehouse.label.scan_hint') }}</div>
                        @endif
                    </div>

                    @if ($withQr)
                        <div class="qr">
                            {!! \App\Modules\Warehouse\Support\QrSvg::render(
                                \App\Modules\Warehouse\Support\ScanCode::forLocation($location->id)
                            ) !!}
                        </div>
                    @endif
                </div>
            @endif
        @endforeach
    </div>
@empty
    <div class="noprint">{{ __('warehouse.label.no_locations') }}</div>
@endforelse

</body>
</html>
