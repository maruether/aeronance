<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <title>{{ __('warehouse.tag.sheet_title') }}</title>
    @include('warehouse.tags._styles')
</head>
<body>

<div class="noprint">
    <strong>{{ __('warehouse.tag.print_hint_title') }}</strong>
    — {{ __('warehouse.tag.variant.'.($variant ?? 'sheet')) }}<br>
    {{ __('warehouse.tag.print_hint') }}
    @if (($variant ?? 'sheet') === 'label')
        <br>{{ __('warehouse.tag.label_hint') }}
    @endif
    @if (count($skip) > 0)
        <br>{{ __('warehouse.tag.skipped', ['positions' => implode(', ', $skip)]) }}
    @endif
</div>

@php
    $perSheet = $layout['columns'] * $layout['rows'];

    // Belegte Positionen ueberspringen: sonst kostet ein einzelner Zettel
    // einen ganzen Bogen. Der angebrochene Bogen wird wieder eingelegt.
    $slots = [];
    for ($position = 1; $position <= $perSheet; $position++) {
        if (! in_array($position, $skip, true)) {
            $slots[] = $position;
        }
    }

    $chunks = array_chunk($tags->all(), max(1, count($slots)));
@endphp

@forelse ($chunks as $chunk)
    <div class="sheet">
        @foreach ($chunk as $index => $tag)
            @php
                $position = $slots[$index] ?? null;
                if ($position === null) { continue; }

                $column = ($position - 1) % $layout['columns'];
                $row    = intdiv($position - 1, $layout['columns']);

                $left = $layout['margin_left'] + $column * ($layout['tag_width'] + $layout['gap_x']);
                $top  = $layout['margin_top']  + $row    * ($layout['tag_height'] + $layout['gap_y']);
            @endphp

            @include('warehouse.tags._tag', ['tag' => $tag, 'left' => $left, 'top' => $top])
        @endforeach
    </div>
@empty
    <div class="noprint">{{ __('warehouse.tag.none') }}</div>
@endforelse

</body>
</html>
