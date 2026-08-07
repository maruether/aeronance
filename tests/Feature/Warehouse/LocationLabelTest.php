<?php

declare(strict_types=1);

namespace Tests\Feature\Warehouse;

use App\Core\Access\AccessSetup;
use App\Core\Modules\ModuleManager;
use App\Models\User;
use App\Modules\Warehouse\Models\StorageLocation;
use App\Modules\Warehouse\Permissions;
use App\Modules\Warehouse\Support\ScanCode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Das Regalschild — und warum darauf keine Adresse steht.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * Vorgabe: „wenn dann eher was das sich mit der handy kamera scannen lässt
 * zwecks inventur" — und auf die Frage nach der Adresse im Code: „warum eine
 * adresse? ich dachte eher daran das aeronance selbst einen scanner aufmacht
 * und somit darin nur Infos sind die das tool braucht."
 *
 * Der Inventurbildschirm arbeitet ORTSWEISE, aufgebaut wie die gedruckte
 * Zählliste. Der Code am Regal trifft damit genau den langsamen Schritt: Statt
 * den Ort aus einer Liste zu suchen, scannt man das Schild, vor dem man steht.
 * ─────────────────────────────────────────────────────────────────────────────
 */
final class LocationLabelTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        app(AccessSetup::class)->run();
        app(ModuleManager::class)->enable('warehouse');
        app(ModuleManager::class)->forgetCache();
    }

    #[Test]
    public function the_sign_carries_the_name_and_a_code(): void
    {
        $ort = StorageLocation::create(['name' => 'Regal B-12', 'description' => 'Kleinteile Halle 2']);

        $seite = $this->printedPage();

        $this->assertStringContainsString('Regal B-12', $seite);
        $this->assertStringContainsString('Kleinteile Halle 2', $seite);
        $this->assertStringContainsString('<svg', $seite, 'Ohne QR-Code ist das Schild kein Schild.');
        $this->assertSame('AER1:S:'.$ort->id, ScanCode::forLocation($ort->id));
    }

    /**
     * DER TEST, UM DEN ES GEHT: keine Adresse auf dem Schild.
     *
     * Ein Regalschild hängt sichtbar in der Halle. Stünde eine URL darauf,
     * hätte jeder, der es fotografiert, die Adresse dieser Instanz — und ein
     * Aufkleber, der Jahre klebt, überlebte keinen Domainwechsel.
     */
    #[Test]
    public function no_address_reaches_the_sign(): void
    {
        StorageLocation::create(['name' => 'Regal B-12']);

        $etikett = Str::after($this->printedPage(), 'class="label"');

        /*
         * Geprueft wird das SCHILD, nicht die Seite: Der Bildschirmhinweis
         * darueber verlinkt den Kalibrierbogen, und das ist eine echte
         * Adresse -- gedruckt wird sie nicht.
         *
         * Dass der Code selbst keine Adresse traegt, prueft ScanCodeTest.
         */
        $this->assertStringNotContainsString('href', $etikett, 'Auf dem Schild darf kein Verweis stehen.');
    }

    /**
     * Ohne Angabe kommen alle — Schilder druckt man einmal fürs ganze Lager.
     *
     * Das ist der Unterschied zum Losaufkleber: Dort wäre „alle" ein
     * Rollendrucker, der einige hundert Etiketten auswirft.
     */
    #[Test]
    public function without_a_selection_every_location_is_printed(): void
    {
        StorageLocation::create(['name' => 'Regal A']);
        StorageLocation::create(['name' => 'Regal B']);

        $seite = $this->printedPage();

        $this->assertStringContainsString('Regal A', $seite);
        $this->assertStringContainsString('Regal B', $seite);
    }

    #[Test]
    public function a_single_location_can_be_asked_for(): void
    {
        $eins = StorageLocation::create(['name' => 'Regal A']);
        StorageLocation::create(['name' => 'Regal B']);

        $seite = $this->printedPage(['locations' => $eins->getKey()]);

        $this->assertStringContainsString('Regal A', $seite);
        $this->assertStringNotContainsString('Regal B', $seite);
    }

    #[Test]
    public function without_the_permission_no_sign(): void
    {
        StorageLocation::create(['name' => 'Regal A']);

        $this->actingAs(User::factory()->create(['is_active' => true]))
            ->get(route('warehouse.label.locations'))
            ->assertForbidden();
    }

    #[Test]
    public function nothing_is_served_while_the_module_is_off(): void
    {
        StorageLocation::create(['name' => 'Regal A']);
        $leser = $this->stockReader();

        app(ModuleManager::class)->disable('warehouse');
        app(ModuleManager::class)->forgetCache();

        $this->actingAs($leser)
            ->get(route('warehouse.label.locations'))
            ->assertNotFound();
    }

    /**
     * @param  array<string, mixed>  $query
     */
    private function printedPage(array $query = []): string
    {
        $antwort = $this->actingAs($this->stockReader())
            ->get(route('warehouse.label.locations', $query))
            ->assertSuccessful();

        // Nur der Koerper -- der Stilblock traegt Kommentare, und die sind
        // nicht, was jemand auf dem Schild liest. Siehe LotLabelTest.
        return Str::after((string) $antwort->getContent(), '<body>');
    }

    private function stockReader(): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->givePermissionTo(Permissions::STOCK_VIEW);

        return $user->fresh();
    }
}
