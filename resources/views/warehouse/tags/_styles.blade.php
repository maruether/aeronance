<style>
    /*
     * Millimetergeometrie. @page mit Groesse und Rand null verhindert, dass der
     * Browser eigene Raender addiert; „an Seite anpassen" muss der Nutzer
     * trotzdem abschalten -- dafuer gibt es den Kalibrierbogen.
     */
    @page { size: A4 portrait; margin: 0; }

    * { box-sizing: border-box; }

    html, body {
        margin: 0; padding: 0;
        font-family: "DejaVu Sans", Arial, Helvetica, sans-serif;
        -webkit-print-color-adjust: exact;
        print-color-adjust: exact;
    }

    .sheet {
        position: relative;
        width: {{ $layout['width'] }}mm;
        height: {{ $layout['height'] }}mm;
        page-break-after: always;
        overflow: hidden;
    }

    .tag {
        position: absolute;
        width: {{ $layout['tag_width'] }}mm;
        height: {{ $layout['tag_height'] }}mm;
        overflow: hidden;
        font-size: 7pt;
        line-height: 1.25;
    }

    /*
     * Der Kopf -- die Seite mit dem Loch, an der der Anhaenger haengt, und bei
     * T2002-10 die abgeschraegte. Eingefaerbt statt eines Bandes oben, weil man
     * genau diesen Teil sieht, wenn mehrere Anhaenger im Regal uebereinander
     * liegen oder gebuendelt an einem Haken haengen.
     *
     * Die Farbe kommt aus dem Datensatz, nicht von der Person am Drucker.
     */
    .tag .head {
        position: absolute; left: 0; top: 0; bottom: 0;
        width: {{ $layout['head_width'] }}mm;
        color: #fff;
        display: flex; flex-direction: column;
        align-items: center; justify-content: center;
        text-align: center;
        padding: 1mm .5mm;
        /* Schraege wie die gestanzte Ecke, damit Druck und Stanzung
           zusammenpassen statt einander zu widersprechen. */
        clip-path: polygon(0 4mm, 4mm 0, 100% 0, 100% 100%, 4mm 100%, 0 calc(100% - 4mm));
    }
    .tag .head .no    { font-size: 7.5pt; font-weight: bold; letter-spacing: .1mm; }
    .tag .head .state { font-size: 5.5pt; text-transform: uppercase; letter-spacing: .2mm;
                        margin-top: .8mm; line-height: 1.1; }

    .tag .body {
        position: absolute;
        left: {{ $layout['head_width'] + 2.5 }}mm; right: 2.5mm; top: 2.5mm; bottom: 2.5mm;
    }

    .tag dl { margin: 0; display: grid; grid-template-columns: 17mm 1fr; gap: .3mm 1mm; }
    .tag dt { color: #555; }
    .tag dd { margin: 0; font-weight: 600; overflow: hidden; }

    .tag .sign {
        position: absolute; left: 0; right: 0; bottom: 0;
        border-top: .3mm solid #000;
        padding-top: .6mm;
        font-size: 5.5pt;
        color: #555;
    }

    .cutmark { position: absolute; border: .2mm dashed #bbb; }

    @media screen {
        body { background: #e9ecef; padding: 10mm 0; }
        .sheet { background: #fff; margin: 0 auto 10mm; box-shadow: 0 0 6px rgba(0,0,0,.25); }
        .noprint { max-width: {{ $layout['width'] }}mm; margin: 0 auto 6mm;
                   font: 13px/1.5 system-ui, sans-serif; color: #333; }
        .noprint code { background: #fff; padding: 1px 4px; border-radius: 3px; }
    }
    @media print { .noprint { display: none; } }
</style>
