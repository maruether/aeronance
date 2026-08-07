<?php

declare(strict_types=1);

namespace Tests\Feature\Core;

use App\Core\Access\AccessSetup;
use App\Core\Access\CoreRoles;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Jedes ausgelieferte Inline-Skript ist in der CSP freigegeben — und sonst keins.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * DAS PROBLEM: Filament liefert Inline-Skripte (Dunkelmodus, eingeklappte
 * Menügruppen, `window.filamentData`). Unter `script-src 'self'` führt der
 * Browser sie NICHT aus. Das erzeugt keine 500 — die Seite baut sich, und der
 * Schaden steht in der Browserkonsole. Kein Rendering-Test kann das sehen.
 *
 * Über eine Nonce ginge es nicht: Livewire beherrscht CSP-Nonces, Filament
 * nicht. Bleibt der Hash über den Inhalt.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * DIESER TEST IST BEIDES — PRÜFUNG UND ERZEUGUNG.
 *
 * Prüfen:    ./vendor/bin/phpunit --filter CspScriptHashesTest
 * Erzeugen:  CSP_PIN=1 ./vendor/bin/phpunit --filter CspScriptHashesTest
 *
 * Warum nicht als Artisan-Befehl: Das vierte Skript erscheint erst auf einer
 * ANGEMELDETEN Panel-Seite (die Seitenleiste bringt es mit) — gemessen liefert
 * die Anmeldeseite nur drei. Ein Befehl müsste sich also eine Sitzung bauen,
 * und diese Maschinerie hat die Testsuite bereits und beweisbar richtig.
 *
 * Nach dem Erzeugen gehört `git diff config/csp.php` angesehen. Genau dieser
 * Blick unterscheidet die Liste von einer Automatik, die alles durchwinkt.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * WANN ER ANSCHLÄGT: Wenn Filament seine Skripte ändert — also in dem Merge
 * Request, der `composer.lock` anfasst. Driften kann nichts: Alle drei Kanäle
 * installieren mit `composer install`, nie `composer update`.
 * ─────────────────────────────────────────────────────────────────────────────
 */
