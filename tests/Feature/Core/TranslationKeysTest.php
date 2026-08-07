<?php

declare(strict_types=1);

namespace Tests\Feature\Core;

use Illuminate\Support\Facades\Lang;
use PHPUnit\Framework\Attributes\Test;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Tests\TestCase;

/**
 * Jeder Übersetzungsschlüssel, der im Code steht, muss auch übersetzt sein.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * DER FEHLER, DEN DAS FINDET, IST STUMM.
 *
 * Fehlt ein Schlüssel, wirft Laravel nichts — es zeigt den Schlüssel selbst an.
 * In der Benutzerliste stand deshalb „users.filter.never_activated" statt
 * „Nie aktiviert", und kein einziger Test hat das gemerkt: Die Seite antwortet
 * ja mit 200, der Text ist bloß Unsinn.
 *
 * Gefunden wurde es beim Lesen, nicht beim Testen. Genau dafür ist das hier.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * WAS GEPRÜFT WIRD: Nur vollständig ausgeschriebene Schlüssel, also `__('a.b')`
 * und `trans('a.b')`. Zusammengesetzte wie `__('roles.'.$name)` kann kein
 * Scanner auflösen — die stehen in den fachlichen Tests.
 *
 * Deutsch ist die Referenzsprache: Sie ist die einzige, die vollständig sein
 * MUSS. Für weitere Sprachen ist eine Lücke ein fehlender Beitrag und kein
 * Fehler — Laravel fällt dann auf Deutsch zurück.
 * ─────────────────────────────────────────────────────────────────────────────
 */
final class TranslationKeysTest extends TestCase
{
    /**
     * Dateigruppen, die es nicht als Sprachdatei gibt.
     *
     * `validation`, `passwords` und `auth` liefert Laravel selbst mit, und
     * Filament bringt seine eigenen mit.
     *
     * @var list<string>
     */
    private const FREMDE_GRUPPEN = ['validation', 'passwords', 'filament', 'filament-panels'];

    #[Test]
    public function every_literal_key_in_the_code_is_translated(): void
    {
        $fehlend = [];

        foreach ($this->quelldateien() as $datei) {
            $inhalt = (string) file_get_contents($datei->getPathname());

            /*
             * `__('gruppe.schluessel')` oder `trans('...')` -- und nur dann,
             * wenn direkt hinter dem Schluessel die Klammer zugeht oder ein
             * Komma folgt. Steht dort ein Punkt, ist der Schluessel
             * zusammengesetzt und nicht pruefbar.
             */
            preg_match_all(
                "/\b(?:__|trans)\(\s*'([a-z][a-z0-9_]*(?:\.[a-z0-9_]+)+)'\s*[,)]/i",
                $inhalt,
                $treffer,
            );

            foreach ($treffer[1] as $schluessel) {
                $gruppe = explode('.', $schluessel)[0];

                if (in_array($gruppe, self::FREMDE_GRUPPEN, true)) {
                    continue;
                }

                if (! Lang::has($schluessel, 'de')) {
                    $fehlend[$schluessel] = $this->kurz($datei->getPathname());
                }
            }
        }

        $this->assertSame([], $fehlend, sprintf(
            "Diese Übersetzungsschlüssel stehen im Code, aber in keiner Sprachdatei:\n%s",
            implode("\n", array_map(
                static fn (string $schluessel, string $wo): string => sprintf('  %-52s %s', $schluessel, $wo),
                array_keys($fehlend),
                $fehlend,
            )),
        ));
    }

    /**
     * Und der Gegentest: keine Sprachdatei ohne Gegenstück im Code.
     *
     * Eine Übersetzung, die niemand aufruft, ist kein Fehler — sie ist Ballast,
     * der beim nächsten Umbau mitgeschleppt und mitübersetzt wird. Der Test
     * meldet sie, ohne rot zu werden: Er zählt nur.
     */
    #[Test]
    public function unused_keys_are_reported_but_do_not_fail(): void
    {
        $benutzt = [];

        foreach ($this->quelldateien() as $datei) {
            preg_match_all(
                "/'([a-z][a-z0-9_]*(?:\.[a-z0-9_]+)+)'/i",
                (string) file_get_contents($datei->getPathname()),
                $treffer,
            );

            foreach ($treffer[1] as $schluessel) {
                $benutzt[$schluessel] = true;
            }
        }

        $ungenutzt = [];

        foreach (glob(lang_path('de/*.php')) ?: [] as $sprachdatei) {
            $gruppe = basename($sprachdatei, '.php');

            foreach ($this->flach((array) require $sprachdatei, $gruppe) as $schluessel) {
                if (! isset($benutzt[$schluessel])) {
                    $ungenutzt[] = $schluessel;
                }
            }
        }

        // Keine Behauptung ueber die Zahl -- nur eine sichtbare Angabe.
        $this->addToAssertionCount(1);

        if ($ungenutzt !== []) {
            fwrite(STDERR, sprintf(
                "\n[Hinweis] %d Übersetzungsschlüssel werden nirgends wörtlich aufgerufen ".
                "(kann an zusammengesetzten Schlüsseln liegen).\n",
                count($ungenutzt),
            ));
        }
    }

    /**
     * @return list<SplFileInfo>
     */
    private function quelldateien(): array
    {
        $dateien = [];

        foreach ([app_path(), resource_path('views')] as $wurzel) {
            if (! is_dir($wurzel)) {
                continue;
            }

            /** @var SplFileInfo $datei */
            foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($wurzel)) as $datei) {
                if ($datei->isFile() && $datei->getExtension() === 'php') {
                    $dateien[] = $datei;
                }
            }
        }

        return $dateien;
    }

    /**
     * @param  array<mixed>  $werte
     * @return list<string>
     */
    private function flach(array $werte, string $praefix): array
    {
        $schluessel = [];

        foreach ($werte as $key => $wert) {
            $voll = $praefix.'.'.$key;

            if (is_array($wert)) {
                $schluessel = [...$schluessel, ...$this->flach($wert, $voll)];

                continue;
            }

            $schluessel[] = $voll;
        }

        return $schluessel;
    }

    private function kurz(string $pfad): string
    {
        return str_replace(base_path().'/', '', $pfad);
    }
}
