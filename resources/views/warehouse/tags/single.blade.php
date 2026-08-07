<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <title>{{ __('warehouse.tag.single_title', ['tag' => $tag->quarantine_tag]) }}</title>
    @include('warehouse.tags._styles')
</head>
<body>

<div class="noprint">
    <strong>{{ __('warehouse.tag.print_hint_title') }}</strong><br>
    {{ __('warehouse.tag.single_hint') }}
</div>

{{-- Einzeln, oben links mit Schnittmarken: fuer Blankokarton in Rot, Weiss
     oder Gruen, der von Hand zugeschnitten wird. --}}
<div class="sheet">
    <div class="cutmark"
         style="left: {{ $layout['margin_left'] - 1 }}mm; top: {{ $layout['margin_top'] - 1 }}mm;
                width: {{ $layout['tag_width'] + 2 }}mm; height: {{ $layout['tag_height'] + 2 }}mm;"></div>

    @include('warehouse.tags._tag', [
        'tag'  => $tag,
        'left' => $layout['margin_left'],
        'top'  => $layout['margin_top'],
    ])
</div>

</body>
</html>
