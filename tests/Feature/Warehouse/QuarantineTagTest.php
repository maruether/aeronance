<?php

declare(strict_types=1);

namespace Tests\Feature\Warehouse;

use App\Core\Access\AccessSetup;
use App\Core\Models\Qualification;
use App\Core\Modules\ModuleManager;
use App\Models\User;
use App\Modules\Warehouse\Actions\ChangeLotState;
use App\Modules\Warehouse\Actions\ReceiveStock;
use App\Modules\Warehouse\Enums\LotState;
use App\Modules\Warehouse\Enums\PartClassification;
use App\Modules\Warehouse\Models\LotStateChange;
use App\Modules\Warehouse\Models\PartType;
use App\Modules\Warehouse\Models\StockLot;
use App\Modules\Warehouse\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The printable quarantine slips.
 *
 * Rendered as HTML with millimetre geometry rather than a PDF -- the usual
 * library is blocked here on two counts, and the browser turns out to do the
 * job well enough once the calibration sheet has confirmed the printer is not
 * scaling.
 */
final class QuarantineTagTest extends TestCase
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
    public function the_slip_carries_everything_that_belongs_on_it(): void
    {
        $change = $this->quarantine(aircraft: 'D-KABC', type: 'ASK 21');

        $this->actingAs($this->userWith(Permissions::STOCK_QUARANTINE))
            ->get(route('warehouse.tag.single', ['change' => $change]))
            ->assertSuccessful()
            ->assertSee($change->quarantine_tag)        // laufende Nummer
            ->assertSee('D-KABC')                       // Kennzeichen
            ->assertSee('ASK 21')                       // Muster
            ->assertSee('Ölfilter Rotax 912', false)    // Bauteilbezeichnung
            /*
             * IN DER VEREINSZEITZONE, wie der Zettel selbst -- die Anwendung
             * rechnet in UTC. Verglichen gegen die unkonvertierte Zeit war
             * dieser Test 22 Stunden am Tag gruen und zwei Stunden rot, und der
             * Tag-Lauf um Mitternacht hat ihn erwischt. Siehe
             * a_slip_written_after_midnight_carries_the_local_day().
             */
            ->assertSee($change->occurred_at
                ->timezone(config('aeronance.organisation.timezone'))
                ->format('d.m.Y'))
            ->assertSee('Unterschrift');
    }

    #[Test]
    public function the_colour_comes_from_the_record(): void
    {
        // So the wrong colour cannot end up on a part by mistake: the person at
        // the printer does not choose, the state does.
        $change = $this->quarantine();

        $this->actingAs($this->userWith(Permissions::STOCK_QUARANTINE))
            ->get(route('warehouse.tag.single', ['change' => $change]))
            ->assertSee(config('aeronance.quarantine_tag.colours.quarantined'), false);
    }

    #[Test]
    public function a_slip_written_after_midnight_carries_the_local_day(): void
    {
        /*
         * ─────────────────────────────────────────────────────────────────────
         * DER FALL, DEN DIE PIPELINE GEFUNDEN HAT, festgehalten statt vertagt.
         *
         * Die Anwendung rechnet in UTC, der Sperrzettel druckt in der
         * Vereinszeitzone. Zwischen 22:00 und 24:00 UTC ist das in Mitteleuropa
         * bereits der naechste Tag -- ein Teil, das um 00:30 Ortszeit gesperrt
         * wird, darf nicht den Vortag auf dem Zettel tragen. Der Zettel ist ein
         * Papierdokument fuer den Verein, und massgeblich ist die Zeit, die dort
         * jemand auf die Uhr sieht.
         *
         * Ohne diese Zusicherung war die Frage "welches Datum stimmt" nirgends
         * beantwortet: der uebrige Test verglich gegen UTC und war 22 Stunden am
         * Tag gruen.
         * ─────────────────────────────────────────────────────────────────────
         */
        $this->travelTo(Carbon::parse('2026-08-02 22:30:00', 'UTC'));

        $change = $this->quarantine(aircraft: 'D-KABC', type: 'ASK 21');

        $this->actingAs($this->userWith(Permissions::STOCK_QUARANTINE))
            ->get(route('warehouse.tag.single', ['change' => $change]))
            ->assertSuccessful()
            ->assertSee('03.08.2026');

        $this->travelBack();
    }

    #[Test]
    public function the_colours_follow_the_industry_convention(): void
    {
        // Yellow serviceable, blue awaiting a decision, green repairable, red
        // condemned -- the widely-read scheme from the US military forms.
        // Nothing prescribes it, which is why it is configurable, but the one
        // that trips people up is green: it means REPAIRABLE, not good.
        $colours = config('aeronance.quarantine_tag.colours');

        $this->assertNotSame($colours['serviceable'], $colours['unserviceable']);
        $this->assertNotSame($colours['unserviceable'], $colours['unsalvageable']);
        $this->assertNotSame($colours['quarantined'], $colours['serviceable']);

        // Every state a tag can carry has a colour.
        foreach (LotState::cases() as $state) {
            $this->assertArrayHasKey($state->value, $colours);
        }
    }

    #[Test]
    public function labels_can_be_printed_for_ready_made_coloured_tags(): void
    {
        // The alternative for clubs using pre-made coloured card tags with a
        // metal eyelet: the colour is in the card, so the label carries the
        // state in words instead -- a wrongly picked tag is then still read
        // correctly.
        $change = $this->quarantine();

        $this->actingAs($this->userWith(Permissions::STOCK_QUARANTINE))
            ->get(route('warehouse.tag.sheet', ['layout' => 'labels', 'tags' => $change->id]))
            ->assertSuccessful()
            ->assertSee($change->quarantine_tag)
            ->assertSee(__('warehouse.tag.state.quarantined'));
    }

    #[Test]
    public function a_sheet_holds_the_slips_that_have_not_been_printed(): void
    {
        $first = $this->quarantine();
        $second = $this->quarantine();

        $this->actingAs($this->userWith(Permissions::STOCK_QUARANTINE))
            ->get(route('warehouse.tag.sheet'))
            ->assertSuccessful()
            ->assertSee($first->quarantine_tag)
            ->assertSee($second->quarantine_tag);
    }

    #[Test]
    public function printing_marks_them_so_a_sheet_is_not_repeated(): void
    {
        $change = $this->quarantine();
        $this->assertNull($change->tag_printed_at);

        $this->actingAs($this->userWith(Permissions::STOCK_QUARANTINE))
            ->get(route('warehouse.tag.sheet'))
            ->assertSuccessful();

        $this->assertNotNull($change->fresh()->tag_printed_at);

        // ...and the next sheet is empty rather than repeating it.
        $this->actingAs($this->userWith(Permissions::STOCK_QUARANTINE))
            ->get(route('warehouse.tag.sheet'))
            ->assertDontSee($change->quarantine_tag);
    }

    #[Test]
    public function a_single_slip_can_be_reprinted_with_the_same_number(): void
    {
        // A lost or unreadable tag gets the same number again -- it is already
        // written into the history, and reissuing would make two things share
        // one number.
        $change = $this->quarantine();

        $this->actingAs($this->userWith(Permissions::STOCK_QUARANTINE))
            ->get(route('warehouse.tag.single', ['change' => $change]))
            ->assertSuccessful()
            ->assertSee($change->quarantine_tag);

        $this->actingAs($this->userWith(Permissions::STOCK_QUARANTINE))
            ->get(route('warehouse.tag.single', ['change' => $change]))
            ->assertSuccessful()
            ->assertSee($change->quarantine_tag);
    }

    #[Test]
    public function positions_already_used_on_a_sheet_can_be_skipped(): void
    {
        // Otherwise one slip costs a whole sheet of card, which is the
        // practical objection to sheet formats.
        $change = $this->quarantine();
        $layout = config('aeronance.quarantine_tag.sheet');

        $response = $this->actingAs($this->userWith(Permissions::STOCK_QUARANTINE))
            ->get(route('warehouse.tag.sheet', ['skip' => '1,2,3']));

        $response->assertSuccessful();

        // The first free position is the fourth: second row, second column.
        $expectedTop = $layout['margin_top'] + $layout['tag_height'];
        $response->assertSee('top: '.$expectedTop.'mm', false);
    }

    #[Test]
    public function the_calibration_sheet_states_the_measurements_to_check(): void
    {
        $this->actingAs($this->userWith(Permissions::STOCK_QUARANTINE))
            ->get(route('warehouse.tag.calibration'))
            ->assertSuccessful()
            ->assertSee('100')
            ->assertSee('T2002-10');
    }

    #[Test]
    public function someone_without_the_permission_gets_nothing(): void
    {
        $change = $this->quarantine();

        $this->actingAs($this->userWith())
            ->get(route('warehouse.tag.single', ['change' => $change]))
            ->assertForbidden();

        $this->actingAs($this->userWith())
            ->get(route('warehouse.tag.sheet'))
            ->assertForbidden();
    }

    #[Test]
    public function nothing_is_printed_while_the_module_is_off(): void
    {
        $change = $this->quarantine();

        app(ModuleManager::class)->disable('warehouse');
        app(ModuleManager::class)->forgetCache();

        $this->actingAs($this->userWith(Permissions::STOCK_QUARANTINE))
            ->get(route('warehouse.tag.single', ['change' => $change]))
            ->assertNotFound();
    }

    private function quarantine(?string $aircraft = null, ?string $type = null): LotStateChange
    {
        $part = PartType::firstOrCreate(
            ['name' => 'Ölfilter Rotax 912'],
            [
                'classification' => PartClassification::Component,
                'unit_of_measure' => 'St',
                'shelf_life_days' => 1095,
            ],
        );

        app(ReceiveStock::class)->handle($part, 4, '2026-07-01');
        $lot = StockLot::where('part_type_id', $part->id)->latest('id')->first();

        $change = app(ChangeLotState::class)->handle(
            $lot,
            LotState::Quarantined,
            'Verdacht auf Chargenfehler',
            $this->userWith(Permissions::STOCK_QUARANTINE),
        );

        if ($aircraft !== null) {
            LotStateChange::query()->whereKey($change->getKey())->update([
                'aircraft_reference' => $aircraft,
                'aircraft_type' => $type,
            ]);
        }

        return $change->fresh();
    }

    private function userWith(string ...$permissions): User
    {
        $user = User::factory()->create(['is_active' => true]);

        foreach ($permissions as $permission) {
            $user->givePermissionTo($permission);
        }

        return $user->fresh();
    }

    private function givePart66(User $user): void
    {
        Qualification::create([
            'user_id' => $user->id,
            'type' => Qualification::TYPE_PART66,
            'reference' => 'DE.66.12345',
            'valid_from' => now()->subYear()->toDateString(),
        ]);
    }
}
