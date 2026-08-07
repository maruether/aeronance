<?php

declare(strict_types=1);

namespace Tests\Feature\Warehouse;

use App\Modules\Warehouse\Enums\PartClassification;
use App\Modules\Warehouse\Models\PartType;
use App\Modules\Warehouse\Models\StockLot;
use App\Modules\Warehouse\Support\DocumentTypes;
use App\Modules\Warehouse\Support\UnitsOfMeasure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Einheiten (F17) und Dokumentarten (F5) -- Auswahl mit Ventil.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * Beides folgt demselben Muster: eine feste Liste, damit nicht drei
 * Schreibweisen desselben Dings nebeneinander stehen, und ein Weg für den Fall,
 * den die Liste nicht kennt -- damit niemand ausweicht und die Wahrheit in ein
 * Bemerkungsfeld schreibt.
 *
 * Der Unterschied liegt in der Folge: Eine unbekannte Einheit ist harmlos. Eine
 * unbekannte Dokumentart darf NIEMALS als Form-1-Nachweis durchgehen, und
 * genau das prüft die zweite Hälfte dieser Datei.
 * ─────────────────────────────────────────────────────────────────────────────
 */
final class UnitsAndDocumentTypesTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function the_list_covers_more_than_pieces(): void
    {
        $alle = UnitsOfMeasure::all();

        // Vorgabe: "wir muessen aber alles abdecken."
        foreach (['St', 'm', 'l', 'kg', 'cm²', 'Satz'] as $erwartet) {
            $this->assertArrayHasKey($erwartet, $alle);
        }
    }

    /**
     * Imperiale Masse sind keine Zierde.
     *
     * Luftfahrtteile kommen in Zoll und Fuss -- Schlauchdurchmesser,
     * Kabellängen, Blechstärken. Wer nur metrisch anbietet, zwingt zum
     * Umrechnen von Hand, und genau da entstehen Fehler.
     */
    #[Test]
    public function imperial_units_are_offered(): void
    {
        $alle = UnitsOfMeasure::all();

        $this->assertArrayHasKey('in', $alle);
        $this->assertArrayHasKey('ft', $alle);
        $this->assertArrayHasKey('lb', $alle);
    }

    /**
     * Eine eigene Einheit verschwindet nicht nach dem Speichern.
     *
     * Sonst ersetzte das nächste Bearbeiten sie stillschweigend durch eine
     * andere -- und aus „Rolle" würde „St", ohne dass es jemand bemerkt.
     */
    #[Test]
    public function an_own_unit_survives_in_the_list(): void
    {
        $gruppen = UnitsOfMeasure::optionsIncluding('Rolle');

        $flach = array_merge(...array_values($gruppen));

        $this->assertArrayHasKey('Rolle', $flach);
        $this->assertFalse(UnitsOfMeasure::isKnown('Rolle'));
    }

    #[Test]
    public function a_known_unit_does_not_get_a_second_entry(): void
    {
        $gruppen = UnitsOfMeasure::optionsIncluding('St');

        $this->assertArrayNotHasKey(__('warehouse.unit_group.own'), $gruppen);
    }

    // ── Dokumentarten ────────────────────────────────────────────────────────

    /**
     * DIE GRENZE, UM DIE ES GEHT.
     *
     * Wer „Form 1" von Hand einträgt, bekommt ein Papier mit dieser Aufschrift
     * -- keinen Nachweis. Für den Menschen dasselbe Wort, für das System ein
     * anderer Wert; ein Los, das nach Nachweis aussieht und keinen hat, ist
     * genau der Zustand, den ML.A.504 verhindern will.
     */
    #[Test]
    public function a_hand_written_form_one_is_not_a_form_one(): void
    {
        $wert = DocumentTypes::custom('Form 1');

        $this->assertNotSame(StockLot::DOCUMENT_FORM_ONE, $wert);
        $this->assertTrue(DocumentTypes::isCustom($wert));

        $lot = $this->lotMit($wert, 'ABC-123');

        $this->assertFalse($lot->hasRequiredDocument(), 'Darf nicht als Nachweis zählen.');
    }

    #[Test]
    public function the_real_form_one_still_counts(): void
    {
        $lot = $this->lotMit(StockLot::DOCUMENT_FORM_ONE, 'ABC-123');

        $this->assertTrue($lot->hasRequiredDocument());
    }

    #[Test]
    public function a_form_one_without_a_number_is_no_evidence(): void
    {
        // Block 12/13 ist das, was das Papier auffindbar macht. Ohne Nummer ist
        // die Angabe eine Behauptung ohne Beleg.
        $lot = $this->lotMit(StockLot::DOCUMENT_FORM_ONE, null);

        $this->assertFalse($lot->hasRequiredDocument());
    }

    #[Test]
    public function the_own_name_is_what_is_shown(): void
    {
        $wert = DocumentTypes::custom('Werksbescheinigung');

        $this->assertSame('Werksbescheinigung', DocumentTypes::label($wert));
    }

    #[Test]
    public function feeding_a_stored_value_back_in_does_not_double_the_prefix(): void
    {
        $einmal = DocumentTypes::custom('Werksbescheinigung');
        $zweimal = DocumentTypes::custom($einmal);

        $this->assertSame($einmal, $zweimal);
    }

    /**
     * Einmal benannte Papiere tauchen wieder auf.
     *
     * Sonst schriebe jeder sie neu und leicht anders, und aus einem Papier
     * würden fünf -- genau das, was die feste Liste verhindern soll.
     */
    #[Test]
    public function a_name_used_once_is_offered_again(): void
    {
        $wert = DocumentTypes::custom('Werksbescheinigung');
        $this->lotMit($wert, 'X-1');

        $this->assertArrayHasKey($wert, DocumentTypes::options());
    }

    #[Test]
    public function the_fixed_three_are_always_there(): void
    {
        $auswahl = DocumentTypes::options();

        $this->assertArrayHasKey(StockLot::DOCUMENT_FORM_ONE, $auswahl);
        $this->assertArrayHasKey(StockLot::DOCUMENT_CERTIFICATE_OF_CONFORMITY, $auswahl);
        $this->assertArrayHasKey(StockLot::DOCUMENT_NONE, $auswahl);
    }

    /**
     * Ein eigenes Papier passt in die Spalte.
     *
     * document_type hält 32 Zeichen. Ohne Kürzung schlüge das Speichern erst in
     * der Datenbank fehl -- mit einer Meldung, die niemandem sagt, was zu tun
     * ist.
     */
    #[Test]
    public function an_overlong_name_still_fits_the_column(): void
    {
        $wert = DocumentTypes::custom(str_repeat('X', 100));

        $this->assertLessThanOrEqual(32, mb_strlen($wert));

        // Und es laesst sich wirklich speichern.
        $lot = $this->lotMit($wert, 'X-2');
        $this->assertSame($wert, $lot->fresh()->document_type);
    }

    private function lotMit(string $typ, ?string $referenz): StockLot
    {
        $part = PartType::firstOrCreate(
            ['name' => 'Prüfteil'],
            [
                'classification' => PartClassification::Component,
                'unit_of_measure' => 'St',
                'requires_form_one' => true,
            ],
        );

        $lot = StockLot::create([
            'part_type_id' => $part->id,
            'lot_number' => 'L-'.mb_substr(md5($typ.$referenz), 0, 10),
            'document_type' => $typ,
            'document_reference' => $referenz,
            'received_at' => '2026-08-04',
        ]);

        return $lot->load('partType');
    }
}
