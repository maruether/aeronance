<?php

declare(strict_types=1);

namespace Tests\Feature\Fleet;

use App\Core\Access\AccessSetup;
use App\Core\Modules\ModuleManager;
use App\Models\User;
use App\Modules\Fleet\Enums\ManualKind;
use App\Modules\Fleet\Models\AircraftType;
use App\Modules\Fleet\Models\MaintenanceManual;
use App\Modules\Fleet\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Die Datei an der Wartungsunterlage -- die Ablage gab es, den Weg hinein nicht.
 *
 * Feldtest: "ok, und wie bekomm ich die da von hand rein?" Die Antwort war:
 * gar nicht -- das Modell hatte die Dokumentsammlung, aber kein Formular bot
 * sie an und keine Route lieferte sie aus. Seit dem Fix: Upload im Formular
 * und im "Neue Revision"-Dialog, Auslieferung nur über die auth-geprüfte
 * Route, private Disk, wie jeder Nachweis.
 */
final class MaintenanceManualFileTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        app(ModuleManager::class)->enable('fleet');
        app(ModuleManager::class)->forgetCache();
        app(AccessSetup::class)->run();
    }

    #[Test]
    public function the_file_is_served_only_with_the_fleet_permission(): void
    {
        $unterlage = $this->manualWithFile();
        $url = route('fleet.manual.file', ['manual' => $unterlage]);

        // Ohne Sitzung: Umleitung zur Anmeldung.
        $this->get($url)->assertRedirect();

        // Angemeldet, aber ohne Flottenrecht: ausdrücklich nein.
        $this->actingAs(User::factory()->create(['is_active' => true]));
        $this->get($url)->assertForbidden();

        // Mit fleet.view: die Datei kommt.
        $betrachter = User::factory()->create(['is_active' => true]);
        $betrachter->givePermissionTo(Permissions::FLEET_VIEW);
        $this->actingAs($betrachter->fresh());

        $this->get($url)->assertSuccessful();
    }

    #[Test]
    public function an_entry_without_a_file_answers_not_found(): void
    {
        // Weiterhin erlaubt: der Eintrag als Verweis auf den Papierordner.
        $unterlage = $this->manual();

        $betrachter = User::factory()->create(['is_active' => true]);
        $betrachter->givePermissionTo(Permissions::FLEET_VIEW);
        $this->actingAs($betrachter->fresh());

        $this->get(route('fleet.manual.file', ['manual' => $unterlage]))->assertNotFound();
    }

    #[Test]
    public function a_second_upload_replaces_the_first(): void
    {
        // singleFile: Jeder Eintrag IST eine Revision, und eine Revision hat
        // genau ihr Dokument -- zwei Dateien an einem Stand wären zwei Stände.
        $unterlage = $this->manualWithFile();

        $pfad = tempnam(sys_get_temp_dir(), 'man');
        file_put_contents($pfad, '%PDF-1.4 zweite Fassung');

        $unterlage->addMedia($pfad)
            ->usingFileName('zweite.pdf')
            ->toMediaCollection(MaintenanceManual::DOCUMENTS);

        $this->assertCount(1, $unterlage->fresh()->getMedia(MaintenanceManual::DOCUMENTS));
    }

    private function manual(): MaintenanceManual
    {
        $muster = AircraftType::create(['designation' => 'ASK 21', 'manufacturer' => 'Schleicher']);

        return MaintenanceManual::create([
            'aircraft_type_id' => $muster->id,
            'kind' => ManualKind::Maintenance,
            'title' => 'Wartungshandbuch ASK 21',
            'revision' => 'Rev. 12',
        ]);
    }

    private function manualWithFile(): MaintenanceManual
    {
        $unterlage = $this->manual();

        $pfad = tempnam(sys_get_temp_dir(), 'man');
        file_put_contents($pfad, '%PDF-1.4 test');

        $unterlage->addMedia($pfad)
            ->usingFileName('whb.pdf')
            ->toMediaCollection(MaintenanceManual::DOCUMENTS);

        return $unterlage->fresh();
    }
}
