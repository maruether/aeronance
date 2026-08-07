<?php

declare(strict_types=1);

namespace Tests\Feature\Modules;

use App\Core\Access\AccessSetup;
use App\Core\Access\CorePermissions;
use App\Models\User;
use App\Modules\Vereinsflieger\Filament\Resources\AircraftLinks\AircraftLinkResource;
use App\Modules\Vereinsflieger\Filament\Resources\AircraftLinks\Pages\ListAircraftLinks;
use App\Modules\Vereinsflieger\Filament\Resources\Connections\ConnectionResource;
use App\Modules\Vereinsflieger\Filament\Resources\Connections\Pages\ListConnections;
use App\Modules\Vereinsflieger\Models\Connection;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\RendersModulePages;
use Tests\TestCase;

/**
 * Die Bildschirme bauen sich wirklich.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * Vorgabe: „ich hab das tool tatsächlich noch nie gesehen. ich will erstmal das
 * backend fertig haben und das frontend läuft halt mit."
 *
 * Wenn niemand die Oberflaeche oeffnet, faellt ein Fehler darin erst auf, wenn
 * es der Erste tut -- und das kann Monate spaeter der Verein sein. Ein falscher
 * Komponentenaufruf, eine fehlende Uebersetzung, eine Methode, die es in
 * Filament 5 nicht mehr gibt: Nichts davon sieht ein gewoehnlicher Test, weil
 * er die Seite nie baut.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * EIN TEST FUER ALLE SEITEN, und das ist eine Kostenentscheidung.
 *
 * RendersModulePages baut die App neu, damit die Modul-Ressourcen ihre Routen
 * bekommen -- mit zwei Schema-Laeufen je Test. GEMESSEN: 67 Sekunden. Acht
 * Tests waeren neun Minuten und wuerden die Suite verdoppeln.
 *
 * Also alle Seiten in einem Durchgang. Faellt einer aus, sagt die Meldung
 * welcher -- mehr braucht ein Rauchmelder nicht. Die Rechtepruefungen liegen
 * getrennt in VereinsfliegerInterfaceTest und laufen dort in Sekunden.
 * ─────────────────────────────────────────────────────────────────────────────
 */
#[Group('rendering')]
final class VereinsfliegerRenderTest extends TestCase
{
    use RendersModulePages;

    /** @return list<string> */
    protected function modulesUnderTest(): array
    {
        return ['vereinsflieger', 'fleet'];
    }

    protected function setUp(): void
    {
        parent::setUp();

        // Schema anlegen, Module einschalten, App neu bauen -- erst danach
        // haben die Modul-Ressourcen ihre Routen.
        $this->bootWithModules();

        app(AccessSetup::class)->run();
    }

    #[Test]
    public function every_screen_of_this_module_builds(): void
    {
        Connection::create([
            'name' => 'Testverein',
            'username' => 'test',
            'password' => 'geheim',
            'app_key' => 'schluessel',
            'provides_identities' => true,
            'is_active' => true,
        ]);

        $this->actingAs($this->admin());

        $this->get(ConnectionResource::getUrl('index'))
            ->assertSuccessful()
            ->assertSee('Testverein');

        $this->get(AircraftLinkResource::getUrl('index'))->assertSuccessful();

        /*
         * DIE FORMULARE LIEGEN IN MODALEN, nicht auf eigenen Seiten -- es gibt
         * keine create-Route, und das ist Absicht: Eine Anbindung ist ein
         * kurzer Datensatz, fuer den sich kein Seitenwechsel lohnt.
         *
         * Aufgefallen, weil dieser Test zuerst getUrl('create') abrief und
         * "Route not defined" bekam. Das Formular wird deshalb ueber die
         * Aktion geoeffnet -- und genau dabei baut Filament seine Komponenten,
         * was der eigentliche Zweck dieser Pruefung ist.
         */
        Livewire::test(ListConnections::class)
            ->assertSuccessful()
            ->mountAction('create')
            ->assertSee(__('vereinsflieger.connection.field.provides_identities'));

        Livewire::test(ListAircraftLinks::class)
            ->assertSuccessful()
            ->mountAction('create')
            ->assertSee(__('vereinsflieger.link.field.callsign'));
    }

    /**
     * Und die Geheimnisse stehen nicht auf der Seite.
     *
     * Ein Passwortfeld, das den alten Wert enthält, wandert beim nächsten
     * Speichern durch den Browser und steht im Zweifel im Verlauf. Das lässt
     * sich nur an der gerenderten Seite prüfen — deshalb steht es hier und
     * nicht bei den schnellen Tests.
     */
    #[Test]
    public function the_stored_secrets_never_reach_the_page(): void
    {
        Connection::create([
            'name' => 'Testverein',
            'username' => 'test',
            'password' => 'streng-geheim-4711',
            'app_key' => 'app-schluessel-4711',
            'is_active' => true,
        ]);

        $this->actingAs($this->admin());

        $this->get(ConnectionResource::getUrl('index'))
            ->assertSuccessful()
            ->assertDontSee('streng-geheim-4711')
            ->assertDontSee('app-schluessel-4711');

        // Auch nicht im Bearbeitungsformular: Der alte Wert wird nie
        // zurueckgezeigt, ein leeres Feld heisst "nicht aendern".
        Livewire::test(ListConnections::class)
            ->mountAction('edit', ['record' => Connection::sole()->getKey()])
            ->assertDontSee('streng-geheim-4711')
            ->assertDontSee('app-schluessel-4711');
    }

    private function admin(): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->givePermissionTo(CorePermissions::SETTINGS_MANAGE);
        $user->givePermissionTo(CorePermissions::ROLES_MANAGE);

        return $user->fresh();
    }
}
