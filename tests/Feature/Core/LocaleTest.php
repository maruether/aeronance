<?php

declare(strict_types=1);

namespace Tests\Feature\Core;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Die eingestellte Sprache muss es auch geben.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * DER FEHLER, DEN DAS FINDET, IST STUMM UND TOTAL.
 *
 * Laravel meldet eine fehlende Sprachdatei nicht — es zeigt den SCHLÜSSEL an.
 * In `config/app.php` stand als Vorgabe `en`, das Projekt liefert aber nur
 * `lang/de`. Gemessen am 2026-08-05 ergab eine Installation ohne `APP_LOCALE`:
 *
 *     users.field.name      -> users.field.name
 *     warehouse.scan.field  -> warehouse.scan.field
 *     audit.title           -> audit.title
 *
 * Also die vollständige Oberfläche in rohen Schlüsseln, ohne eine einzige
 * Fehlermeldung. Betroffen wäre jede `.env`, die die Zeile nicht hat: frisch
 * kopiert, eine Docker-Umgebung ohne die Variable, ein `config:cache` vor der
 * vollständigen Einrichtung.
 *
 * Die Rendering-Tests hätten es nie gesehen — die Seiten bauen sich ja, der
 * Text ist bloß Unsinn. Dieselbe Klasse wie die fehlenden
 * Übersetzungsschlüssel, nur systemweit.
 * ─────────────────────────────────────────────────────────────────────────────
 */
final class LocaleTest extends TestCase
{
    /**
     * Zu jeder eingestellten Sprache gibt es Sprachdateien.
     *
     * Geprüft wird die tatsächlich geladene Konfiguration — also genau das, was
     * eine Installation ohne eigene Angaben bekommt, denn phpunit.xml setzt
     * bewusst keine Sprache.
     */
    #[Test]
    public function every_configured_locale_actually_exists(): void
    {
        foreach (['app.locale', 'app.fallback_locale'] as $schluessel) {
            $sprache = (string) config($schluessel);

            $this->assertNotSame('', $sprache, $schluessel.' ist nicht gesetzt.');

            $this->assertDirectoryExists(
                lang_path($sprache),
                sprintf(
                    '%s steht auf "%s", aber lang/%s gibt es nicht. Die Oberfläche würde '
                    .'rohe Schlüssel anzeigen -- ohne Fehlermeldung.',
                    $schluessel,
                    $sprache,
                    $sprache,
                ),
            );
        }
    }

    /**
     * Und die Übersetzung greift tatsächlich.
     *
     * Der Test darüber prüft, dass ein Verzeichnis da ist. Dieser prüft, dass
     * auch etwas herauskommt — ein leeres Verzeichnis wäre genauso stumm.
     */
    #[Test]
    public function a_translation_actually_resolves(): void
    {
        foreach (['users.field.name', 'audit.title', 'nav.group.system'] as $schluessel) {
            $this->assertNotSame(
                $schluessel,
                __($schluessel),
                sprintf('"%s" liefert sich selbst zurück -- die Sprache greift nicht.', $schluessel),
            );
        }
    }

    /**
     * Der Name der Anwendung ist nicht „Laravel".
     *
     * Er steht im Seitentitel, im Absender jeder Mail und in der Anmeldemaske.
     * Die Vorgabe des Gerüsts stehen zu lassen ist dieselbe Nachlässigkeit, die
     * in der composer.json die Lizenz auf MIT gelassen hat.
     */
    #[Test]
    public function the_application_has_its_own_name(): void
    {
        $this->assertNotSame('Laravel', config('app.name'));
        $this->assertNotSame('Laravel', config('mail.from.name'));
    }
}
