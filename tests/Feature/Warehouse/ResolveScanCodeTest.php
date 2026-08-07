<?php

declare(strict_types=1);

namespace Tests\Feature\Warehouse;

use App\Modules\Warehouse\Actions\ResolveScanCode;
use App\Modules\Warehouse\Enums\PartClassification;
use App\Modules\Warehouse\Models\PartType;
use App\Modules\Warehouse\Models\StockLot;
use App\Modules\Warehouse\Models\StorageLocation;
use App\Modules\Warehouse\Support\ScanCode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Was ein gescannter Code bedeutet.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * Drei Antworten, und der Test hält vor allem fest, dass sie
 * AUSEINANDERGEHALTEN werden: „fremder Code" und „kennen wir nicht" sind zwei
 * verschiedene Auskünfte an jemanden, der im Lager steht. Ein gemeinsames
 * „geht nicht" liesse ihn raten, ob er falsch gescannt hat oder ob etwas fehlt.
 * ─────────────────────────────────────────────────────────────────────────────
 */
final class ResolveScanCodeTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function a_lot_code_finds_the_lot(): void
    {
        $lot = $this->lot('EASA-12345');

        $ergebnis = $this->resolve(ScanCode::forLot('EASA-12345'));

        $this->assertSame(ResolveScanCode::OK, $ergebnis['status']);
        $this->assertSame(ScanCode::KIND_LOT, $ergebnis['kind']);
        $this->assertTrue($lot->is($ergebnis['record']));
    }

    #[Test]
    public function a_location_code_finds_the_location(): void
    {
        $ort = StorageLocation::create(['name' => 'Regal B-12']);

        $ergebnis = $this->resolve(ScanCode::forLocation($ort->id));

        $this->assertSame(ResolveScanCode::OK, $ergebnis['status']);
        $this->assertSame(ScanCode::KIND_LOCATION, $ergebnis['kind']);
        $this->assertTrue($ort->is($ergebnis['record']));
    }

    /**
     * DER RÜCKFALLWEG, und er ist kein Randfall.
     *
     * Thermodruck verblasst, Etiketten bekommen Öl ab. Wer den Code nicht
     * lesen kann, tippt die Losnummer ab, die im Klartext daneben steht — und
     * muss an derselben Stelle landen. Diese Eingabe als „fremden Code"
     * abzuweisen wäre die unfreundlichste denkbare Antwort.
     */
    #[Test]
    public function a_typed_lot_number_works_just_as_well(): void
    {
        $lot = $this->lot('EASA-12345');

        $ergebnis = $this->resolve('EASA-12345');

        $this->assertSame(ResolveScanCode::OK, $ergebnis['status']);
        $this->assertTrue($lot->is($ergebnis['record']));
    }

    /**
     * Fremdes bleibt fremd — auch wenn es wie ein Code aussieht.
     */
    #[Test]
    public function a_foreign_code_is_reported_as_foreign(): void
    {
        foreach (['https://example.org/lot/12', 'WIFI:S:Werkstatt;;', 'irgendwas'] as $eingabe) {
            $ergebnis = $this->resolve($eingabe);

            $this->assertSame(
                ResolveScanCode::FOREIGN,
                $ergebnis['status'],
                sprintf('"%s" ist kein Code von hier.', $eingabe),
            );
        }
    }

    /**
     * Unser Code, aber der Datensatz ist weg — das ist ein Befund, kein Fehler.
     *
     * Ein Etikett überlebt das Los, an dem es klebte. Wer es scannt, soll
     * erfahren, dass es dieses Los nicht mehr gibt, und nicht dass sein
     * Scanner kaputt sei.
     */
    #[Test]
    public function our_code_for_something_gone_is_reported_as_unknown(): void
    {
        $ergebnis = $this->resolve(ScanCode::forLot('GIBT-ES-NICHT'));

        $this->assertSame(ResolveScanCode::UNKNOWN, $ergebnis['status']);
        $this->assertSame(ScanCode::KIND_LOT, $ergebnis['kind'], 'Die Art ist bekannt, nur der Datensatz nicht.');
        $this->assertNull($ergebnis['record']);

        $ergebnis = $this->resolve(ScanCode::forLocation(9999));

        $this->assertSame(ResolveScanCode::UNKNOWN, $ergebnis['status']);
        $this->assertSame(ScanCode::KIND_LOCATION, $ergebnis['kind']);
    }

    /**
     * @return array{status: string, kind: ?string, record: mixed}
     */
    private function resolve(string $raw): array
    {
        return app(ResolveScanCode::class)->handle($raw);
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
