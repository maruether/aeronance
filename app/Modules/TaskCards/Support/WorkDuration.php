<?php

declare(strict_types=1);

namespace App\Modules\TaskCards\Support;

/**
 * Arbeitszeit, wie Menschen sie schreiben.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * Feldtest: "Zeiteintragungen muessen als hh:mm nicht als minuten moeglich
 * sein, das formular sollte 90min automatisch direkt in 1:30h aendern sobald
 * das feld den fokus verliert." Auf dem Zettel in der Werkstatt steht "1:45",
 * nicht "105" -- und wer im Kopf umrechnet, verrechnet sich freitags um zwei.
 *
 * GESPEICHERT WIRD WEITER IN MINUTEN (die Part-66-Auswertung rechnet damit);
 * diese Klasse ist nur die Uebersetzung an der Kante: Sie liest beides
 * ("90" und "1:30") und schreibt immer die eine Anzeige ("1:30").
 * ─────────────────────────────────────────────────────────────────────────────
 */
final class WorkDuration
{
    /**
     * Minuten aus einer Eingabe -- oder null, wenn sie keine Zeit ist.
     *
     * Zwei Schreibweisen, mehr nicht: nackte Minuten ("90") und Stunden mit
     * Doppelpunkt ("1:30"). "1:75" ist keine Zeit, "1,5" auch nicht -- lieber
     * eine klare Ablehnung als eine stille Fehldeutung um Faktor sechzig.
     */
    public static function parse(?string $eingabe): ?int
    {
        $eingabe = trim((string) $eingabe);

        if ($eingabe === '') {
            return null;
        }

        if (preg_match('/^\d+$/', $eingabe) === 1) {
            $minuten = (int) $eingabe;

            return $minuten > 0 ? $minuten : null;
        }

        if (preg_match('/^(\d+):([0-5]\d)$/', $eingabe, $teile) === 1) {
            $minuten = ((int) $teile[1]) * 60 + (int) $teile[2];

            return $minuten > 0 ? $minuten : null;
        }

        return null;
    }

    /** Immer dieselbe Anzeige: "1:30", "0:45". */
    public static function format(int $minuten): string
    {
        return sprintf('%d:%02d', intdiv($minuten, 60), $minuten % 60);
    }

    /**
     * Die Eingabe in die Anzeige umschreiben -- was nicht lesbar ist, bleibt
     * stehen, damit die Validierung es benennen kann statt es zu schlucken.
     */
    public static function normalise(?string $eingabe): ?string
    {
        $minuten = self::parse($eingabe);

        return $minuten !== null ? self::format($minuten) : $eingabe;
    }
}
