<?php

declare(strict_types=1);

namespace App\Modules\Warehouse\Support;

/**
 * Was in einem QR-Code auf einem Etikett steht — und warum keine Adresse.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * Vorgabe: „warum eine adresse? ich dachte eher daran das aeronance selbst einen
 * scanner aufmacht und somit darin nur Infos sind die das tool braucht."
 *
 * Das ist die bessere Bauart, aus drei Gründen:
 *
 *  1. EINE ADRESSE VERRÄT DIE INSTANZ. Ein Regalschild hängt sichtbar in der
 *     Halle. Wer es fotografiert, hätte mit einer URL die Adresse der Anwendung
 *     — an einem Ort, an dem auch Gäste stehen. Ein reiner Nutzinhalt sagt
 *     einem Fremden nichts.
 *
 *  2. ETIKETTEN ÜBERLEBEN DOMAINS. Ein Aufkleber klebt Jahre. Zieht der Verein
 *     auf eine andere Adresse um, zeigten alle gedruckten Etiketten ins Leere.
 *     Der Nutzinhalt bleibt gültig, solange es das Los gibt.
 *
 *  3. DER SCANNER GEHÖRT INS WERKZEUG. Wer in der Anwendung scannt, ist schon
 *     angemeldet und landet dort, wo er weiterarbeiten kann. Die Kamera-App des
 *     Telefons würde nur einen Browser öffnen und nach dem Passwort fragen.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * DAS FORMAT: `AER1:L:EASA-12345`
 *
 *   AER1  Kennung samt Version. Sie ist da, damit der Scanner einen FREMDEN
 *         QR-Code als solchen erkennt und das auch sagt — ein Paketaufkleber,
 *         ein WLAN-Code, das Etikett eines anderen Systems. Ohne Kennung
 *         müsste er raten, und Raten heißt hier: falsches Los.
 *         Die Ziffer erlaubt ein späteres zweites Format, ohne dass altes
 *         Papier ungültig wird.
 *
 *   L     Was gemeint ist: L = Los, S = Lagerort (storage location).
 *
 *   Rest  Die Kennung des Datensatzes.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * WELCHE KENNUNG, und das ist je Art eine andere Antwort:
 *
 *   LOS → die LOSNUMMER. Sie ist eindeutig, sie ändert sich nie (siehe
 *         LotNumber: „Eine Nummer, die sich ändert, ist keine Nummer"), und
 *         sie steht im Klartext auf demselben Etikett. QR und Aufdruck sagen
 *         dasselbe — wer das eine liest, kann das andere prüfen.
 *
 *   ORT → die ID. Ein Lagerort hat keine fachliche Nummer, nur einen Namen,
 *         und Namen werden geändert („Regal 3" wird „Halle 2 links"). Ein
 *         umbenannter Ort dürfte sein Schild nicht verlieren. Die ID bleibt
 *         über die Lebensdauer der Installation stabil — genau so lange, wie
 *         das Schild am Regal klebt.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * KEINE PERSONENDATEN, KEINE MENGEN, KEINE PREISE. Ein Etikett hängt sichtbar
 * im Raum. Im Code steht ein Verweis und sonst nichts.
 * ─────────────────────────────────────────────────────────────────────────────
 */
final class ScanCode
{
    /** Kennung samt Formatversion. */
    public const PREFIX = 'AER1';

    public const KIND_LOT = 'L';

    public const KIND_LOCATION = 'S';

    /**
     * Der Inhalt für ein Los.
     */
    public static function forLot(string $lotNumber): string
    {
        return self::PREFIX.':'.self::KIND_LOT.':'.$lotNumber;
    }

    /**
     * Der Inhalt für einen Lagerort.
     */
    public static function forLocation(int $id): string
    {
        return self::PREFIX.':'.self::KIND_LOCATION.':'.$id;
    }

    /**
     * Einen gescannten Inhalt auseinandernehmen.
     *
     * Gibt `null` zurück, wenn der Code nicht von hier stammt — der Scanner
     * unterscheidet dann „fremder Code" von „unbekanntes Los", und das sind
     * zwei verschiedene Auskünfte an den Menschen davor.
     *
     * @return array{kind: string, reference: string}|null
     */
    public static function parse(string $raw): ?array
    {
        $raw = trim($raw);

        /*
         * Genau drei Teile, und der Rest bleibt am Stück: Eine Losnummer darf
         * einen Doppelpunkt enthalten -- sie stammt aus einem Form 1, und was
         * dort steht, bestimmt nicht dieses System.
         */
        $teile = explode(':', $raw, 3);

        if (count($teile) !== 3) {
            return null;
        }

        [$prefix, $kind, $reference] = $teile;

        if ($prefix !== self::PREFIX) {
            return null;
        }

        if (! in_array($kind, [self::KIND_LOT, self::KIND_LOCATION], true)) {
            return null;
        }

        if (trim($reference) === '') {
            return null;
        }

        return ['kind' => $kind, 'reference' => trim($reference)];
    }
}
