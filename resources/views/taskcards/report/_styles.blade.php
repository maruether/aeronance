{{--
    Das Aussehen des Befundberichts — für Bildschirm und Papier dasselbe.

    Farben durchgehend über currentColor: Am Bildschirm läuft das Panel auch
    dunkel, und ein Blatt in Schwarz auf Dunkel wäre da und unlesbar. Auf Papier
    ist currentColor schwarz, und alles bleibt, wie es gedruckt gehört.
--}}
<style>
    .sheet-head { display: flex; justify-content: space-between; align-items: baseline; margin-bottom: 2mm; }
    .sheet-title { font-size: 13pt; font-weight: 700; }
    .sheet-org { font-size: 8pt; text-align: right; opacity: .8; }

    table { width: 100%; border-collapse: collapse; }
    table.ident { margin-bottom: 2mm; }
    table.ident td { border: 0.3mm solid currentColor; padding: 1mm 1.5mm; font-size: 8.5pt; }
    table.ident b { font-weight: 700; margin-right: 1mm; }

    table.points th, table.points td {
        border: 0.3mm solid currentColor; padding: 1mm 1.5mm; vertical-align: top;
        font-size: 8.5pt;
    }
    table.points th { font-size: 7.5pt; font-weight: 700; text-align: left;
                      background: color-mix(in srgb, currentColor 8%, transparent); }
    table.points th.nr, table.points td.nr { width: 8mm; text-align: right; }
    table.points th.fix, table.points th.finding { width: auto; }
    table.points th.sig, table.points td.sig { width: 24mm; }
    table.points tr { page-break-inside: avoid; }

    .klein { font-size: 7.5pt; line-height: 1.3; }
    .zart { opacity: .7; }
    .leer { font-style: italic; opacity: .7; }
    .fod td { font-size: 8pt; }

    .box { display: inline-block; width: 3.2mm; height: 3.2mm; border: 0.3mm solid currentColor;
           vertical-align: -0.3mm; margin-right: 1mm; }
    .box.on { background: currentColor; }

    .sheet-foot { display: flex; gap: 12mm; margin-top: 8mm; font-size: 8pt; }
    .sheet-foot .block { flex: 1; }
    .block-title { font-weight: 700; margin-bottom: 6mm; }
    .sig-lines { display: flex; gap: 8mm; margin-bottom: 6mm; }
    .sig-lines .line { flex: 1; border-top: 0.3mm solid currentColor; padding-top: 1mm; }
    .sig-lines .label { font-size: 7pt; opacity: .75; }
    .release { border: 0.3mm solid currentColor; padding: 2mm; }
</style>
