{{--
    Das Aussehen des Blattes — für Bildschirm und Papier dasselbe.

    Der Bildschirm bekommt hier bewusst KEIN eigenes Aussehen: Wer das Blatt
    ausfüllt, soll sehen, was er später in der Hand hält. Deshalb sind auch die
    Eingabefelder rahmenlos und sitzen bündig in ihrer Zelle — sichtbar wird
    ein Feld erst, wenn es den Fokus hat.

    Farben über currentColor statt fest Schwarz: In der Maske läuft das Panel
    auch dunkel, und ein Blatt in Schwarz auf Dunkel wäre da und unlesbar.
--}}
<style>
    .sheet-head { display: flex; justify-content: space-between; align-items: baseline; }
    .sheet-title { font-size: 13pt; font-weight: 700; }
    .sheet-orgname { font-size: 9pt; margin-bottom: 3mm; opacity: .8; }

    .cols { display: flex; gap: 4mm; align-items: flex-start; margin-bottom: 3mm; }
    .col { flex: 1; min-width: 0; }

    .grau { background: color-mix(in srgb, currentColor 12%, transparent); }
    .bar { border: 0.3mm solid currentColor; padding: 1.5mm 2mm; margin-bottom: 3mm; }
    .bar-title { font-size: 7.5pt; font-weight: 700; margin-bottom: 1mm; }
    .line { margin-bottom: 3mm; font-size: 8.5pt; }
    .centered { text-align: center; }
    .confirm { margin-top: 3mm; font-size: 8pt; line-height: 1.5; }
    .stamp { min-height: 10mm; }
    .note { font-size: 7pt; opacity: .75; margin-top: 1mm; line-height: 1.35; }
    .sheet-id { margin-top: 5mm; border-top: 0.3mm solid currentColor; padding-top: 1mm;
                font-size: 6.5pt; display: flex; justify-content: space-between; opacity: .75; }
    tr { page-break-inside: avoid; }

    /* Die Zelle als Eingabefeld: unsichtbar, bis man hineinklickt. */
    .zelle {
        width: 100%; border: 0; background: transparent; color: inherit;
        font: inherit; padding: 0.3mm 0.5mm; outline: 0;
    }
    .zelle:focus { background: color-mix(in srgb, currentColor 10%, transparent); }
    .zelle.num { text-align: right; }
    .zelle.schmal { width: 18mm; display: inline-block; }
    .zelle.weit { width: 100%; }
    textarea.zelle { resize: vertical; }

    .zeile-plus {
        border: 0.3mm dashed currentColor; background: transparent; color: inherit;
        font: inherit; font-size: 8pt; padding: 0.8mm 2mm; margin: 1mm 0 2mm;
        cursor: pointer; opacity: .8;
    }
    .zeile-plus:hover { opacity: 1; }
    @media print { .zeile-plus { display: none; } }

    /* Die Zeichnung. */
    .skizze-bild { border: 0.3mm solid currentColor; padding: 2mm; margin: 3mm 0; }
    .skizze-bild img { width: 100%; height: auto; display: block; }

    /* Strichzeichnung in Schwarz waere auf dunklem Grund da und unsichtbar --
       invertiert wird sie zu weissen Linien. Nur am Bildschirm: Papier ist
       hell, und ein invertiertes Bild im Druck waere ein schwarzer Kasten. */
    .dark .skizze-bild img { filter: invert(1); }
    @media print { .skizze-bild img { filter: none; } }
</style>
