<?php

declare(strict_types=1);

namespace App\Modules\Tooling;

/**
 * Wer darf was mit dem Werkzeugbestand.
 *
 * Die Trennung folgt wieder den zwei verschiedenen Handlungen: Werkzeuge
 * verwalten ist Buchhaltung, die Bewertung einer Kalibrierlücke ist eine
 * Aussage darüber, ob damit ausgeführte Arbeit in Ordnung war.
 */
final class Permissions
{
    /** Den Bestand und die Kalibrierhistorie sehen. */
    public const TOOLS_VIEW = 'tools.view';

    /** Werkzeuge anlegen, ändern, Kalibrierscheine eintragen. */
    public const TOOLS_MANAGE = 'tools.manage';

    /**
     * Werkzeug ausgeben und zurücknehmen.
     *
     * Eigene Berechtigung und ausdrücklich NICHT an TOOLS_MANAGE gebunden: Ein
     * Werkzeug zu nehmen ist alltägliche Arbeit, den Bestand zu pflegen ist
     * Verwaltung. Wer beides koppelt, gibt entweder allen Stammdatenrechte oder
     * lässt die Ausgabeliste leer laufen, weil sie zu umständlich ist.
     */
    public const TOOLS_ISSUE = 'tools.issue';

    /**
     * Eine Kalibrierlücke bewerten.
     *
     * „Mit dem Schlüssel wurde in den vier Monaten nichts Kritisches gemacht"
     * ist eine fachliche Aussage, keine Bürotätigkeit.
     */
    public const TOOLS_ASSESS = 'tools.assess';
}
