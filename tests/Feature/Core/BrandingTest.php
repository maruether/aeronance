<?php

declare(strict_types=1);

namespace Tests\Feature\Core;

use App\Core\Access\AccessSetup;
use App\Core\Access\CoreRoles;
use App\Core\Version;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Wer bin ich, und welche Fassung läuft? -- Werkzeugname und Version im Panel.
 *
 * Feldtest: "die Versionsnummer angezeigt … und wenn ein Organisationsname
 * eingetragen ist dieser oben links mit einem '-' hinter dem Aeronance".
 * Vorher stand oben links NUR der Vereinsname (auf frischer Installation:
 * nichts), und die laufende Fassung war nur Administratoren im
 * Dashboard-Hinweis sichtbar.
 */
final class BrandingTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function the_brand_carries_the_organisation_name(): void
    {
        config(['aeronance.organisation.name' => 'Akaflieg Freiburg e.V.']);

        $this->dashboard()->assertSee('Aeronance - Akaflieg Freiburg e.V.');
    }

    #[Test]
    public function without_a_name_the_tool_stands_alone(): void
    {
        config(['aeronance.organisation.name' => null]);

        $antwort = $this->dashboard();

        $antwort->assertSee('Aeronance');
        $antwort->assertDontSee('Aeronance - ');
    }

    #[Test]
    public function the_running_version_sits_in_the_user_menu(): void
    {
        // Im Entwicklungsstand und in der CI gibt es keine VERSION-Datei --
        // dann steht dort ehrlich "Entwicklungsstand". Über label() statt
        // einer festen Nummer bleibt der Test in jeder Umgebung wahr.
        $this->dashboard()->assertSee('Aeronance '.Version::label());
    }

    private function dashboard()
    {
        app(AccessSetup::class)->run();

        $admin = User::factory()->create(['is_active' => true]);
        $admin->assignRole(CoreRoles::ADMIN);

        return $this->actingAs($admin->fresh())->get('/verwaltung')->assertOk();
    }
}
