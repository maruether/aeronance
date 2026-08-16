<?php

declare(strict_types=1);

namespace Tests\Feature\Fleet;

use App\Core\Access\AccessSetup;
use App\Core\Modules\ModuleManager;
use App\Models\User;
use App\Modules\Fleet\Actions\PrepareWeighing;
use App\Modules\Fleet\Actions\SeedWeighingSheet;
use App\Modules\Fleet\Enums\SheetVariant;
use App\Modules\Fleet\Enums\Undercarriage;
use App\Modules\Fleet\Models\Aircraft;
use App\Modules\Fleet\Models\Weighing;
use App\Modules\Fleet\Models\WeighingEntry;
use App\Modules\Fleet\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Das Wägeblatt als Blatt -- Gliederung, Vorlagenzeilen, abgeleitete Zuladung.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * Feldtest, zweimal: „Ich habe immer noch beim anlegen der wägung die kacheln
 * zum werte eintragen und in der Druckansicht die Tabelle. Keine Grafik. Ich
 * will das BWLV Formular quasi 1:1 haben zum digital ausfüllen nur ohne das
 * logo." Und danach: „der jetztige istzustand ist für den täglichen betrieb
 * nicht brauchbar."
 *
 * Was diese Tests festhalten, ist deshalb nicht die Optik, sondern das, woran
 * sie hing: dass ein Blatt mit seinen Zeilen ENTSTEHT, dass die Zuladung
 * abgeleitet und nicht abgetippt wird, und dass die Blattart bis in den
 * Ausdruck durchkommt.
 * ─────────────────────────────────────────────────────────────────────────────
 */
