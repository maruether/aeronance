<?php

declare(strict_types=1);

namespace Tests\Feature\Fleet;

use App\Core\Access\AccessSetup;
use App\Core\Modules\ModuleManager;
use App\Models\User;
use App\Modules\Fleet\Enums\DocumentType;
use App\Modules\Fleet\Models\Aircraft;
use App\Modules\Fleet\Models\AircraftDocument;
use App\Modules\Fleet\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Die Datei am Luftfahrzeug-Dokument -- Upload war versprochen, kam aber nie.
 *
 * Feldtest: "Dokumente können nicht hochgeladen werden." Der Dialog nahm nur
 * Metadaten an; seit dem Fix haengt die Datei als Medium am Datensatz und
 * kommt ausschliesslich ueber die auth-gepruefte Route heraus -- private
 * Disk, wie jeder Nachweis.
 */
final class AircraftDocumentFileTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Das Flottenrecht existiert erst mit aktivem Modul.
        app(ModuleManager::class)->enable('fleet');
        app(ModuleManager::class)->forgetCache();
        app(AccessSetup::class)->run();
    }

    #[Test]
    public function the_file_is_served_only_with_the_fleet_permission(): void
    {
        $dokument = $this->documentWithFile();
        $url = route('fleet.document.file', $dokument);

        // Ohne Sitzung: kein Nachweis. Umleitung zur Anmeldung.
        $this->get($url)->assertRedirect();

        // Angemeldet, aber ohne Flottenrecht: ausdruecklich nein.
        $this->actingAs(User::factory()->create(['is_active' => true]));
        $this->get($url)->assertForbidden();

        // Mit fleet.view: die Datei kommt.
        $betrachter = User::factory()->create(['is_active' => true]);
        $betrachter->givePermissionTo(Permissions::FLEET_VIEW);
        $this->actingAs($betrachter->fresh());

        $this->get($url)->assertSuccessful();
    }

    #[Test]
    public function a_metadata_only_document_has_no_file_to_serve(): void
    {
        // Nur Frist, Papier im Ordner -- weiterhin erlaubt, siehe Modell.
        $dokument = $this->document();

        $betrachter = User::factory()->create(['is_active' => true]);
        $betrachter->givePermissionTo(Permissions::FLEET_VIEW);
        $this->actingAs($betrachter->fresh());

        $this->get(route('fleet.document.file', $dokument))->assertNotFound();
    }

    private function document(): AircraftDocument
    {
        $aircraft = Aircraft::create([
            'registration' => 'D-K'.strtoupper(substr(uniqid(), -4)),
            'model' => 'ASK 21',
        ]);

        return AircraftDocument::create([
            'aircraft_id' => $aircraft->id,
            'type' => DocumentType::WeighingReport,
            'title' => 'Wägebericht 2026',
        ]);
    }

    private function documentWithFile(): AircraftDocument
    {
        $dokument = $this->document();

        $pfad = tempnam(sys_get_temp_dir(), 'doc');
        file_put_contents($pfad, '%PDF-1.4 test');

        $dokument->addMedia($pfad)
            ->usingFileName('waegebericht.pdf')
            ->toMediaCollection(AircraftDocument::FILE);

        return $dokument->fresh();
    }
}
