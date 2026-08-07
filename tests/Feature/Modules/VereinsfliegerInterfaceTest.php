<?php

declare(strict_types=1);

namespace Tests\Feature\Modules;

use App\Core\Access\AccessSetup;
use App\Core\Access\CorePermissions;
use App\Core\Modules\ModuleManager;
use App\Models\User;
use App\Modules\Vereinsflieger\Enums\MemberStatusHandling;
use App\Modules\Vereinsflieger\Filament\Pages\MemberStatusesPage;
use App\Modules\Vereinsflieger\Filament\Resources\AircraftLinks\AircraftLinkResource;
use App\Modules\Vereinsflieger\Filament\Resources\Connections\ConnectionResource;
use App\Modules\Vereinsflieger\Models\MemberStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use Throwable;

/**
 * Die Riegel vor den Bildschirmen.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * Vorgabe: „ich hab das tool tatsächlich noch nie gesehen. ich will erstmal das
 * backend fertig haben und das frontend läuft halt mit."
 *
 * DAS IST DER GRUND FUER DIESE DATEI. Wenn niemand die Oberflaeche oeffnet,
 * faellt ein Fehler darin erst auf, wenn es der Erste tut -- und das kann
 * Monate spaeter der Verein sein. Ein falscher Komponentenaufruf, eine
 * vergessene Uebersetzung, eine Methode, die es in Filament 5 nicht mehr gibt:
 * Nichts davon sieht ein gewoehnlicher Test, weil er die Seite nie baut.
 *
 * Hier stehen die Rechtepruefungen und die Statusseite -- alles, was ohne
 * Routen auskommt und deshalb schnell laeuft. Dass die Ressourcen-Seiten sich
 * ueberhaupt BAUEN lassen, prueft VereinsfliegerRenderTest; das kostet einen
 * App-Neustart und gehoert nicht in jede Klasse.
 * ─────────────────────────────────────────────────────────────────────────────
 */
final class VereinsfliegerInterfaceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        app(AccessSetup::class)->run();
        app(ModuleManager::class)->enable('vereinsflieger');
        app(ModuleManager::class)->enable('fleet');
    }

    /** Ohne Flotte gibt es nichts zu koppeln -- die Seite ist dann zu. */
    #[Test]
    public function the_aircraft_links_are_closed_without_the_fleet(): void
    {
        app(ModuleManager::class)->disable('fleet');
        $this->actingAs($this->admin());

        $this->assertFalse(AircraftLinkResource::canViewAny());
        $this->assertFalse(AircraftLinkResource::shouldRegisterNavigation());
    }

    #[Test]
    public function the_member_statuses_page_renders(): void
    {
        MemberStatus::create([
            'msid' => '1',
            'label' => 'aktiv',
            'member_count' => 91,
            'handling' => MemberStatusHandling::Active,
        ]);
        MemberStatus::create(['msid' => '6', 'label' => 'sonstige', 'member_count' => 229]);

        $this->actingAs($this->admin());

        Livewire::test(MemberStatusesPage::class)
            ->assertSuccessful()
            ->assertSee('sonstige')
            ->assertSee(__('vereinsflieger.status.undecided'));
    }

    /**
     * Und die Entscheidung wirkt.
     *
     * Der Knopf ist die einzige Stelle, an der ein Mensch über den Zugang
     * ganzer Gruppen bestimmt -- er muss tun, was draufsteht.
     */
    #[Test]
    public function deciding_a_status_from_the_page_works(): void
    {
        $status = MemberStatus::create(['msid' => '6', 'label' => 'sonstige', 'member_count' => 229]);

        $this->actingAs($this->admin());

        Livewire::test(MemberStatusesPage::class)
            ->call('decide', $status->id, MemberStatusHandling::Ignore->value)
            ->assertSuccessful();

        $this->assertSame(MemberStatusHandling::Ignore, $status->fresh()->handling);
    }

    /**
     * Ohne Recht geht nichts -- auch nicht über den Livewire-Aufruf direkt.
     *
     * Ein Riegel, der nur die Schaltfläche versteckt, ist keiner.
     */
    #[Test]
    public function deciding_without_the_permission_is_refused(): void
    {
        $status = MemberStatus::create(['msid' => '6', 'label' => 'sonstige']);

        $this->actingAs(User::factory()->create(['is_active' => true]));

        // canAccess() haelt die Seite zu -- und der Aufruf selbst prueft noch
        // einmal, damit ein Riegel nicht nur die Schaltflaeche versteckt.
        $this->assertFalse(MemberStatusesPage::canAccess());

        try {
            Livewire::test(MemberStatusesPage::class)
                ->call('decide', $status->id, MemberStatusHandling::Active->value);
            $this->fail('Ohne Recht darf nicht entschieden werden.');
        } catch (Throwable) {
            // erwartet
        }

        $this->assertNull($status->fresh()->handling);
    }

    #[Test]
    public function the_pages_are_closed_without_the_permission(): void
    {
        $this->actingAs(User::factory()->create(['is_active' => true]));

        $this->assertFalse(ConnectionResource::canViewAny());
        $this->assertFalse(AircraftLinkResource::canViewAny());
        $this->assertFalse(MemberStatusesPage::canAccess());
    }

    private function admin(): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->givePermissionTo(CorePermissions::SETTINGS_MANAGE);
        $user->givePermissionTo(CorePermissions::ROLES_MANAGE);

        return $user->fresh();
    }
}
