<style>
    /*
     * Millimetergeometrie, wie bei den Sperrzetteln. @page mit Groesse und Rand
     * null verhindert, dass der Browser eigene Raender addiert; „an Seite
     * anpassen" muss der Nutzer trotzdem abschalten -- dafuer gibt es den
     * Kalibrierbogen.
     *
     * BEI DER ROLLE IST DIE SEITE DAS ETIKETT. Ein Brother QL, ein Zebra oder
     * ein Dymo bekommen keine A4-Seite mit einem Etikett obendrauf, sondern
     * eine Seite in Etikettengroesse. Deshalb steht hier die Groesse aus der
     * Konfiguration und nicht „A4".
     */
    @page { size: {{ $layout['width'] }}mm {{ $layout['height'] }}mm; margin: 0; }

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

    /* Die letzte Seite darf keinen Umbruch anhaengen -- sonst wirft ein
       Etikettendrucker ein leeres Etikett aus, und bei einer Rolle ist das
       nicht bloss Papier, sondern das naechste Etikett. */
    .sheet:last-of-type { page-break-after: auto; }

    .label {
        position: absolute;
        width: {{ $layout['label_width'] }}mm;
        height: {{ $layout['label_height'] }}mm;
        overflow: hidden;
        padding: 1.5mm 2mm;
        font-size: 6.5pt;
        line-height: 1.2;
        display: flex;
        flex-direction: row;
        gap: 1.5mm;
    }

    /* Der Text nimmt, was uebrig bleibt -- der Code hat eine feste Groesse,
       weil eine Telefonkamera unter etwa 16 mm nicht mehr zuverlaessig liest. */
    .label .text { flex: 1 1 auto; min-width: 0; display: flex; flex-direction: column; }

    .label .qr {
        flex: 0 0 auto;
        width: {{ $qrSize }}mm;
        height: {{ $qrSize }}mm;
        align-self: flex-start;
    }
    .label .qr svg { width: 100%; height: 100%; display: block; }

    /*
     * Die Losnummer ist die Zeile, wegen der das Etikett existiert: Sie fuehrt
     * zum Datensatz und zum Papier. Deshalb steht sie oben, gross und
     * einzeilig -- und wird lieber beschnitten als umgebrochen, weil eine
     * halbe Nummer in der zweiten Zeile schlechter zu lesen ist als eine
     * sichtbar abgeschnittene.
     */
    .label .lot {
        font-size: 11pt;
        font-weight: bold;
        letter-spacing: .1mm;
        white-space: nowrap;
        overflow: hidden;
        line-height: 1.1;
    }

    .label .part {
        font-weight: 600;
        font-size: 7.5pt;
        margin-top: .4mm;
        overflow: hidden;
    }

    .label .pn { color: #333; font-size: 6.5pt; }

    .label dl {
        margin: .8mm 0 0;
        display: grid;
        grid-template-columns: auto 1fr;
        gap: .2mm 1.5mm;
        flex: 1 1 auto;
        align-content: start;
        overflow: hidden;
    }
    .label dt { color: #555; white-space: nowrap; }
    .label dd { margin: 0; font-weight: 600; overflow: hidden; white-space: nowrap; }

    /*
     * Das Verfallsdatum ist die einzige Angabe, die jemand AM REGAL pruefen
     * muss, ohne etwas nachzuschlagen. Deshalb bekommt es einen Rahmen und
     * steht unten, wo der Blick beim Ablesen endet -- und nur dann, wenn es
     * eines gibt: ein leerer Kasten "Verfall: —" ist eine Einladung, ihn zu
     * ueberlesen.
     */
    .label .expiry {
        margin-top: .8mm;
        border: .3mm solid #000;
        padding: .4mm 1mm;
        font-size: 7.5pt;
        font-weight: bold;
        text-align: center;
        white-space: nowrap;
    }

    .cutmark { position: absolute; border: .2mm dashed #bbb; }

    @media screen {
        body { background: #e9ecef; padding: 10mm 0; }
        .sheet { background: #fff; margin: 0 auto 6mm; box-shadow: 0 0 6px rgba(0,0,0,.25); }
        .label { outline: .2mm dashed #ccc; outline-offset: -.1mm; }
        .noprint { max-width: max({{ $layout['width'] }}mm, 120mm); margin: 0 auto 6mm;
                   font: 13px/1.5 system-ui, sans-serif; color: #333; }
        .noprint code { background: #fff; padding: 1px 4px; border-radius: 3px; }
    }
    @media print {
        .noprint { display: none; }
        .label { outline: none; }
    }
</style>
