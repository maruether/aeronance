<?php

declare(strict_types=1);

namespace App\Modules\Warehouse\Support;

use App\Modules\Warehouse\Models\StockLot;

/**
 * Welche Papiere an einem Los hängen können.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * Vorgabe (F5): „da sollte ne auswahlliste mit freitextfunktion rein."
 *
 * F33 hatte drei feste Werte festgelegt -- Form 1, CoC, keines. Das bleibt die
 * Auswahl; dazu kommt die Möglichkeit, ein Papier zu benennen, das es dort
 * nicht gibt. Was mit einer Lieferung ankommt, hält sich nicht an drei
 * Kategorien.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * DIE EINE GRENZE, UND SIE IST KEINE KLEINIGKEIT:
 *
 *     EIN FREITEXTTYP DARF NIEMALS ALS FORM-1-NACHWEIS DURCHGEHEN.
 *
 * `document_type === 'form_one'` steuert die Nachweislogik am Los:
 * hasRequiredDocument(), die Sperre bei fehlendem Nachweis, ob ein ausgebautes
 * Teil in ein ANDERES Luftfahrzeug darf. Ein frei eingetragenes „Form 1" oder
 * „EASA Form 1" wäre für den Menschen dasselbe Wort und für das System ein
 * anderer Wert -- und ein Los, das nach Nachweis aussieht und keinen hat, ist
 * genau der Zustand, den ML.A.504 verhindern will.
 *
 * Deshalb: Der Freitext ist immer „sonstiges Dokument mit Bezeichnung", nie ein
 * vierter gleichberechtigter Typ. Technisch getragen von einem Präfix, das
 * niemand von Hand tippen kann, ohne es zu merken -- und die Prüfung auf
 * Form 1 vergleicht weiter exakt.
 * ─────────────────────────────────────────────────────────────────────────────
 */
final class DocumentTypes
{
    /**
     * Was vor einem selbst benannten Papier steht.
     *
     * Ein Präfix und keine zweite Spalte, weil `document_type` an vielen
     * Stellen gelesen wird -- eine Spalte daneben würde irgendwo vergessen.
     * So ist jeder Wert, der nicht in der festen Liste steht, an seinem Anfang
     * als das erkennbar, was er ist.
     */
    public const OTHER_PREFIX = 'other:';

    /**
     * Die feste Auswahl.
     *
     * @return array<string, string>
     */
    public static function fixed(): array
    {
        return [
            StockLot::DOCUMENT_FORM_ONE => __('warehouse.document_type.form_one'),
            StockLot::DOCUMENT_CERTIFICATE_OF_CONFORMITY => __('warehouse.document_type.certificate_of_conformity'),
            StockLot::DOCUMENT_NONE => __('warehouse.document_type.none'),
        ];
    }

    /**
     * Die Auswahl plus alles, was in diesem Bestand schon benannt wurde.
     *
     * Einmal eingetragene Bezeichnungen tauchen wieder auf -- sonst schriebe
     * jeder sie neu und leicht anders, und aus einem Papier würden fünf.
     *
     * @return array<string, string>
     */
    public static function options(?string $current = null): array
    {
        $auswahl = self::fixed();

        foreach (self::knownCustom() as $wert) {
            $auswahl[$wert] = self::label($wert);
        }

        // Der eigene Wert dieses Datensatzes, falls er sonst nirgends steht --
        // sonst verschwaende er beim Bearbeiten aus der Liste.
        if ($current !== null && $current !== '' && ! array_key_exists($current, $auswahl)) {
            $auswahl[$current] = self::label($current);
        }

        return $auswahl;
    }

    /**
     * Aus einer Eingabe einen gespeicherten Wert machen.
     *
     * Hier sitzt der Riegel: Wer „Form 1" von Hand einträgt, bekommt
     * `other:Form 1` -- ein Papier mit dieser Aufschrift, kein Nachweis.
     */
    public static function custom(string $bezeichnung): string
    {
        $sauber = trim($bezeichnung);

        // Doppeltes Praefix, falls jemand einen gespeicherten Wert erneut
        // hineingibt.
        if (str_starts_with($sauber, self::OTHER_PREFIX)) {
            $sauber = mb_substr($sauber, mb_strlen(self::OTHER_PREFIX));
        }

        return self::OTHER_PREFIX.mb_substr(trim($sauber), 0, 26);
    }

    public static function isCustom(string $wert): bool
    {
        return str_starts_with($wert, self::OTHER_PREFIX);
    }

    /**
     * Wie ein Wert in der Oberfläche heisst.
     */
    public static function label(string $wert): string
    {
        if (self::isCustom($wert)) {
            return mb_substr($wert, mb_strlen(self::OTHER_PREFIX));
        }

        return self::fixed()[$wert] ?? $wert;
    }

    /**
     * Die selbst benannten Papiere, die in diesem Bestand vorkommen.
     *
     * @return list<string>
     */
    public static function knownCustom(): array
    {
        return StockLot::withTrashed()
            ->where('document_type', 'like', self::OTHER_PREFIX.'%')
            ->distinct()
            ->orderBy('document_type')
            ->pluck('document_type')
            ->all();
    }
}
