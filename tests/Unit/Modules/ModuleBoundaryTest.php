<?php

declare(strict_types=1);

namespace Tests\Unit\Modules;

use App\Core\Modules\ModuleRegistry;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Who is allowed to know about whom.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * WHY THIS IS A TEST AND NOT A CONVENTION. The fleet used to type-hint
 * App\Modules\Directives\Sources\HttpFetcher, and the comment above it said, in
 * so many words, "arguably that is a dependency the wrong way round -- worth
 * revisiting if the fetcher ever moves to the core". It sat there for months.
 * The fleet declares no requirement at all, so an installation with the
 * directives module switched off had a base module that could not resolve its
 * own fetcher: not a style question, a broken install.
 *
 * A note in a docblock does not fail a pipeline. This does.
 *
 * The two rules are the leitplanken said out loud:
 *
 *   "Der Kern muss ohne jedes Modul lauffähig sein, jedes Modul einzeln
 *    deaktivierbar, ohne dass der Rest bricht."
 *   "Kommunikation zwischen Modulen nur über definierte Schnittstellen/Events
 *    -- nie direkt auf fremde Tabellen zugreifen. Abhängigkeiten explizit im
 *    Manifest deklarieren."
 *
 * A NEW EDGE FAILS UNTIL SOMEBODY WRITES DOWN WHY, which is the point: the
 * allow-list below is deliberately hand-maintained rather than derived from
 * whatever the code happens to do. Deriving it would turn this into a test that
 * always passes.
 * ─────────────────────────────────────────────────────────────────────────────
 */
final class ModuleBoundaryTest extends TestCase
{
    /**
     * Edges a module may have WITHOUT declaring the other in `requires`.
     *
     * Each one is optional in the real sense: the feature disappears when the
     * other module is off, and nothing else does. Both are guarded at the point
     * of use -- the Filament page asks the action's isAvailable(), and the
     * action asks the ModuleManager -- so the class is never resolved on an
     * installation that does not have it.
     *
     * @var array<string, array<string, string>>
     */
    private const OPTIONAL = [
        'directives' => [
            'taskcards' => 'Eine Anweisung als Vorgang einplanen -- ScheduleDirectiveCard::isAvailable().',
        ],
        'taskcards' => [
            'warehouse' => 'Teileentnahme auf die Arbeitskarte -- IssuePartToCard::isAvailable().',
        ],
        'fleet' => [
            'warehouse' => 'Hört auf PartIssuedToAircraft. Der Ereignisweg ist der vorgesehene, '
                .'und die Klasse wird nur geladen, wenn das Lager das Ereignis überhaupt wirft.',
            'taskcards' => 'Hört auf ReleaseIssued -- die Freigabebescheinigung wird als '
                .'Dokumentverweis in der Lebenslaufakte abgelegt. Derselbe Ereignisweg, '
                .'dritte Anwendung.',
        ],
        'warehouse' => [
            'fleet' => 'Hört auf ComponentRemovedFromAircraft -- dieselbe Richtung, gespiegelt.',
        ],
        'vereinsflieger' => [
            'fleet' => 'Betriebszeiten aus Vereinsflieger werden Zählerstände. Angefasst wird '
                .'die Flotte NUR über Klassennamen als String und erst, nachdem '
                .'ReadAircraftTimes den ModuleManager gefragt hat -- ist die Flotte aus, '
                .'gibt es keine Luftfahrzeuge und damit nichts zu holen. Die '
                .'Kopplungstabelle des Moduls hat aus demselben Grund KEINEN '
                .'Fremdschlüssel auf aircraft.',
        ],
    ];

    #[Test]
    public function the_core_knows_of_no_module(): void
    {
        /*
         * THE RULE THAT CARRIES THE OTHERS. Everything may depend on the core;
         * the core may depend on nothing. The moment it names a module, that
         * module is no longer optional -- and the setup assistant offers a
         * choice it cannot honour.
         */
        $offenders = [];

        foreach ($this->phpFilesIn(app_path('Core')) as $file) {
            foreach ($this->modulesNamedIn($file) as $module) {
                $offenders[] = sprintf('%s -> %s', $this->relative($file), $module);
            }
        }

        $this->assertSame([], $offenders, sprintf(
            "Der Kern nennt ein Modul:\n  %s\nDamit ist dieses Modul nicht mehr abschaltbar. "
            .'Was beide brauchen, gehört in den Kern -- so wie HttpFetcher nach App\\Core\\Http '
            .'gewandert ist, statt dass die Flotte es sich aus den LTA holt.',
            implode("\n  ", $offenders),
        ));
    }

