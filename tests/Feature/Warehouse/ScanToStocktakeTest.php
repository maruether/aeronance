<?php

declare(strict_types=1);

namespace Tests\Feature\Warehouse;

use App\Core\Modules\ModuleManager;
use App\Models\User;
use App\Modules\Warehouse\Enums\PartClassification;
use App\Modules\Warehouse\Filament\Pages\StocktakePage;
use App\Modules\Warehouse\Models\PartType;
use App\Modules\Warehouse\Models\StockLot;
use App\Modules\Warehouse\Models\StorageLocation;
use App\Modules\Warehouse\Permissions;
use App\Modules\Warehouse\Support\ScanCode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Das Regalschild scannen, statt den Ort zu suchen.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * Vorgabe: „wenn dann eher was das sich mit der handy kamera scannen lässt
 * zwecks inventur."
 *
 * Der Inventurbildschirm arbeitet ORTSWEISE, aufgebaut wie die gedruckte
 * Zählliste. Der langsame Schritt ist deshalb nicht das Zählen, sondern das
 * Heraussuchen des Ortes aus einer Liste — während man vor genau diesem Regal
 * steht.
 * ─────────────────────────────────────────────────────────────────────────────
 */
final class ScanToStocktakeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Permission::findOrCreate(Permissions::STOCK_CORRECT, 'web');

        app(ModuleManager::class)->enable('warehouse');
        app(ModuleManager::class)->forgetCache();
    }

    #[Test]
    public function a_scanned_shelf_sign_selects_the_location(): void
    {
        $ort = StorageLocation::create(['name' => 'Regal B-12']);

        $seite = $this->page();
        $seite->applyScan(ScanCode::forLocation($ort->id));

        $this->assertSame((string) $ort->getKey(), $seite->location);
    }

    /**
     * EIN LOSAUFKLEBER WECHSELT DEN ORT NICHT.
     *
     * Er ist ein gültiger Code, nur nicht für diese Frage. Still den Ort zu
     * wechseln, weil jemand aufs falsche Etikett gehalten hat, hiesse mitten in
     * einer Zählung die Liste unter den Händen zu tauschen — und die halb
     * eingetragenen Mengen gehörten dann zum falschen Regal.
     */
    #[Test]
    public function a_lot_label_does_not_change_the_location(): void
    {
        $ort = StorageLocation::create(['name' => 'Regal B-12']);
        $this->lot('EASA-12345');

        $seite = $this->page();
        $seite->location = (string) $ort->getKey();

        $seite->applyScan(ScanCode::forLot('EASA-12345'));

        $this->assertSame((string) $ort->getKey(), $seite->location, 'Der Ort muss stehen bleiben.');
    }

    #[Test]
    public function a_foreign_code_changes_nothing(): void
    {
        $seite = $this->page();
        $seite->location = '';

        $seite->applyScan('https://example.org/etwas');

        $this->assertSame('', $seite->location);
    }

    #[Test]
    public function an_unknown_location_changes_nothing(): void
    {
        $seite = $this->page();
        $seite->location = '';

        $seite->applyScan(ScanCode::forLocation(9999));

        $this->assertSame('', $seite->location);
    }

    private function page(): StocktakePage
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->givePermissionTo(Permissions::STOCK_CORRECT);

        $this->actingAs($user->fresh());

        return new StocktakePage;
    }

    private function lot(string $nummer): StockLot
    {
        $part = PartType::query()->firstOrCreate(
            ['name' => 'Bremsklotz'],
            ['classification' => PartClassification::StandardPart],
        );

        return StockLot::create([
            'part_type_id' => $part->getKey(),
            'lot_number' => $nummer,
            'received_at' => '2026-01-15',
        ]);
    }
}
