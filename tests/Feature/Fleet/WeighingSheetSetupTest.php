<?php

declare(strict_types=1);

namespace Tests\Feature\Fleet;

use App\Core\Access\AccessSetup;
use App\Core\Modules\ModuleManager;
use App\Modules\Fleet\Actions\PrepareWeighing;
use App\Modules\Fleet\Actions\SwitchSheetVariant;
use App\Modules\Fleet\Enums\Propulsion;
use App\Modules\Fleet\Enums\SheetVariant;
use App\Modules\Fleet\Enums\Undercarriage;
use App\Modules\Fleet\Enums\WeighingKind;
use App\Modules\Fleet\Models\Aircraft;
use App\Modules\Fleet\Models\AircraftType;
use App\Modules\Fleet\Models\Weighing;
use App\Modules\Fleet\Models\WeighingEntry;
use App\Modules\Fleet\Support\SheetSetup;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Welches Wägeblatt ein Flugzeug bekommt — und woher die Antwort stammt.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * Feldtest: „wenn ich für die D-EICC eine wägung anlege bekomme ich als
 * eingabemaske die massenübersicht segelflugzeug. Bei der Auswahl des
 * Flugzeuges gehört die abfrage nach typ und fahrwerkskonfiguration rein. Noch
 * besser wäre wenn Diese Daten direkt im Muster hinterlegt werden könnten."
 *
 * Der Fehler war kein Tippfehler, sondern ein stummer Rückfall: Ohne
 * Vorgängerwägung stand „Segelflugzeug" als Vorgabe im Code, und der Weg über
 * „Neue Wägung (Werte übernehmen)" fragte niemanden. Diese Tests halten die
 * Rangfolge fest, die an seine Stelle getreten ist -- Muster vor letzter
 * Wägung vor Antrieb -- und dass ein einmal falsch angelegtes Blatt umgestellt
 * werden kann, ohne gewogene Zahlen zu verlieren.
 * ─────────────────────────────────────────────────────────────────────────────
 */
