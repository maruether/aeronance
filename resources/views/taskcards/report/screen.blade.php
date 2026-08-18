{{--
    Der Befundbericht auf der Vorgangsseite — derselbe Blattkörper wie im Druck.

    ─────────────────────────────────────────────────────────────────────────────
    AM BILDSCHIRM FOLGT ER DEM DUNKELMODUS, IM DRUCK NICHT. Dieselbe
    Entscheidung wie beim Wägeblatt und aus demselben Grund: Ein weisses A4
    mitten in einer dunklen Seite blendet genau abends in der Werkstatt; auf
    Papier ist es Papier. Möglich wird das dadurch, dass der Blattkörper
    durchgehend `currentColor` benutzt.
    ─────────────────────────────────────────────────────────────────────────────
--}}
@php
    $order = $getRecord();
    $bericht = app(\App\Modules\TaskCards\Actions\ManageFindingReport::class);
    $points = $bericht->points($order);
    $release = $order->currentRelease();
@endphp

@include('taskcards.report._styles')

<style>
    .bericht-rahmen { overflow-x: auto; }
    .bericht {
        --blatt-grund: #fff;
        --blatt-schrift: #18181b;

        min-width: 180mm; padding: 6mm;
        background: var(--blatt-grund); color: var(--blatt-schrift);
        border: 1px solid color-mix(in srgb, currentColor 20%, transparent);
        border-radius: 3px;
        font-family: ui-sans-serif, system-ui, sans-serif;
    }
    .dark .bericht {
        --blatt-grund: #1c1c1f;
        --blatt-schrift: #e7e7ea;
    }
</style>

<div class="bericht-rahmen">
    <div class="bericht">
        @include('taskcards.report._sheet')
    </div>
</div>
