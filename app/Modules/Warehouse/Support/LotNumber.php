<?php

declare(strict_types=1);

namespace App\Modules\Warehouse\Support;

use App\Modules\Warehouse\Models\StockLot;
use Illuminate\Support\Carbon;

/**
 * Woher eine Losnummer kommt.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * Vorgabe: „Als losnummer hätte ich gerne, soweit vorhanden, die Nummer vom
 * Form 1. Wenn nicht müssen wir eine andere nehmen."
 *
 * Das ist der Griff nach dem Nummernkreis, den es schon gibt. Wer im Regal
 * steht und wissen will, welches Papier zu diesem Teil gehört, liest die
 * Nummer ab und findet sie auf dem Dokument wieder -- ohne Umweg über eine
 * zweite, hausgemachte Nummer, die nur dieses System kennt.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * DREI GRÜNDE, WARUM DAS NICHT EINFACH `= document_reference` IST:
 *
 *  1. EIN FORM 1 KANN MEHRERE LOSE DECKEN. Die Blöcke 6 bis 12 des Vordrucks
 *     sind eine TABELLE -- ein Zertifikat kann mehrere Positionen tragen, und
 *     jede davon wird hier ein eigenes Los. Die Losnummer ist aber eindeutig
 *     (`unique` in der Tabelle), weil sie auf dem Aufkleber steht und ein
 *     Regal nicht zweimal dasselbe Schild vertragen kann. Deshalb hängt bei
 *     der zweiten Position ein `-2` an, bei der dritten ein `-3`.
 *
 *  2. FORM-1-NUMMERN SIND NUR BEIM AUSSTELLER EINDEUTIG. Zwei Betriebe dürfen
 *     dieselbe schlichte Nummer vergeben ("12345"), und irgendwann treffen sich
 *     die zwei im selben Lager. Derselbe Zähler löst auch das.
 *
 *  3. SIE PASST NICHT IMMER. `document_reference` hält 128 Zeichen, die
 *     Losnummer 32 -- sie muss auf ein Etikett passen und von Hand abschreibbar
 *     sein. Was zu lang ist, wird gekürzt UND bekommt den Zähler, damit aus
 *     zwei verschiedenen langen Nummern nicht dieselbe kurze wird.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * WAS BEWUSST NICHT PASSIERT: NACHTRÄGLICHES UMNUMMERIEREN.
 *
 * Kommt die Ware vor dem Papier (F28 -- theoretisch, gebaut ist es
 * trotzdem), bekommt das Los eine erzeugte Nummer. Trägt jemand später das
 * Form 1 nach, BLEIBT sie. Eine Nummer, die sich ändert, ist keine Nummer: Sie
 * steht dann schon auf einem Aufkleber, in Bewegungen und womöglich in einer
 * Freigabe. Die Form-1-Nummer ist über `document_reference` ohnehin durchsuchbar
 * -- beide Wege führen zum selben Los.
 * ─────────────────────────────────────────────────────────────────────────────
 */
final class LotNumber
{
    /** Passend zur Spaltenbreite von stock_lots.lot_number. */
    private const MAX = 32;

    /**
     * Die Nummer für ein neues Los.
     *
     * Aufzurufen INNERHALB der umgebenden Transaktion -- die Prüfung auf
     * Vergebenes und das Einfügen dürfen nicht auseinanderfallen.
     */
    public static function forNewLot(string $receivedAt, ?string $documentReference): string
    {
        $ausDemPapier = self::fromDocument($documentReference);

        return $ausDemPapier ?? self::sequential($receivedAt);
    }

    /**
     * Aus der Form-1-Nummer, falls brauchbar.
     */
    private static function fromDocument(?string $reference): ?string
    {
        $roh = trim((string) $reference);

        if ($roh === '') {
            return null;
        }

        /*
         * Zeichen, die auf einem Etikett oder in einer Dateiablage Ärger machen,
         * fallen weg. Nicht "alles außer A-Z0-9": Form-1-Nummern tragen oft
         * Schrägstriche und Punkte, und die wegzuwerfen machte aus "24/0815"
         * und "240815" dieselbe Nummer.
         */
        $sauber = trim((string) preg_replace('/[^\p{L}\p{N}\-\/\.\s]+/u', '', $roh));
        $sauber = (string) preg_replace('/\s+/', ' ', $sauber);

        if ($sauber === '') {
            return null;
        }

        // Platz für den Zähler freihalten, siehe unten.
        $basis = mb_substr($sauber, 0, self::MAX - 4);

        return self::unique($basis);
    }

    /**
     * Der Rückfall: YYYYMM-NNN, monatlich neu beginnend.
     *
     * Dasselbe Muster wie beim Sperrzettel (F34) -- ein Nummernkreis, den man
     * schon kennt, ist einer weniger zum Erklären.
     */
    private static function sequential(string $receivedAt): string
    {
        $prefix = Carbon::parse($receivedAt)->format('Ym');

        $letzte = StockLot::withTrashed()
            ->where('lot_number', 'like', $prefix.'-%')
            // Nur die eigene Form zählen: Eine Form-1-Nummer, die zufällig mit
            // "202608-" beginnt, darf den Zähler nicht verstellen.
            ->where('lot_number', 'regexp', '^[0-9]{6}-[0-9]{3}$')
            ->lockForUpdate()
            ->orderByDesc('lot_number')
            ->value('lot_number');

        $naechste = $letzte === null ? 1 : ((int) mb_substr((string) $letzte, -3)) + 1;

        return sprintf('%s-%03d', $prefix, $naechste);
    }

    /**
     * Hängt einen Zähler an, solange die Nummer schon vergeben ist.
     *
     * `withTrashed`, weil eine gelöschte Zeile die Nummer weiter belegt -- sie
     * steht in Bewegungen und womöglich in einer Freigabe, und zweimal dieselbe
     * Nummer in derselben Akte wäre schlimmer als eine Lücke.
     */
    private static function unique(string $basis): string
    {
        $kandidat = $basis;
        $zaehler = 1;

        while (StockLot::withTrashed()->where('lot_number', $kandidat)->lockForUpdate()->exists()) {
            $zaehler++;
            $kandidat = $basis.'-'.$zaehler;

            // Reissleine: Bei einem Form 1 mit über 99 Positionen ist etwas
            // anderes im Argen. Dann lieber der erzeugte Nummernkreis als eine
            // Endlosschleife im Wareneingang.
            if ($zaehler > 99) {
                return self::sequential(now()->toDateString());
            }
        }

        return $kandidat;
    }
}
