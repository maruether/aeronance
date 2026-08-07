<?php

declare(strict_types=1);

namespace Tests\Feature\Core;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Die Rückfallwerte der Konfiguration widersprechen den Leitplanken nicht.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * DREIMAL IN EINER NACHT DERSELBE FEHLER — deshalb dieser Test.
 *
 * Aeronance ist aus dem Laravel-Gerüst entstanden, und dessen Vorgaben stehen
 * an vielen Stellen noch drin. Gefunden am 2026-08-05:
 *
 *   composer.json  license: MIT          statt AGPL-3.0 (die LICENSE-Datei)
 *   config/app.php locale: 'en'          es gibt nur lang/de
 *   config/app.php name: 'Laravel'       im Seitentitel und als Mailabsender
 *   config/database.php: 'sqlite'        die Leitplanke sagt: NUR MariaDB
 *
 * Jede dieser Zeilen war jahrelang unauffällig, weil `.env.example` die Werte
 * überschreibt. Sie greifen nur dort, wo jemand eine unvollständige `.env`
 * hat — also genau bei der Installation, die ohnehin Mühe hat.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * GEPRÜFT WIRD DER QUELLTEXT, nicht die geladene Konfiguration.
 *
 * `config('database.default')` liefert im Test „mariadb", weil phpunit.xml es
 * setzt — der Rückfallwert bliebe dabei unsichtbar. Und die Dateien lassen
 * sich nicht einzeln laden, sie brauchen den Container (`database_path()`).
 * Bleibt: hinsehen, was dort geschrieben steht.
 * ─────────────────────────────────────────────────────────────────────────────
 */
final class ConfigDefaultsTest extends TestCase
{
    /**
     * Datei, Variable, erwarteter Rückfallwert, Begründung.
     *
     * @return list<array{0: string, 1: string, 2: string, 3: string}>
     */
    public static function vorgaben(): array
    {
        return [
            [
                'database.php', 'DB_CONNECTION', 'mariadb',
                'CLAUDE.md: "ausschliesslich MariaDB -- Hard Limit. Kein PostgreSQL, kein '
                .'SQLite, auch nicht fuer Tests oder lokale Entwicklung."',
            ],
            [
                'app.php', 'APP_LOCALE', 'de',
                'Es gibt nur lang/de. Bei einer anderen Sprache zeigt die Oberflaeche '
                .'rohe Schluessel -- ohne Fehlermeldung.',
            ],
            [
                'app.php', 'APP_FALLBACK_LOCALE', 'de',
                'Dasselbe: Der Rueckfall muss eine Sprache sein, die ausgeliefert wird.',
            ],
        ];
    }

    #[Test]
    #[DataProvider('vorgaben')]
    public function the_fallback_matches_the_guardrail(
        string $datei,
        string $variable,
        string $erwartet,
        string $grund,
    ): void {
        $quelle = (string) file_get_contents(config_path($datei));

        $treffer = preg_match(
            sprintf("/env\\(\\s*'%s'\\s*,\\s*'([^']*)'\\s*\\)/", preg_quote($variable, '/')),
            $quelle,
            $m,
        );

        $this->assertSame(1, $treffer, sprintf(
            'In config/%s steht kein env(\'%s\', …) mit Rückfallwert — wurde die Zeile umgebaut?',
            $datei,
            $variable,
        ));

        $this->assertSame($erwartet, $m[1], sprintf(
            "config/%s: %s fällt auf '%s' zurück, erwartet ist '%s'.\n%s",
            $datei,
            $variable,
            $m[1],
            $erwartet,
            $grund,
        ));
    }

    /**
     * Das Sitzungscookie ist in production sicher.
     *
     * ─────────────────────────────────────────────────────────────────────────
     * Hier stand `env('SESSION_SECURE_COOKIE')` ohne Rückfallwert — in Laravel
     * heißt das `false`. Eine Installation ohne die Zeile hätte ihr
     * Sitzungscookie unverschlüsselt mitgeschickt; bei einer Instanz, die ins
     * Internet soll, genau die Sorte stiller Fehler, gegen die die übrigen
     * Leitplanken gebaut sind.
     *
     * An APP_ENV gebunden und nicht hart auf `true`, weil sonst der lokale
     * Entwicklungsbetrieb über http bricht — der Browser schickt das Cookie
     * dann nicht, die Anmeldung scheitert stumm.
     * ─────────────────────────────────────────────────────────────────────────
     */
    #[Test]
    public function the_session_cookie_is_secure_in_production(): void
    {
        $quelle = (string) file_get_contents(config_path('session.php'));

        $this->assertMatchesRegularExpression(
            "/'secure'\s*=>\s*env\(\s*'SESSION_SECURE_COOKIE'\s*,[^)]*APP_ENV/",
            $quelle,
            "config/session.php bindet 'secure' nicht an APP_ENV — ohne die Zeile in der "
            .'.env ginge das Sitzungscookie unverschlüsselt hinaus.',
        );
    }

    /**
     * Jede Einstellung, die der Code kennt, steht auch in der .env.example.
     *
     * ─────────────────────────────────────────────────────────────────────────
     * DIE VORLAGE IST DIE DOKUMENTATION. Wer eine Instanz aufsetzt, liest sie
     * — und was dort nicht steht, gibt es für ihn nicht. Gemessen fehlten acht
     * `AERONANCE_*`-Einstellungen, darunter die gesamte
     * Aktualisierungsprüfung: vorhanden, wirksam, aber unauffindbar.
     * ─────────────────────────────────────────────────────────────────────────
     */
    #[Test]
    public function every_setting_is_documented_in_the_env_example(): void
    {
        $vorlage = (string) file_get_contents(base_path('.env.example'));

        $imCode = [];

        foreach (glob(config_path('*.php')) ?: [] as $datei) {
            preg_match_all('/AERONANCE_[A-Z0-9_]+/', (string) file_get_contents($datei), $m);
            $imCode = [...$imCode, ...$m[0]];
        }

        $imCode = array_values(array_unique($imCode));
        sort($imCode);

        $this->assertNotEmpty($imCode, 'Keine Einstellung gefunden — stimmt der Suchausdruck?');

        $fehlend = array_values(array_filter(
            $imCode,
            static fn (string $v): bool => preg_match('/^#?\s*'.$v.'=/m', $vorlage) !== 1,
        ));

        $this->assertSame([], $fehlend, sprintf(
            'Diese Einstellungen kennt der Code, die .env.example nicht:
  %s
'
            .'Wer eine Instanz aufsetzt, liest die Vorlage — was dort fehlt, gibt es für ihn nicht.',
            implode('
  ', $fehlend),
        ));
    }

    /**
     * Und der Name der Anwendung ist nicht der des Gerüsts.
     */
    #[Test]
    public function the_application_is_not_called_laravel(): void
    {
        foreach (['app.php', 'mail.php'] as $datei) {
            $quelle = (string) file_get_contents(config_path($datei));

            $this->assertStringNotContainsString(
                "'Laravel'",
                $quelle,
                sprintf(
                    'config/%s trägt noch "Laravel" als Rückfallwert. Der erscheint im '
                    .'Seitentitel, in der Anmeldemaske und als Absender jeder Mail.',
                    $datei,
                ),
            );
        }
    }
}