final class CspScriptHashesTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Seiten, die zusammen alle Inline-Skripte zeigen.
     *
     * Die Anmeldeseite allein genügt nicht — gemessen fehlt ihr das Skript für
     * die eingeklappten Menügruppen, das die Seitenleiste mitbringt.
     *
     * @var list<string>
     */
    private const SEITEN = ['/verwaltung/login', '/verwaltung', '/verwaltung/benutzer'];

    #[Test]
    public function every_inline_script_is_covered_by_the_policy(): void
    {
        $gefunden = $this->collectHashes();

        $this->assertNotEmpty(
            $gefunden,
            'Kein einziges Inline-Skript gefunden — dann stimmt die Messung nicht mehr, '
            .'nicht die Richtlinie.',
        );

        if ((string) env('CSP_PIN') !== '') {
            $this->writePinnedList($gefunden);

            $this->markTestIncomplete(sprintf(
                'config/csp.php neu geschrieben (%d Hashes). Jetzt `git diff config/csp.php` ansehen.',
                count($gefunden),
            ));
        }

        /** @var list<string> $festgeschrieben */
        $festgeschrieben = config('csp.script_hashes', []);

        $fehlend = array_values(array_diff($gefunden, $festgeschrieben));
        $ueberzaehlig = array_values(array_diff($festgeschrieben, $gefunden));

        $this->assertSame([], $fehlend, sprintf(
            "Diese Inline-Skripte werden ausgeliefert, stehen aber nicht in config/csp.php.\n"
            ."Der Browser führt sie NICHT aus — die Oberfläche wäre teilweise tot.\n"
            ."Neu erzeugen:  CSP_PIN=1 ./vendor/bin/phpunit --filter CspScriptHashesTest\n\n  %s",
            implode("\n  ", $fehlend),
        ));

        /*
         * Ueberzaehlige sind kein Sicherheitsproblem -- ein Hash erlaubt nur
         * genau diesen einen Inhalt. Sie sind aber ein Zeichen dafuer, dass die
         * Liste veraltet ist, und eine Liste, der man nicht ansieht, ob sie
         * stimmt, wird irgendwann nicht mehr gepflegt.
         */
        $this->assertSame([], $ueberzaehlig, sprintf(
            "Diese Hashes stehen in config/csp.php, werden aber nicht mehr ausgeliefert.\n"
            ."Ungefährlich, aber die Liste gehört aufgeräumt.\n\n  %s",
            implode("\n  ", $ueberzaehlig),
        ));
    }

    /**
     * Und die Richtlinie nennt sie auch wirklich.
     *
     * Die Liste zu pflegen nützt nichts, wenn sie nicht im Header landet — das
     * wäre der stille Fehler, der genau wie der behobene aussieht.
     */
    #[Test]
    public function the_header_carries_the_hashes(): void
    {
        /** @var list<string> $festgeschrieben */
        $festgeschrieben = config('csp.script_hashes', []);

        $this->assertNotEmpty($festgeschrieben, 'Ohne Hashes ist dieser Test bedeutungslos.');

        $csp = (string) $this->get('/up')->headers->get('Content-Security-Policy');

        foreach ($festgeschrieben as $hash) {
            $this->assertStringContainsString(
                "'".$hash."'",
                $csp,
                'Der Hash steht in der Konfiguration, aber nicht im Header.',
            );
        }
    }

    /**
     * Alle Inline-Skripte der Panel-Seiten, als CSP-Hashes.
     *
     * @return list<string>
     */
    private function collectHashes(): array
    {
        app(AccessSetup::class)->run();

        $admin = User::factory()->create(['is_active' => true]);
        $admin->assignRole(CoreRoles::ADMIN);

        $hashes = [];

        foreach (self::SEITEN as $pfad) {
            // Die Anmeldeseite wird ABGEMELDET geholt -- angemeldet leitet sie
            // weiter, und eine Weiterleitung hat keinen Rumpf.
            $antwort = $pfad === '/verwaltung/login'
                ? $this->get($pfad)
                : $this->actingAs($admin->fresh())->get($pfad);

            preg_match_all(
                '/<script\b([^>]*)>(.*?)<\/script>/is',
                (string) $antwort->getContent(),
                $treffer,
                PREG_SET_ORDER,
            );

            foreach ($treffer as $skript) {
                if (str_contains($skript[1], 'src=')) {
                    continue;
                }

                // Nutzlast, die der Browser nicht ausfuehrt, faellt nicht unter
                // script-src.
                if (preg_match('/type\s*=\s*"[^"]*json[^"]*"/i', $skript[1])) {
                    continue;
                }

                if (trim($skript[2]) === '') {
                    continue;
                }

                /*
                 * GEHASHT WIRD DER INHALT BYTEGENAU, so wie er zwischen den
                 * Marken steht -- inklusive Leerraum. Genau so rechnet der
                 * Browser, und ein getrimmter Hash passte nie.
                 */
                $hashes[] = 'sha256-'.base64_encode(hash('sha256', $skript[2], true));
            }
        }

        $hashes = array_values(array_unique($hashes));
        sort($hashes);

        return $hashes;
    }

    /**
     * @param  list<string>  $hashes
     */
    private function writePinnedList(array $hashes): void
    {
        $pfad = config_path('csp.php');
        $inhalt = (string) file_get_contents($pfad);

        $zeilen = implode("\n", array_map(
            static fn (string $hash): string => sprintf("        '%s',", $hash),
            $hashes,
        ));

        $neu = preg_replace(
            "/('script_hashes' => \[\n)(.*?)(\n    \],)/s",
            "$1        // Filament 5 — erzeugt, siehe Kopf\n".$zeilen.'$3',
            $inhalt,
        );

        file_put_contents($pfad, (string) $neu);
    }
}
