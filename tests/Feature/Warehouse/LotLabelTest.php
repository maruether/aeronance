<?php

declare(strict_types=1);

namespace Tests\Feature\Warehouse;

use App\Core\Access\AccessSetup;
use App\Core\Modules\ModuleManager;
use App\Models\User;
use App\Modules\Warehouse\Enums\PartClassification;
use App\Modules\Warehouse\Models\PartType;
use App\Modules\Warehouse\Models\StockLot;
use App\Modules\Warehouse\Permissions;
use App\Modules\Warehouse\Support\ScanCode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Der Losaufkleber — das Etikett, das am Teil bleibt.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * Vorgabe: „wir brauchen losaufkleber für die Teile. kommen aus dem
 * thermodrucker" — gedacht an Brother DK-Folie.
 *
 * DER ENTWURFSGRUNDSATZ, und er ist das, was diese Datei vor allem festhält:
 * Aufs Etikett kommt nur, was über die Lebensdauer des Loses UNVERÄNDERLICH
 * ist.
 *
 *   Menge     Ein Los wird abgebucht. Eine gedruckte Menge ist beim ersten
 *             Auslagern falsch — und eine falsche Zahl auf einem Aufkleber ist
 *             schlimmer als keine, weil sie geglaubt wird.
 *   Lagerort  Lose werden umgelagert. Der Ort auf dem Etikett schickte jemanden
 *             ins falsche Fach.
 *
 * Beides prüft dieser Test ausdrücklich ab. Es sind die zwei Angaben, die man
 * beim Entwurf eines Lageretiketts als Erstes daraufschreiben will.
 * ─────────────────────────────────────────────────────────────────────────────
 */
