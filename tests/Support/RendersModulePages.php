<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Core\Modules\ModuleManager;

/**
 * Macht die Bildschirme eines Moduls im Test wirklich aufrufbar.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * DAS PROBLEM, DAS DIESES TRAIT LOEST -- und es betraf ALLE Module:
 *
 * Filament-Ressourcen eines Moduls bekommen ihre Routen beim Panel-Bau. Der
 * laeuft in createApplication(), also BEVOR ein Test die Datenbank hat und
 * bevor er ein Modul einschalten kann. Ein in setUp() aktiviertes Modul kommt
 * zu spaet: Das Panel ist gebaut, die Routen fehlen.
 *
 * Folge war, dass in diesem Projekt KEIN Test je eine Modul-Ressource
 * gerendert hat. Weder Flotte noch Lager, Arbeitskarten, LTA oder
 * Vereinsflieger. Geprueft wurden Rechte und Zaehler -- nie die Seite selbst.
 * Ein Fehler im Formular fiel damit erst auf, wenn ein Mensch es oeffnete.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * DER WEG: Schema anlegen, Module einschalten, App NEU BAUEN.
 *
 * Nach refreshApplication() liest der Panel-Provider die Modultabelle erneut --
 * jetzt mit den eingeschalteten Modulen, und die Routen entstehen.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * DESHALB KEIN RefreshDatabase, sondern migrate:fresh in setUp UND tearDown.
 *
 * RefreshDatabase haelt seine Isolation ueber eine Transaktion auf der
 * Verbindung. refreshApplication() baut den Container neu, die Verbindung damit
 * auch -- und die Transaktion waere weg, ihre Daten blieben stehen und tauchten
 * im naechsten Test wieder auf. Dasselbe Muster wie im RestoreTest, wo ein
 * externer mariadb-Prozess an derselben Transaktion haengenblieb.
 *
 * Der Preis sind zwei Schema-Laeufe je Test. Fuer eine Handvoll
 * Oberflaechen-Tests ist das der richtige Tausch: Sie sind die einzigen, die
 * eine kaputte Seite ueberhaupt finden koennen.
 * ─────────────────────────────────────────────────────────────────────────────
 */
trait RendersModulePages
{
    /**
     * Welche Module beim Panel-Bau aktiv sein sollen.
     *
     * @return list<string>
     */
    abstract protected function modulesUnderTest(): array;

    /**
     * AUSDRUECKLICH AUFZURUFEN, gleich nach parent::setUp().
     *
     * Nicht als setUp() im Trait: Definiert die Testklasse selbst eines --
     * und das tut sie fast immer --, gewinnt ihres, und das des Traits laeuft
     * nie. Der Fehler ist tueckisch, weil nichts bricht: Die Routen fehlen
     * einfach wieder, und der Test meldet "Route not defined" statt "du hast
     * den Aufruf vergessen".
     */
    protected function bootWithModules(): void
    {
        // Schema ohne Transaktion -- siehe Kopf.
        $this->artisan('migrate:fresh');

        $manager = app(ModuleManager::class);

        foreach ($this->modulesUnderTest() as $modul) {
            $manager->enable($modul);
        }

        /*
         * Und jetzt neu bauen. Erst hier sieht der Panel-Provider die
         * eingeschalteten Module -- und registriert ihre Routen.
         */
        $this->refreshApplication();
        $this->withoutVite();
    }

    /**
     * Aufraeumen laeuft ueber tearDown, weil es niemand vergessen kann.
     */
    protected function tearDown(): void
    {
        /*
         * Aufraeumen, weil keine Transaktion es tut. Ohne das faende der
         * naechste Test die Module eingeschaltet und die Daten dieses Tests
         * vor -- und faende sie irgendwann als Fehler, der nichts mit ihm zu
         * tun hat.
         */
        $this->artisan('migrate:fresh');

        parent::tearDown();
    }
}
