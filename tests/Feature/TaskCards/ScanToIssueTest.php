<?php

declare(strict_types=1);

namespace Tests\Feature\TaskCards;

use App\Core\Modules\ModuleManager;
use App\Modules\TaskCards\Filament\Resources\WorkOrders\Pages\ViewWorkOrder;
use App\Modules\Warehouse\Enums\PartClassification;
use App\Modules\Warehouse\Models\PartType;
use App\Modules\Warehouse\Models\StockLot;
use App\Modules\Warehouse\Models\StorageLocation;
use App\Modules\Warehouse\Support\ScanCode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Ein Scan an der Teileentnahme — und was er der Freigabe erspart.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * Vorgabe: „Techniker holt teil aus schrank, scannt es und muss nicht weiter
 * suchen und nummern tippen. Außerdem haben wir damit automatisch zur Freigabe
 * die richtigen form 1."
 *
 * DER ZWEITE SATZ IST DER WICHTIGE. Ohne Scan wählt ein Mensch das Los aus
 * einer Liste — oder lässt das Feld leer, dann greift FEFO und nimmt das
 * älteste. FEFO ist eine ANNAHME darüber, welche Packung in der Hand lag. Griff
 * er die danebenliegende, hängt an der Freigabe das falsche Form 1, und
 * niemand merkt es: Die Buchung sieht plausibel aus.
 *
 * Der Scan ersetzt die Annahme durch eine Beobachtung.
 * ─────────────────────────────────────────────────────────────────────────────
 */
final class ScanToIssueTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        app(ModuleManager::class)->enable('warehouse');
        app(ModuleManager::class)->enable('taskcards');
        app(ModuleManager::class)->forgetCache();
    }

    /**
     * EIN SCAN, ZWEI FELDER.
     *
     * Ein Los weiß, zu welchem Bauteil es gehört — also muss niemand erst den
     * Bauteiltyp suchen, um danach das Los zu suchen.
     */
    #[Test]
    public function a_scanned_lot_fills_part_and_lot(): void
    {
        $lot = $this->lot('EASA-12345');

        $gesetzt = $this->apply(ScanCode::forLot('EASA-12345'));

        $this->assertSame($lot->part_type_id, $gesetzt['part_type_id'] ?? null);
        $this->assertSame($lot->getKey(), $gesetzt['stock_lot_id'] ?? null);
    }

    /**
     * Und die abgetippte Nummer tut dasselbe.
     *
     * Der Aufdruck eines Thermodruckers verblasst; die Losnummer steht im
     * Klartext auf dem Etikett, damit sie abgeschrieben werden kann. Wäre das
     * ein zweitklassiger Weg, wäre das Etikett nach zwei Jahren wertlos.
     */
    #[Test]
    public function a_typed_lot_number_fills_the_same_fields(): void
    {
        $lot = $this->lot('EASA-12345');

        $gesetzt = $this->apply('EASA-12345');

        $this->assertSame($lot->part_type_id, $gesetzt['part_type_id'] ?? null);
        $this->assertSame($lot->getKey(), $gesetzt['stock_lot_id'] ?? null);
    }

    /**
     * Ein fremder Code setzt NICHTS.
     *
     * Das ist die wichtigere Hälfte: Ein Scanner, der bei einem Paketaufkleber
     * irgendein Los einträgt, ist schlimmer als keiner.
     */
    #[Test]
    public function a_foreign_code_changes_nothing(): void
    {
        $this->lot('EASA-12345');

        $this->assertSame([], $this->apply('https://example.org/etwas'));
    }

    /**
     * Ein Code von hier, dessen Los es nicht mehr gibt, ebenfalls nicht.
     */
    #[Test]
    public function an_unknown_lot_changes_nothing(): void
    {
        $this->assertSame([], $this->apply(ScanCode::forLot('GIBT-ES-NICHT')));
    }

    /**
     * Und ein REGALSCHILD auch nicht — obwohl es ein gültiger Code ist.
     *
     * Der Fall passiert im Lager wirklich: Man steht vor dem Regal, hält die
     * Kamera aufs nächstbeste Etikett und hat das Schild erwischt. Still ein
     * falsches Los zu setzen wäre hier der teuerste denkbare Fehler.
     */
    #[Test]
    public function a_shelf_sign_is_not_a_lot(): void
    {
        $ort = StorageLocation::create(['name' => 'Regal B-12']);

        $this->assertSame([], $this->apply(ScanCode::forLocation($ort->id)));
    }

    /**
     * Wendet einen Code an und gibt zurück, was dabei gesetzt wurde.
     *
     * @return array<string, mixed>
     */
    private function apply(string $code): array
    {
        $gesetzt = [];

        ViewWorkOrder::applyScannedCode(
            $code,
            function (string $feld, mixed $wert) use (&$gesetzt): void {
                $gesetzt[$feld] = $wert;
            },
        );

        return $gesetzt;
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
