{{--
    Der Befundbericht als Papier — für die Akte des Luftfahrzeugs.

    Nur Rahmen: Seitenformat, Blatt-CSS, und der Blattkörper aus
    taskcards.report._sheet. Denselben Körper zeigt die Vorgangsseite --
    deshalb sehen Bildschirm und Ausdruck gleich aus, und zwar nicht, weil
    jemand aufpasst, sondern weil es dieselbe Datei ist.

    Hochformat, anders als das Wägeblatt: Die sechs Spalten sind schmal, die
    Zeilen viele. Ein Bericht mit dreissig Punkten soll untereinander weglaufen
    und nicht quer.
--}}
<!doctype html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <title>{{ __('taskcards.finding_report.title') }} {{ $order->number }} — {{ $order->aircraft?->registration }}</title>
    <style>
        @page { size: A4 portrait; margin: 12mm 10mm; }
        body {
            font-family: "DejaVu Sans", Arial, sans-serif;
            font-size: 8.5pt; color: #000; margin: 0;
        }
        .no-print { margin-bottom: 4mm; }
        @media print { .no-print { display: none; } }
    </style>
    @include('taskcards.report._styles')
</head>
<body>

<div class="no-print">
    <button type="button" data-print>{{ __('fleet.print.label') }}</button>
    <script src="/js/print-button.js"></script>
</div>

@include('taskcards.report._sheet')

</body>
</html>