    #[Test]
    public function a_module_reaches_only_into_modules_it_has_declared(): void
    {
        $registry = app(ModuleRegistry::class);
        $offenders = [];

        foreach ($registry->names() as $name) {
            $manifest = $registry->manifest($name);
            $allowed = array_merge(
                array_map('strval', $manifest->requires),
                array_keys(self::OPTIONAL[$name] ?? []),
            );

            foreach ($this->phpFilesIn($this->pathOf($name)) as $file) {
                foreach ($this->modulesNamedIn($file) as $other) {
                    if ($other === $name || in_array($other, $allowed, true)) {
                        continue;
                    }

                    $offenders[] = sprintf('%s -> %s (%s)', $name, $other, $this->relative($file));
                }
            }
        }

        sort($offenders);

        $this->assertSame([], array_values(array_unique($offenders)), sprintf(
            "Ein Modul greift in ein Modul, das es nicht kennt:\n  %s\n"
            .'Entweder gehört die Abhängigkeit ins Manifest (requires), oder sie ist optional '
            .'und wird über ModuleManager::isEnabled geschützt -- dann bitte oben in OPTIONAL '
            .'mit Begründung eintragen.',
            implode("\n  ", array_unique($offenders)),
        ));
    }

    #[Test]
    public function every_declared_requirement_names_a_module_that_exists(): void
    {
        // Cheap, and it catches the rename that silently turns a hard dependency
        // into no dependency at all.
        $registry = app(ModuleRegistry::class);

        foreach ($registry->names() as $name) {
            foreach ($registry->manifest($name)->requires as $required) {
                $this->assertTrue(
                    $registry->has((string) $required),
                    sprintf('%s verlangt "%s" -- ein Modul dieses Namens gibt es nicht.', $name, $required),
                );
            }
        }
    }

    /**
     * Und dasselbe für Sprachdateien: kein Modul benutzt die Texte eines anderen.
     *
     * ─────────────────────────────────────────────────────────────────────────
     * GEFUNDEN, NICHT AUSGEDACHT: Im Erfahrungslogbuch (Part-66) stand das
     * Datumsfeld auf `__('warehouse.inventory.from')` — einem Schlüssel aus dem
     * Lagermodul, den es dort gar nicht gab. Angezeigt wurde deshalb der
     * Schlüssel selbst.
     *
     * Der bestehende Test konnte das nicht sehen: Er liest PHP-Dateien unter
     * `app/Modules` und sucht nach Klassennamen. Ein Übersetzungsschlüssel in
     * einer Blade-Datei ist beides nicht.
     *
     * WARUM DAS EINE GRENZVERLETZUNG IST und nicht bloß unsauber: Die Texte
     * eines Moduls sind Teil seiner Schnittstelle. Wer sie mitbenutzt, kann
     * nicht wissen, dass drüben jemand einen Schlüssel umbenennt — und merkt es
     * auch nicht, denn Laravel wirft nichts, es zeigt den Schlüssel an.
     * ─────────────────────────────────────────────────────────────────────────
     */
    #[Test]
    public function a_module_uses_only_its_own_translations(): void
    {
        $registry = app(ModuleRegistry::class);

        // Alles, was keiner Modul-Sprachdatei gehört, ist Kern und für alle da.
        $moduleGroups = array_map('mb_strtolower', $registry->names());

        $offenders = [];

        foreach ($registry->names() as $name) {
            $own = mb_strtolower($name);
            $allowed = array_merge(
                [$own],
                array_map('mb_strtolower', array_map('strval', $registry->manifest($name)->requires)),
                array_map('mb_strtolower', array_keys(self::OPTIONAL[$name] ?? [])),
            );

            foreach ($this->translatedFilesOf($name) as $file) {
                preg_match_all(
                    "/\b(?:__|trans)\(\s*'([a-z][a-z0-9_]*)\./i",
                    (string) file_get_contents($file),
                    $treffer,
                );

                foreach ($treffer[1] as $gruppe) {
                    $gruppe = mb_strtolower($gruppe);

                    if (! in_array($gruppe, $moduleGroups, true) || in_array($gruppe, $allowed, true)) {
                        continue;
                    }

                    $offenders[] = sprintf('%s -> %s.* (%s)', $name, $gruppe, $this->relative($file));
                }
            }
        }

        sort($offenders);

        $this->assertSame([], array_values(array_unique($offenders)), sprintf(
            "Ein Modul benutzt die Übersetzungen eines Moduls, das es nicht kennt:\n  %s\n"
            .'Entweder gehört der Text in die eigene Sprachdatei, oder die Abhängigkeit ins Manifest.',
            implode("\n  ", array_unique($offenders)),
        ));
    }

