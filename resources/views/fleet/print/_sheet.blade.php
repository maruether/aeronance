{{--
  Shared geometry for both aircraft sheets.

  A4 landscape with millimetre measurements, because these are forms somebody
  files in a folder next to the ones the CAMO sent -- and a sheet that prints a
  few millimetres off is a sheet that will not line up with the rest.
--}}
<style>
    @page { size: A4 landscape; margin: 10mm 8mm; }

    body {
        font-family: "DejaVu Sans", Arial, sans-serif;
        font-size: 8.5pt;
        color: #000;
        margin: 0;
    }

    .sheet-head { display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 3mm; }
    .sheet-title { font-size: 13pt; font-weight: 700; }
    .sheet-org { font-size: 8pt; text-align: right; line-height: 1.3; }

    .sheet-ident { display: flex; gap: 8mm; margin-bottom: 3mm; font-size: 9.5pt; }
    .sheet-ident b { font-weight: 700; }

    table { width: 100%; border-collapse: collapse; }
    th, td { border: 0.3mm solid #000; padding: 1mm 1.5mm; vertical-align: top; }
    th { font-size: 7.5pt; font-weight: 700; text-align: left; background: #f0f0f0; }
    td.num { text-align: right; white-space: nowrap; }
    td.tick { text-align: center; width: 8mm; }

    .box { display: inline-block; width: 3.5mm; height: 3.5mm; border: 0.3mm solid #000; }
    .box.on { background: #000; }

    .sheet-foot { margin-top: 6mm; display: flex; gap: 12mm; font-size: 8pt; }
    .sig { flex: 1; border-top: 0.3mm solid #000; padding-top: 1mm; }

    .note { margin-top: 2mm; font-size: 7pt; }

    .no-print { margin-bottom: 4mm; }
    @media print { .no-print { display: none; } }
</style>

<div class="no-print">
    <button type="button" data-print>Drucken</button><script src="/js/print-button.js"></script>
</div>
