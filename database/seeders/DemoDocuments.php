<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Modules\Warehouse\Models\StockLot;
use Throwable;

/**
 * Die Beispieldokumente der Demo.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * Vorgabe: „keine uploads, stattdessen die info das diese wegen der demo
 * deaktiviert sind und beispieldokumente."
 *
 * Eine Demo, in der man auf „Form 1 ansehen" klickt und nichts bekommt, ist
 * eine kaputte Demo. Also legt der Seeder ein paar Dateien selbst an -- klein,
 * erzeugt statt mitgeliefert, und unübersehbar als Beispiel gekennzeichnet.
 *
 * SELBST GESCHRIEBEN UND NICHT IM REPO ABGELEGT: Ein PDF im Quellcode ist eine
 * Binärdatei, die niemand mehr liest und die bei jeder Änderung neu committet
 * wird. Diese hier entsteht aus zwanzig Zeilen und trägt genau das, was
 * draufstehen soll. Der Aufbau ist die minimale PDF-Struktur -- Katalog,
 * Seitenbaum, eine Seite, ein Textstrom -- mit von Hand gerechneter
 * Querverweistabelle; ohne die korrekten Byte-Abstände öffnet kein Betrachter
 * die Datei.
 * ─────────────────────────────────────────────────────────────────────────────
 */
final class DemoDocuments
{
    public static function attachFormOne(?StockLot $lot): void
    {
        if ($lot === null) {
            return;
        }

        $pfad = self::write('form1-beispiel.pdf', [
            'EASA Form 1 -- BEISPIELDOKUMENT',
            '',
            'Dies ist kein Nachweis. Es ist eine Datei, die in der Demo an der',
            'Stelle liegt, an der im Betrieb der Herkunftsnachweis liegt.',
            '',
            'Teil:          Schleppkupplung Tost E 85',
            'Seriennummer:  E85-DEMO-4711',
            'Bescheinigung: EASA Form 1 DEMO-2025-0042',
            '',
            'In einer Demo sind Uploads abgeschaltet. Im Betrieb haengt hier die',
            'eingescannte Bescheinigung des Lieferanten.',
        ]);

        try {
            $lot->addMedia($pfad)
                ->usingFileName('form1-beispiel.pdf')
                ->toMediaCollection(StockLot::DOCUMENTS);
        } catch (Throwable) {
            // Ein fehlendes Beispieldokument ist kein Grund, die Einrichtung
            // der Demo scheitern zu lassen.
        }
    }

    /**
     * Schreibt ein einseitiges PDF in eine temporäre Datei und gibt den Pfad
     * zurück. Der Aufrufer verschiebt es in die Medienablage.
     *
     * @param  list<string>  $zeilen
     */
    private static function write(string $name, array $zeilen): string
    {
        $text = '';
        $y = 780;

        foreach ($zeilen as $zeile) {
            // Klammern und Rueckstriche sind im PDF-Textstrom Steuerzeichen.
            $sicher = str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $zeile);
            $text .= sprintf("BT /F1 11 Tf 56 %d Td (%s) Tj ET\n", $y, $sicher);
            $y -= 16;
        }

        $objekte = [
            '<< /Type /Catalog /Pages 2 0 R >>',
            '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
            '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] '
                .'/Resources << /Font << /F1 4 0 R >> >> /Contents 5 0 R >>',
            '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>',
            '<< /Length '.strlen($text)." >>\nstream\n".$text.'endstream',
        ];

        $pdf = "%PDF-1.4\n";
        $abstaende = [];

        foreach ($objekte as $i => $objekt) {
            $abstaende[] = strlen($pdf);
            $pdf .= ($i + 1)." 0 obj\n".$objekt."\nendobj\n";
        }

        $xref = strlen($pdf);
        $pdf .= "xref\n0 ".(count($objekte) + 1)."\n0000000000 65535 f \n";

        foreach ($abstaende as $abstand) {
            $pdf .= sprintf("%010d 00000 n \n", $abstand);
        }

        $pdf .= "trailer\n<< /Size ".(count($objekte) + 1)." /Root 1 0 R >>\n"
            ."startxref\n".$xref."\n%%EOF\n";

        $pfad = sys_get_temp_dir().'/'.$name;

        file_put_contents($pfad, $pdf);

        return $pfad;
    }
}