    /**
     * Alle Dateien eines Moduls, in denen Übersetzungen vorkommen können.
     *
     * Also PHP UND Blade — die Ansichten liegen nicht bei den Klassen, sondern
     * unter `resources/views/<modul>`, und genau dort saß der gefundene Fehler.
     *
     * @return list<string>
     */
    private function translatedFilesOf(string $name): array
    {
        $dateien = $this->phpFilesIn($this->pathOf($name));

        $ansichten = resource_path('views/'.mb_strtolower($name));

        if (is_dir($ansichten)) {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($ansichten, \FilesystemIterator::SKIP_DOTS),
            );

            foreach ($iterator as $entry) {
                if ($entry->isFile() && $entry->getExtension() === 'php') {
                    $dateien[] = $entry->getPathname();
                }
            }
        }

        return $dateien;
    }

    /**
     * The module directory belonging to a manifest name.
     *
     * The two differ in case only ('fleet' -> 'Fleet'), but the mapping is made
     * explicit rather than assumed: a module whose folder does not follow it
     * would otherwise be scanned as an empty directory and pass everything.
     */
    private function pathOf(string $name): string
    {
        $path = app_path('Modules/'.ucfirst($name));

        if (! is_dir($path)) {
            // Part66 -> 'Part66'; anything else that does not match is a real
            // finding and must not be quietly skipped.
            $found = array_filter(
                glob(app_path('Modules/*'), GLOB_ONLYDIR) ?: [],
                static fn (string $dir): bool => mb_strtolower(basename($dir)) === mb_strtolower($name),
            );

            $this->assertNotEmpty($found, sprintf('Zum Modul "%s" gibt es kein Verzeichnis.', $name));

            $path = (string) reset($found);
        }

        return $path;
    }

    /**
     * Module namespaces named anywhere in a file.
     *
     * Reads the whole file rather than the use statements alone: a fully
     * qualified name in a string, an app() call or an annotation binds just as
     * hard as an import does.
     *
     * @return list<string>
     */
    private function modulesNamedIn(string $file): array
    {
        $source = (string) file_get_contents($file);

        // The digits matter: [A-Za-z]+ reads "App\Modules\Part66" as a module
        // called "Part", which is a boundary that does not exist.
        /*
         * EIN ODER ZWEI BACKSLASHES, und das ist eine Korrektur.
         *
         * Vorher stand hier nur die einfache Form. Eine Klasse, die per STRING
         * angesprochen wird -- 'App\\Modules\\Fleet\\Models\\Aircraft', wie es
         * ReadAircraftTimes tut, um die Flotte nicht hart zu importieren --
         * steht in der Datei mit DOPPELTEN Backslashes und fiel damit
         * vollstaendig durch.
         *
         * Genau diese Schreibweise waehlt man aber, WEIL man die harte
         * Abhaengigkeit vermeiden will. Die Grenzpruefung uebersah also
         * ausgerechnet den Fall, fuer den sie da ist -- aufgefallen beim
         * Nachsehen, nicht durch einen roten Test.
         */
        preg_match_all('/App\\\\{1,2}Modules\\\\{1,2}([A-Za-z0-9]+)/', $source, $found);

        return array_values(array_unique(array_map(
            static fn (string $m): string => mb_strtolower($m),
            $found[1] ?? [],
        )));
    }

    /** @return list<string> */
    private function phpFilesIn(string $directory): array
    {
        $files = [];

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
        );

        foreach ($iterator as $entry) {
            if ($entry->isFile() && $entry->getExtension() === 'php') {
                $files[] = $entry->getPathname();
            }
        }

        sort($files);

        return $files;
    }

    private function relative(string $file): string
    {
        return str_replace(base_path().'/', '', $file);
    }
}
