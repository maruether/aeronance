<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Warehouse;

use App\Modules\Warehouse\Enums\PartClassification;
use App\Modules\Warehouse\Models\PartType;
use App\Modules\Warehouse\Models\StockLot;
use App\Modules\Warehouse\Support\LotNumber;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Die Losnummer kommt vom Form 1, wo es eines gibt.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * Vorgabe: „Als losnummer hätte ich gerne, soweit vorhanden, die Nummer vom
 * Form 1. Wenn nicht müssen wir eine andere nehmen."
 *
 * Wer im Regal steht, liest die Nummer ab und findet sie auf dem Papier wieder
 * -- ohne Umweg über eine zweite, hausgemachte Nummer, die nur dieses System
 * kennt.
 * ─────────────────────────────────────────────────────────────────────────────
 */
final class LotNumberTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function the_form_one_number_becomes_the_lot_number(): void
    {
        $this->assertSame(
            'EASA-24-0815',
            LotNumber::forNewLot('2026-08-04', 'EASA-24-0815'),
        );
    }

    #[Test]
    public function without_a_document_it_falls_back_to_the_running_number(): void
    {
        $this->assertSame('202608-001', LotNumber::forNewLot('2026-08-04', null));
    }

    #[Test]
    public function an_empty_reference_is_no_reference(): void
    {
        // Ein leeres Feld, das durchgereicht wird, darf keine Losnummer "" ergeben.
        $this->assertSame('202608-001', LotNumber::forNewLot('2026-08-04', '   '));
    }

    /**
     * Der Fall, der das Ganze überhaupt kompliziert macht.
     *
     * Die Blöcke 6 bis 12 des Form 1 sind eine TABELLE -- ein Zertifikat kann
     * mehrere Positionen tragen, und jede wird hier ein eigenes Los. Die
     * Losnummer ist aber eindeutig, weil sie auf dem Aufkleber steht.
     */
    #[Test]
    public function a_second_lot_on_the_same_form_one_gets_a_counter(): void
    {
        $this->lotMit('EASA-24-0815');

        $this->assertSame('EASA-24-0815-2', LotNumber::forNewLot('2026-08-04', 'EASA-24-0815'));

        $this->lotMit('EASA-24-0815-2');

        $this->assertSame('EASA-24-0815-3', LotNumber::forNewLot('2026-08-04', 'EASA-24-0815'));
    }

    /**
     * Form-1-Nummern sind nur BEIM AUSSTELLER eindeutig.
     *
     * Zwei Betriebe dürfen dieselbe schlichte Nummer vergeben, und irgendwann
     * treffen sich die beiden im selben Lager.
     */
    #[Test]
    public function the_same_plain_number_from_two_issuers_does_not_collide(): void
    {
        $this->lotMit('12345');

        $this->assertSame('12345-2', LotNumber::forNewLot('2026-08-04', '12345'));
    }

    #[Test]
    public function a_deleted_lot_still_holds_its_number(): void
    {
        // Sie steht in Bewegungen und womöglich in einer Freigabe -- zweimal
        // dieselbe Nummer in derselben Akte wäre schlimmer als eine Lücke.
        $lot = $this->lotMit('EASA-24-0815');
        $lot->delete();

        $this->assertSame('EASA-24-0815-2', LotNumber::forNewLot('2026-08-04', 'EASA-24-0815'));
    }

    #[Test]
    public function a_reference_that_is_too_long_is_shortened(): void
    {
        $lang = str_repeat('A', 100);

        $nummer = LotNumber::forNewLot('2026-08-04', $lang);

        $this->assertLessThanOrEqual(32, mb_strlen($nummer));
    }

    /**
     * Zwei verschiedene lange Nummern dürfen nicht dieselbe kurze werden.
     *
     * Sonst hinge an einem Aufkleber die Behauptung, zwei Lieferungen seien
     * dieselbe.
     */
    #[Test]
    public function two_long_references_stay_apart(): void
    {
        // Gleicher Anfang, verschiedenes Ende -- gekürzt wären beide dasselbe.
        $a = str_repeat('A', 40).'ENDE-1';
        $b = str_repeat('A', 40).'ENDE-2';

        $ersteNummer = LotNumber::forNewLot('2026-08-04', $a);
        $this->lotMit($ersteNummer);

        $zweiteNummer = LotNumber::forNewLot('2026-08-04', $b);

        $this->assertNotSame($ersteNummer, $zweiteNummer);
        $this->assertLessThanOrEqual(32, mb_strlen($zweiteNummer));
    }

    /**
     * Schrägstriche und Punkte bleiben.
     *
     * "24/0815" und "240815" sind zwei verschiedene Nummern; sie
     * gleichzumachen wäre schlimmer als ein ungewohntes Zeichen.
     */
    #[Test]
    public function separators_inside_the_number_survive(): void
    {
        $this->assertSame('24/0815.3', LotNumber::forNewLot('2026-08-04', '24/0815.3'));
    }

    #[Test]
    public function the_running_number_ignores_form_one_numbers(): void
    {
        // Eine Form-1-Nummer, die zufällig wie der eigene Kreis aussieht, darf
        // den Zähler nicht verstellen.
        $this->lotMit('202608-777');

        $this->assertSame('202608-778', LotNumber::forNewLot('2026-08-04', null));

        $this->lotMit('202608-XYZ');

        $this->assertSame('202608-778', LotNumber::forNewLot('2026-08-04', null));
    }

    private function lotMit(string $nummer): StockLot
    {
        return StockLot::create([
            'part_type_id' => $this->partType()->id,
            'lot_number' => $nummer,
            'received_at' => '2026-08-04',
        ]);
    }

    private function partType(): PartType
    {
        return PartType::firstOrCreate(
            ['name' => 'Testteil'],
            ['classification' => PartClassification::Component, 'unit_of_measure' => 'St'],
        );
    }
}