final class LotLabelTest extends TestCase
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
    public function the_label_carries_what_identifies_the_lot(): void
    {
        $lot = $this->lot();

        $this->actingAs($this->stockReader())
            ->get(route('warehouse.label.print', ['lots' => $lot->getKey()]))
            ->assertSuccessful()
            ->assertSee($lot->lot_number)
            ->assertSee('Bremsklotz')
            ->assertSee('P/N 4711-01')
            ->assertSee('SN-0815')
            ->assertSee('CH-99')
            ->assertSee('EASA-12345');
    }

    /**
     * DIE MENGE STEHT NICHT DARAUF.
     *
     * Sie ändert sich beim ersten Auslagern, und ein Aufkleber, der eine
     * falsche Zahl behauptet, ist schlimmer als einer ohne Zahl.
     */
    #[Test]
    public function the_quantity_is_not_on_the_label(): void
    {
        $lot = $this->lot();

        $gedruckt = $this->printedPage($lot);

        $this->assertStringNotContainsStringIgnoringCase('Menge', $gedruckt);
        $this->assertStringNotContainsStringIgnoringCase('Bestand', $gedruckt);
    }

    /**
     * Und der Lagerort auch nicht — Lose werden umgelagert.
     */
    #[Test]
    public function the_storage_location_is_not_on_the_label(): void
    {
        $lot = $this->lot();

        $gedruckt = $this->printedPage($lot);

        $this->assertStringNotContainsStringIgnoringCase('Lagerort', $gedruckt);
        $this->assertStringNotContainsStringIgnoringCase('Fach', $gedruckt);
    }

    /**
     * Das Verfallsdatum steht drauf, wenn es eines gibt — und sonst nichts.
     *
     * Ein leerer Kasten „Verfall: —" ist eine Einladung, ihn zu überlesen.
     */
    #[Test]
    public function the_expiry_appears_only_when_there_is_one(): void
    {
        $mit = $this->lot(['expires_at' => '2030-06-30']);
        $ohne = $this->lot(['lot_number' => 'OHNE-1', 'expires_at' => null]);

        $leser = $this->stockReader();

        $this->actingAs($leser)
            ->get(route('warehouse.label.print', ['lots' => $mit->getKey()]))
            ->assertSee('30.06.2030');

        $this->assertStringNotContainsString('Verfall', $this->printedPage($ohne));
    }

    /**
     * Mehrere Lose in einem Aufruf — der Fall nach einer Lieferung.
     */
    #[Test]
    public function several_lots_print_in_one_go(): void
    {
        $eins = $this->lot(['lot_number' => 'AAA-1']);
        $zwei = $this->lot(['lot_number' => 'BBB-2']);

        $this->actingAs($this->stockReader())
            ->get(route('warehouse.label.print', ['lots' => $eins->getKey().','.$zwei->getKey()]))
            ->assertSuccessful()
            ->assertSee('AAA-1')
            ->assertSee('BBB-2');
    }

    /**
     * Ohne Angabe wird nichts gedruckt.
     *
     * „Drucke alles" hieße bei einem gewachsenen Lager einige hundert
     * Etiketten, und ein Rollendrucker fängt sofort an.
     */
    #[Test]
    public function without_a_selection_nothing_is_printed(): void
    {
        $lot = $this->lot();

        $this->actingAs($this->stockReader())
            ->get(route('warehouse.label.print'))
            ->assertSuccessful()
            ->assertDontSee($lot->lot_number);
    }

    /**
     * Die Rolle ist die Voreinstellung, und die Seite IST das Etikett.
     *
     * Geprüft am Seitenformat: Steht dort A4, bekommt ein Brother QL eine
     * Seite, auf die er ein Etikett drucken soll — und wirft entweder eine
     * Fehlermeldung oder ein winziges Etikett aus.
     */
    #[Test]
    public function the_roll_page_is_the_label(): void
    {
        $lot = $this->lot();

        $this->actingAs($this->stockReader())
            ->get(route('warehouse.label.print', ['lots' => $lot->getKey()]))
            ->assertSuccessful()
            ->assertSee('@page { size: 62mm 29mm; margin: 0; }', false);
    }

    #[Test]
    public function the_sheet_layout_is_a_page_of_labels(): void
    {
        $lot = $this->lot();

        $this->actingAs($this->stockReader())
            ->get(route('warehouse.label.print', ['lots' => $lot->getKey(), 'layout' => 'sheet']))
            ->assertSuccessful()
            ->assertSee('@page { size: 210mm 297mm; margin: 0; }', false);
    }

    /**
     * Der Kalibrierbogen misst nach, ob der Drucker skaliert.
     */
    #[Test]
    public function the_calibration_sheet_states_the_size(): void
    {
        $this->actingAs($this->stockReader())
            ->get(route('warehouse.label.calibration'))
            ->assertSuccessful()
            ->assertSee('62 × 29 mm');
    }

    /**
     * Der QR-Code steht auf dem Etikett — und trägt KEINE Adresse.
     *
     * Vorgabe: „warum eine adresse? ich dachte eher daran das aeronance selbst
     * einen scanner aufmacht und somit darin nur Infos sind die das tool
     * braucht."
     */
    #[Test]
    public function the_label_carries_a_code_without_an_address(): void
    {
        $lot = $this->lot();

        $etikett = $this->labelMarkup($lot);

        $this->assertStringContainsString('<svg', $etikett, 'Ohne Code kann niemand scannen.');

        /*
         * Geprueft wird das ETIKETT, nicht die Seite: Der Bildschirmhinweis
         * darueber traegt einen Link zum Kalibrierbogen, und der ist eine
         * echte Adresse -- er wird nur nicht gedruckt.
         *
         * Dass im Code selbst keine Adresse steht, prueft ScanCodeTest an der
         * Zeichenkette. Hier geht es um das Papier: kein Verweis, den jemand
         * vom Etikett ablesen koennte.
         */
        $this->assertStringNotContainsString('href', $etikett, 'Auf dem Etikett darf kein Verweis stehen.');
    }

    /**
     * Und der Code sagt dasselbe wie der Aufdruck.
     *
     * Wer scannt, bekommt die Nummer, die daneben steht — und kann das eine am
     * anderen prüfen. Ein Code, der etwas anderes trägt als der Klartext, wäre
     * genau die Art Fehler, die niemand bemerkt.
     */
    #[Test]
    public function the_code_says_the_same_as_the_print(): void
    {
        $lot = $this->lot();

        $this->assertSame('AER1:L:'.$lot->lot_number, ScanCode::forLot($lot->lot_number));
        $this->assertStringContainsString($lot->lot_number, $this->printedPage($lot));
    }

    // ── Wer darf ─────────────────────────────────────────────────────────────

    #[Test]
    public function without_the_permission_no_label(): void
    {
        $lot = $this->lot();

        $this->actingAs(User::factory()->create(['is_active' => true]))
            ->get(route('warehouse.label.print', ['lots' => $lot->getKey()]))
            ->assertForbidden();
    }

    #[Test]
    public function nothing_is_served_while_the_module_is_off(): void
    {
        $lot = $this->lot();
        $leser = $this->stockReader();

        app(ModuleManager::class)->disable('warehouse');
        app(ModuleManager::class)->forgetCache();

        $this->actingAs($leser)
            ->get(route('warehouse.label.print', ['lots' => $lot->getKey()]))
            ->assertNotFound();
    }

    /**
     * Der Seitenkörper eines Ausdrucks — ohne Stilblock.
     *
     * ─────────────────────────────────────────────────────────────────────────
     * WARUM NICHT DIE GANZE ANTWORT: Der Stilblock enthält Kommentare, und in
     * einem davon steht das Wort „Verfallsdatum" als Begründung, warum der
     * Kasten dort sitzt. Ein Test über die ganze Seite fand es und meldete
     * einen Fehler, den es nicht gab.
     *
     * Geprüft wird, was jemand auf dem Etikett LIEST — und das steht im Körper.
     * ─────────────────────────────────────────────────────────────────────────
     */
    /**
     * Nur das Etikett selbst — ohne Bildschirmhinweis und ohne Stilblock.
     */
    private function labelMarkup(StockLot $lot): string
    {
        return Str::after($this->printedPage($lot), 'class="label"');
    }

    private function printedPage(StockLot $lot): string
    {
        $antwort = $this->actingAs($this->stockReader())
            ->get(route('warehouse.label.print', ['lots' => $lot->getKey()]))
            ->assertSuccessful();

        $inhalt = (string) $antwort->getContent();

        return Str::after($inhalt, '<body>');
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function lot(array $overrides = []): StockLot
    {
        $part = PartType::query()->firstOrCreate(
            ['name' => 'Bremsklotz'],
            [
                'classification' => PartClassification::StandardPart,
                'ipc_part_number' => '4711-01',
            ],
        );

        return StockLot::create(array_merge([
            'part_type_id' => $part->getKey(),
            'lot_number' => 'EASA-12345',
            'serial_number' => 'SN-0815',
            'batch_number' => 'CH-99',
            'document_reference' => 'EASA-12345',
            'received_at' => '2026-01-15',
        ], $overrides));
    }

    private function stockReader(): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->givePermissionTo(Permissions::STOCK_VIEW);

        return $user->fresh();
    }
}