final class WeighingSheetSetupTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        app(ModuleManager::class)->enable('fleet');
        app(ModuleManager::class)->forgetCache();
        app(AccessSetup::class)->run();
    }

    /** DER GEMELDETE FALL: ein Flugzeug, kein Muster, keine Vorgängerwägung. */
    #[Test]
    public function a_powered_aircraft_does_not_silently_get_the_glider_sheet(): void
    {
        $lfz = Aircraft::create([
            'registration' => 'D-EICC',
            'model' => 'Aquila AT01',
            'propulsion' => Propulsion::Piston,
        ]);

        $blatt = app(PrepareWeighing::class)->from($lfz);

        $this->assertSame(SheetVariant::Aeroplane, $blatt->sheet_variant);
        $this->assertSame(WeighingKind::Powered, $blatt->kind, 'Der Rechenweg folgt der Blattart.');
        $this->assertCount(
            3,
            $blatt->entriesOf(WeighingEntry::SECTION_SUPPORT),
            'Ein Dreibein steht auf drei Wägepunkten.',
        );
    }

    /** Das Muster ist die Aussage, die jemand getroffen hat -- sie gewinnt. */
    #[Test]
    public function the_type_outranks_everything_else(): void
    {
        $muster = AircraftType::create([
            'designation' => 'Grob G 109 B',
            'sheet_variant' => SheetVariant::Motorglider,
            'undercarriage' => Undercarriage::TailwheelTwoMains,
        ]);

        // Der Antrieb ist absichtlich unpassend eingetragen: Die Angabe am
        // Muster soll auch dann gelten, wenn eine Ableitung anders raten würde.
        $lfz = Aircraft::create([
            'registration' => 'D-KABC',
            'model' => 'G 109',
            'propulsion' => Propulsion::Unpowered,
            'aircraft_type_id' => $muster->id,
        ]);

        $setup = SheetSetup::for($lfz);

        $this->assertSame(SheetVariant::Motorglider, $setup->variant);
        $this->assertSame(Undercarriage::TailwheelTwoMains, $setup->undercarriage);
        $this->assertSame(SheetSetup::FROM_TYPE, $setup->origin);
        $this->assertTrue($setup->storedOnType);
    }

    /** Ohne Muster zählt, was zuletzt unterschrieben wurde. */
    #[Test]
    public function the_last_signed_off_sheet_is_the_second_source(): void
    {
        $lfz = Aircraft::create(['registration' => 'D-EFGH', 'model' => 'DR 400']);

        Weighing::create([
            'aircraft_id' => $lfz->id,
            'kind' => WeighingKind::Powered,
            'sheet_variant' => SheetVariant::Aeroplane,
            'undercarriage' => Undercarriage::TailwheelTwoMains,
            'weighed_at' => now()->subYears(4)->toDateString(),
            'signed_off_at' => now()->subYears(4),
        ]);

        $setup = SheetSetup::for($lfz->fresh());

        $this->assertSame(SheetVariant::Aeroplane, $setup->variant);
        $this->assertSame(Undercarriage::TailwheelTwoMains, $setup->undercarriage);
        $this->assertSame(SheetSetup::FROM_PREVIOUS, $setup->origin);
    }

    /** Ein Entwurf ist keine Quelle -- er kann genau der Fehler sein. */
    #[Test]
    public function an_unsigned_draft_does_not_count_as_a_source(): void
    {
        $lfz = Aircraft::create([
            'registration' => 'D-EICC',
            'model' => 'Aquila AT01',
            'propulsion' => Propulsion::Piston,
        ]);

        // Genau das Blatt, das der Fehler erzeugt hat: ein Segelflugblatt für
        // ein Flugzeug. Es darf sich nicht selbst fortschreiben.
        Weighing::create([
            'aircraft_id' => $lfz->id,
            'kind' => WeighingKind::Glider,
            'sheet_variant' => SheetVariant::Glider,
            'undercarriage' => Undercarriage::TailwheelOneMain,
            'weighed_at' => now()->toDateString(),
        ]);

        $setup = SheetSetup::for($lfz->fresh());

        $this->assertSame(SheetVariant::Aeroplane, $setup->variant);
        $this->assertSame(SheetSetup::FROM_PROPULSION, $setup->origin);
    }

    /** Kein Fahrwerk aus einem anderen Blatt -- sonst entsteht ein Zwitter. */
    #[Test]
    public function an_undercarriage_from_a_different_sheet_is_not_carried_over(): void
    {
        $muster = AircraftType::create([
            'designation' => 'Aquila AT01',
            'sheet_variant' => SheetVariant::Aeroplane,
            // Fahrwerk bewusst offen: Das Muster weiss nur die halbe Antwort.
        ]);

        $lfz = Aircraft::create([
            'registration' => 'D-EICC',
            'model' => 'Aquila AT01',
            'aircraft_type_id' => $muster->id,
        ]);

        Weighing::create([
            'aircraft_id' => $lfz->id,
            'kind' => WeighingKind::Glider,
            'sheet_variant' => SheetVariant::Glider,
            'undercarriage' => Undercarriage::TailwheelOneMain,
            'weighed_at' => now()->subYear()->toDateString(),
            'signed_off_at' => now()->subYear(),
        ]);

        $setup = SheetSetup::for($lfz->fresh());

        $this->assertSame(SheetVariant::Aeroplane, $setup->variant);
        $this->assertSame(
            Undercarriage::Tricycle,
            $setup->undercarriage,
            'Das eine Hauptrad des Segelflugblatts gehört nicht auf ein Flugzeugblatt.',
        );
        $this->assertFalse($setup->storedOnType, 'Das Fahrwerk fehlt am Muster -- es ist zu lernen.');
    }

    /** Eine ausdrückliche Wahl bringt ihre Wägepunkte mit. */
    #[Test]
    public function an_explicit_variant_brings_its_own_supports(): void
    {
        $lfz = Aircraft::create(['registration' => 'D-KABC', 'model' => 'ASK 21']);

        $blatt = app(PrepareWeighing::class)->from(
            aircraft: $lfz,
            variant: SheetVariant::Aeroplane,
        );

        $this->assertSame(Undercarriage::Tricycle, $blatt->undercarriage);
        $this->assertCount(3, $blatt->entriesOf(WeighingEntry::SECTION_SUPPORT));
    }

    /** Das Muster lernt nur, was es noch nicht weiss. */
    #[Test]
    public function the_type_learns_only_what_it_does_not_know(): void
    {
        $muster = AircraftType::create([
            'designation' => 'Aquila AT01',
            'sheet_variant' => SheetVariant::Aeroplane,
        ]);

        $gelernt = $muster->rememberWeighingSetup(
            SheetVariant::Glider,
            Undercarriage::TailwheelTwoMains,
        );

        $this->assertTrue($gelernt);
        $muster->refresh();

        $this->assertSame(
            SheetVariant::Aeroplane,
            $muster->sheet_variant,
            'Eine vorhandene Angabe am Muster wird nicht überschrieben.',
        );
        $this->assertSame(Undercarriage::TailwheelTwoMains, $muster->undercarriage);

        $this->assertFalse(
            $muster->rememberWeighingSetup(SheetVariant::Glider, Undercarriage::TailwheelOneMain),
            'Ist beides hinterlegt, gibt es nichts mehr zu lernen.',
        );
    }

    /** Ein falsch angelegtes Blatt lässt sich umstellen. */
    #[Test]
    public function an_untouched_sheet_is_rebuilt_for_the_new_variant(): void
    {
        $lfz = Aircraft::create(['registration' => 'D-EICC', 'model' => 'Aquila AT01']);

        // So sah es aus, bevor der Fehler behoben war: Segelflugblatt.
        $blatt = app(PrepareWeighing::class)->from($lfz);

        $this->assertCount(2, $blatt->entriesOf(WeighingEntry::SECTION_SUPPORT));

        $geblieben = app(SwitchSheetVariant::class)->handle(
            $blatt,
            SheetVariant::Aeroplane,
            Undercarriage::Tricycle,
        );

        $blatt->refresh()->load('entries');

        $this->assertSame([], $geblieben, 'Ohne eingetragene Zahlen bleibt nichts stehen.');
        $this->assertSame(SheetVariant::Aeroplane, $blatt->sheet_variant);
        $this->assertSame(WeighingKind::Powered, $blatt->kind);
        $this->assertCount(3, $blatt->entriesOf(WeighingEntry::SECTION_SUPPORT));
        $this->assertCount(
            0,
            $blatt->entriesOf(WeighingEntry::SECTION_COMPONENT),
            'Die Bauteilliste des Segelflugblatts gehört nicht aufs Flugzeugblatt.',
        );
        $this->assertCount(
            5,
            $blatt->entriesOf(WeighingEntry::SECTION_DEDUCTION),
            'Dafür kommen die Behälter des Flugzeugblatts.',
        );
    }

    /** Gewogene Zahlen werden nie stillschweigend weggeworfen. */
    #[Test]
    public function rows_that_already_carry_figures_survive_the_switch(): void
    {
        $lfz = Aircraft::create(['registration' => 'D-KABC', 'model' => 'ASK 21']);

        $blatt = app(PrepareWeighing::class)->from($lfz);

        $auflage = $blatt->entriesOf(WeighingEntry::SECTION_SUPPORT)->first();
        $auflage->update(['gross_kg' => 182.5, 'arm_mm' => 300]);

        $geblieben = app(SwitchSheetVariant::class)->handle(
            $blatt->fresh(['entries']),
            SheetVariant::Aeroplane,
            Undercarriage::Tricycle,
        );

        $blatt->refresh()->load('entries');

        $this->assertSame([WeighingEntry::SECTION_SUPPORT], $geblieben);
        $this->assertCount(
            2,
            $blatt->entriesOf(WeighingEntry::SECTION_SUPPORT),
            'Der Abschnitt bleibt, wie er ist -- gemeldet wird er dafür.',
        );
        $this->assertSame('182.50', $blatt->entriesOf(WeighingEntry::SECTION_SUPPORT)->first()->gross_kg);
    }

    /** Ein abgezeichnetes Blatt wird nicht umgestellt. */
    #[Test]
    public function a_signed_off_sheet_is_not_switched(): void
    {
        $lfz = Aircraft::create(['registration' => 'D-KABC', 'model' => 'ASK 21']);

        $blatt = app(PrepareWeighing::class)->from($lfz);
        $blatt->update(['signed_off_at' => now()]);

        $this->expectExceptionMessage('Ein abgezeichnetes Blatt wird nicht umgestellt.');

        app(SwitchSheetVariant::class)->handle(
            $blatt->fresh(),
            SheetVariant::Aeroplane,
            Undercarriage::Tricycle,
        );
    }
}
