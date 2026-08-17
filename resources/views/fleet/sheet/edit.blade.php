{{--
    Das Wägeblatt am Bildschirm — derselbe Blattkörper wie im Ausdruck.

    Der Rahmen ist eine Filament-Seite (Navigation, Rechte, Kopfleiste); alles
    darin ist das Blatt.

    ─────────────────────────────────────────────────────────────────────────────
    AM BILDSCHIRM FOLGT ES DEM DUNKELMODUS, IM DRUCK NICHT.

    Feldtest: „in der eingabe wäre ein darkmode nett. im druck natürlich nicht."

    Zwei verschiedene Dinge, und deshalb zwei verschiedene Antworten: Am
    Bildschirm ist das Blatt eine Oberfläche und soll sich verhalten wie der
    Rest des Panels -- ein weisses A4 mitten in einer dunklen Seite blendet, und
    zwar genau abends in der Werkstatt. Auf Papier ist es Papier.

    Möglich wird das dadurch, dass der Blattkörper durchgehend `currentColor`
    benutzt: Linien, Rahmen und die grauen Zellen leiten sich aus der
    Textfarbe ab, statt fest schwarz zu sein. Umgeschaltet wird hier oben --
    eine Regel, nicht dreissig.
    ─────────────────────────────────────────────────────────────────────────────
--}}
<x-filament-panels::page>
    @include('fleet.sheet._styles')

    <style>
        /* Feste Blattbreite wie A4, damit die Spalten dort umbrechen, wo sie es
           auch auf Papier tun. Auf kleinen Schirmen scrollt es waagerecht,
           statt die Tabellen zu zerlegen -- ein umgebrochenes Wägeblatt ist
           keins mehr. */
        .blatt-rahmen { overflow-x: auto; }
        .blatt {
            --blatt-grund: #fff;
            --blatt-schrift: #18181b;
            --blatt-kopf: rgba(0, 0, 0, .05);

            width: 190mm; min-width: 190mm; margin: 0 auto; padding: 8mm;
            background: var(--blatt-grund); color: var(--blatt-schrift);
            border: 1px solid color-mix(in srgb, currentColor 20%, transparent);
            border-radius: 3px;
            font-family: ui-sans-serif, system-ui, sans-serif; font-size: 9pt;
        }

        .dark .blatt {
            --blatt-grund: #1c1c1f;
            --blatt-schrift: #e7e7ea;
            --blatt-kopf: rgba(255, 255, 255, .07);
        }

        .blatt table { width: 100%; border-collapse: collapse; }
        .blatt th, .blatt td {
            border: 1px solid color-mix(in srgb, currentColor 45%, transparent);
            padding: 1mm 1.5mm; text-align: left; font-size: 8pt; vertical-align: middle;
        }
        .blatt th { font-weight: 700; background: var(--blatt-kopf); }
        .blatt td.num, .blatt th.num { text-align: right; }
    </style>

    @if ($this->blatt()->isSignedOff())
        <x-filament::section>
            <div class="text-sm">{{ __('fleet.weighing.signed_off_note') }}</div>
        </x-filament::section>
    @endif

    <div class="blatt-rahmen">
        <div class="blatt">
            {{-- Welches Blatt, entscheidet der Rechenweg -- dieselbe Weiche
                 wie im Druck, damit Bildschirm und Papier nie verschiedene
                 Blätter zeigen. --}}
            @include($this->blatt()->kind->usesComponents()
                ? 'fleet.sheet._glider'
                : 'fleet.sheet._powered', [
                'weighing' => $this->blatt(),
                'editable' => ! $this->blatt()->isSignedOff(),
                'kopf' => $this->kopf,
                'bauteile' => $this->bauteile,
                'auflagen' => $this->auflagen,
                'abzuege' => $this->abzuege,
                'konfigurationen' => $this->konfigurationen,
            ])
        </div>
    </div>
</x-filament-panels::page>