final class WeighingSheetTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        app(ModuleManager::class)->enable('fleet');
        app(ModuleManager::class)->forgetCache();
        app(AccessSetup::class)->run();
    }

    /**
     * Ein Papierblatt kommt mit seinen Zeilen. Wer es ausfüllt, trägt Zahlen
     * ein -- er schreibt nicht erst „Tragwerk rechts innen" ab.
     */
    #[Test]
    public function a_new_sheet_comes_with_the_rows_the_form_has_printed(): void
    {
        $blatt = $this->sheet(SheetVariant::Glider, Undercarriage::TailwheelOneMain);

        app(SeedWeighingSheet::class)->handle($blatt);
        $blatt->refresh();

        $bauteile = $blatt->entriesOf(WeighingEntry::SECTION_COMPONENT);
        $auflagen = $blatt->entriesOf(WeighingEntry::SECTION_SUPPORT);

        $this->assertGreaterThan(5, $bauteile->count(), 'Die Vorlage des Blatts fehlt.');
        $this->assertSame('Tragwerk rechts innen', $bauteile->first()->label);

        // Zwei Wägepunkte, weil ein Hauptrad und ein Sporn -- nicht, weil es
        // ein Segelflugzeug ist.
        $this->assertCount(2, $auflagen);
        $this->assertSame('Hauptrad G1', $auflagen[0]->label);

        // Und keine Zahlen: die trägt ein, wer wiegt.
        $this->assertNull($bauteile->first()->mass_kg);
    }

    #[Test]
    public function the_undercarriage_decides_how_many_weighing_points_there_are(): void
    {
        $blatt = $this->sheet(SheetVariant::Motorglider, Undercarriage::Tricycle);

        app(SeedWeighingSheet::class)->handle($blatt);

        $this->assertCount(3, $blatt->fresh()->entriesOf(WeighingEntry::SECTION_SUPPORT));
    }

    /**
     * Zweimal vorbelegen darf nicht zweimal anlegen -- sonst steht die Vorlage
     * doppelt unter der Vorlage.
     */
    #[Test]
    public function seeding_twice_changes_nothing(): void
    {
        $blatt = $this->sheet(SheetVariant::Glider, Undercarriage::TailwheelOneMain);

        app(SeedWeighingSheet::class)->handle($blatt);
        $vorher = $blatt->fresh()->entries->count();

        app(SeedWeighingSheet::class)->handle($blatt->fresh());

        $this->assertSame($vorher, $blatt->fresh()->entries->count());
    }

    /**
     * ─────────────────────────────────────────────────────────────────────────
     * DIE ZULADUNG WIRD ABGELEITET, NICHT EINGETRAGEN.
     *
     * Fachliche Vorgabe wörtlich: „Die Zuladung ist im Flug Teil der M.N.T.
     * Bei der Wägung ist der Flieger natürlich leer (bis auf evtl. Sprit). Die
     * zulässige Zuladung berechnet sich dann daraus."
     *
     * Vorher zählte allein die Höchstmasse -- und die ist hier NICHT die
     * kleinere Grenze. Das Blatt hätte 120 kg Zuladung bestätigt, die das
     * Flugzeug nach seiner N.T.-Grenze gar nicht tragen darf.
     * ─────────────────────────────────────────────────────────────────────────
     */
    #[Test]
    public function the_payload_follows_the_smaller_of_the_two_limits(): void
    {
        $blatt = $this->sheet(SheetVariant::Glider, Undercarriage::TailwheelOneMain);
        $blatt->update([
            'max_mass_kg' => 500,          // 500 − 380 = 120 kg Reserve
            'max_non_lifting_kg' => 300,   // 300 − 250 = 50 kg Reserve  ← kleiner
        ]);

        $this->bauteilzeile($blatt, 'Tragwerk rechts', 130.0, null);
        $this->bauteilzeile($blatt, 'Rumpf', 250.0, 250.0);

        $ergebnis = $blatt->fresh()->result();

        $this->assertSame(380.0, $ergebnis->emptyMassKg);
        $this->assertSame(50.0, $ergebnis->nonLiftingHeadroomKg);
        $this->assertSame(50.0, $ergebnis->usefulLoadKg, 'Die N.T.-Grenze ist hier die engere.');
    }

    #[Test]
    public function a_sheet_that_carries_nothing_says_so(): void
    {
        $blatt = $this->sheet(SheetVariant::Glider, Undercarriage::TailwheelOneMain);
        $blatt->update(['max_mass_kg' => 500, 'max_non_lifting_kg' => 200]);

        // Die nichttragenden Teile allein reissen schon die Grenze.
        $this->bauteilzeile($blatt, 'Rumpf', 250.0, 250.0);

        $ergebnis = $blatt->fresh()->result();

        $this->assertFalse($ergebnis->isAcceptable());
        // Erst die Ursache, dann die Folge.
        $this->assertStringContainsString('nichttragenden', $ergebnis->findings[0]);
    }

    #[Test]
    public function the_printed_sheet_carries_the_variant_in_its_heading(): void
    {
        $blatt = $this->sheet(SheetVariant::Motorglider, Undercarriage::Tricycle);
        app(SeedWeighingSheet::class)->handle($blatt);

        $leser = User::factory()->create(['is_active' => true]);
        $leser->givePermissionTo(Permissions::FLEET_VIEW);

        $this->actingAs($leser->fresh())
            ->get(route('fleet.weighing', ['weighing' => $blatt]))
            ->assertSuccessful()
            ->assertSee('Massenübersicht Motorsegler')
            // Das Blatt ist unseres, nicht das eines Verbandes.
            ->assertDontSee('BWLV');
    }

    /** Was vom letzten Blatt kommt, IST die Vorlage -- nicht zusätzlich zu ihr. */
    #[Test]
    public function carrying_a_sheet_forward_does_not_double_its_rows(): void
    {
        $aircraft = Aircraft::create(['registration' => 'D-1234', 'model' => 'ASK 21']);
        $mechaniker = User::factory()->create(['is_active' => true]);
        $mechaniker->givePermissionTo(Permissions::FLEET_MANAGE);

        $erstes = app(PrepareWeighing::class)->from($aircraft, $mechaniker->fresh());
        $anzahl = $erstes->fresh()->entriesOf(WeighingEntry::SECTION_COMPONENT)->count();

        $erstes->update(['signed_off_at' => now(), 'signed_off_by_name' => 'Hans Meier']);

        $zweites = app(PrepareWeighing::class)->from($aircraft->fresh(), $mechaniker->fresh());

        $this->assertSame(
            $anzahl,
            $zweites->fresh()->entriesOf(WeighingEntry::SECTION_COMPONENT)->count(),
            'Vorlage und Übernahme haben sich addiert.',
        );
    }

    private function sheet(SheetVariant $variant, Undercarriage $undercarriage): Weighing
    {
        $aircraft = Aircraft::create(['registration' => 'D-4321', 'model' => 'ASK 21']);

        return Weighing::create([
            'aircraft_id' => $aircraft->id,
            'kind' => $variant->kind(),
            'sheet_variant' => $variant,
            'undercarriage' => $undercarriage,
            'weighed_at' => now()->toDateString(),
        ]);
    }

    private function bauteilzeile(Weighing $weighing, string $label, float $mass, ?float $nonLifting): void
    {
        WeighingEntry::create([
            'weighing_id' => $weighing->id,
            'section' => WeighingEntry::SECTION_COMPONENT,
            'label' => $label,
            'mass_kg' => $mass,
            'non_lifting_kg' => $nonLifting,
        ]);
    }
}
