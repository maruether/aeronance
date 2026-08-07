<?php

declare(strict_types=1);

namespace Tests\Feature\Warehouse;

use App\Core\Models\Qualification;
use App\Core\Modules\ModuleManager;
use App\Models\User;
use App\Modules\Warehouse\Actions\ChangeLotState;
use App\Modules\Warehouse\Actions\DisposeStock;
use App\Modules\Warehouse\Actions\ExpireStock;
use App\Modules\Warehouse\Actions\ReceiveStock;
use App\Modules\Warehouse\Enums\LotState;
use App\Modules\Warehouse\Enums\PartClassification;
use App\Modules\Warehouse\Models\LotStateChange;
use App\Modules\Warehouse\Models\PartType;
use App\Modules\Warehouse\Models\StockLot;
use App\Modules\Warehouse\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Stock that has passed its date, and who may throw it away.
 *
 * the two rulings together: expiry marks a lot unserviceable by itself and
 * never needs the "unsalvageable" step, and consumables may be destroyed by
 * anyone holding the permission -- the Part-66 binding is for setting the status
 * of components under 145.A.42.
 */
final class ExpiryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        foreach ([Permissions::STOCK_SCRAP, Permissions::STOCK_QUARANTINE, Permissions::STOCK_QUARANTINE_CERTIFY] as $p) {
            Permission::findOrCreate($p, 'web');
        }
    }

    #[Test]
    public function expired_stock_becomes_unserviceable_by_itself(): void
    {
        // It used to sit in state "serviceable" while isIssuable() said no --
        // two places telling different stories about the same tin, and the one
        // people read was the wrong one.
        $resin = $this->resin();
        app(ReceiveStock::class)->handle($resin, 5, now()->subYears(2)->toDateString(), lotData: $this->certified());

        $lot = StockLot::sole();
        $this->assertSame(LotState::Serviceable, $lot->state);
        $this->assertTrue($lot->hasExpired());

        app(ExpireStock::class)->run();

        $this->assertSame(LotState::Unserviceable, $lot->fresh()->state);
        $this->assertFalse($lot->fresh()->isIssuable());
    }

    #[Test]
    public function it_is_recorded_as_the_system_acting_not_a_person(): void
    {
        // The reason it can be automatic is the reason it should be: nobody
        // exercised judgement. A date passed, and the date is a fact the system
        // already holds -- so no licence is snapshotted and no name is claimed.
        $resin = $this->resin();
        app(ReceiveStock::class)->handle($resin, 5, now()->subYears(2)->toDateString(), lotData: $this->certified());

        app(ExpireStock::class)->run();

        $change = LotStateChange::sole();

        $this->assertSame(LotState::Unserviceable, $change->to_state);
        $this->assertNull($change->user_id);
        $this->assertNull($change->determined_by_name);
        $this->assertNull($change->qualification_reference);
        $this->assertStringContainsString('Lagerzeit', $change->reason);
    }

    #[Test]
    public function expired_resin_never_needs_the_unsalvageable_step(): void
    {
        // the objection, end to end: expired, unserviceable by itself, and
        // into the bin in a single human act.
        $resin = $this->resin();
        app(ReceiveStock::class)->handle($resin, 5, now()->subYears(2)->toDateString(), lotData: $this->certified());

        app(ExpireStock::class)->run();

        $lot = StockLot::sole();
        $this->assertSame(LotState::Unserviceable, $lot->state);

        app(DisposeStock::class)->handle(
            $resin->fresh(), 5, $lot->fresh(), $this->storeman(), 'Abgelaufen, entsorgt',
        );

        $this->assertSame(LotState::Disposed, $lot->fresh()->state);
        $this->assertSame(0.0, $resin->fresh()->currentStock());

        $states = LotStateChange::where('stock_lot_id', $lot->id)->pluck('to_state')->all();
        $this->assertNotContains(LotState::Unsalvageable, $states, 'Never passed through it.');
    }

    #[Test]
    public function consumables_may_be_destroyed_without_a_licence(): void
    {
        // The permission gates it; the licence is for setting the status of
        // components under 145.A.42.
        $resin = $this->resin();
        app(ReceiveStock::class)->handle($resin, 5, now()->subYears(2)->toDateString(), lotData: $this->certified());

        $storeman = $this->storeman();
        $this->assertNull($storeman->qualifications()->first(), 'No Part-66 licence.');

        app(DisposeStock::class)->handle($resin->fresh(), 5, StockLot::sole(), $storeman, 'Abgelaufen');

        $this->assertSame(0.0, $resin->fresh()->currentStock());
    }

    #[Test]
    public function standard_parts_too(): void
    {
        $nuts = PartType::create([
            'name' => 'Mutter M6',
            'classification' => PartClassification::StandardPart,
            'unit_of_measure' => 'St',
        ]);
        app(ReceiveStock::class)->handle($nuts, 500, '2025-07-01', lotData: $this->certified());

        app(DisposeStock::class)->handle($nuts->fresh(), 120, null, $this->storeman(), 'Korrodiert');

        $this->assertSame(380.0, $nuts->fresh()->currentStock());
    }

    #[Test]
    public function a_component_still_needs_the_licence(): void
    {
        // Where the line actually is. Saying a component will never fly again is
        // a judgement, and somebody has to answer for it.
        $filters = PartType::create([
            'name' => 'Ölfilter Rotax 912',
            'classification' => PartClassification::Component,
            'unit_of_measure' => 'St',
            'requires_form_one' => true,
        ]);
        app(ReceiveStock::class)->handle($filters, 4, '2025-07-01', lotData: $this->certified());

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/qualified staff/');

        app(DisposeStock::class)->handle(
            $filters->fresh(), 4, StockLot::sole(), $this->storeman(), 'Wasserschaden',
        );
    }

    #[Test]
    public function a_quarantined_lot_that_expires_gets_its_answer(): void
    {
        // Being set aside pending a decision is not an answer. The date has now
        // given one.
        $resin = $this->resin();
        app(ReceiveStock::class)->handle($resin, 5, now()->subYears(2)->toDateString(), lotData: $this->certified());

        $lot = StockLot::sole();
        app(ChangeLotState::class)->handle(
            $lot, LotState::Quarantined, 'Herkunft unklar', $this->storeman(),
        );

        app(ExpireStock::class)->run();

        $this->assertSame(LotState::Unserviceable, $lot->fresh()->state);
    }

    #[Test]
    public function a_deliberate_release_is_left_alone(): void
    {
        // The manufacturer extended the shelf life and the date has not been
        // updated yet. Reverting that every night would be the software arguing
        // with a person; the right fix is the date.
        $resin = $this->resin();
        app(ReceiveStock::class)->handle($resin, 5, now()->subYears(2)->toDateString(), lotData: $this->certified());

        $lot = StockLot::sole();
        app(ExpireStock::class)->run();
        $this->assertSame(LotState::Unserviceable, $lot->fresh()->state);

        app(ChangeLotState::class)->handle(
            $lot->fresh(), LotState::Serviceable, 'Herstellerfreigabe verlängert',
            $this->qualifiedMechanic(),
        );

        app(ExpireStock::class)->run();

        $this->assertSame(LotState::Serviceable, $lot->fresh()->state, 'Left as the person left it.');
    }

    #[Test]
    public function running_it_twice_changes_nothing_the_second_time(): void
    {
        $resin = $this->resin();
        app(ReceiveStock::class)->handle($resin, 5, now()->subYears(2)->toDateString(), lotData: $this->certified());

        $this->assertCount(1, app(ExpireStock::class)->run());
        $this->assertCount(0, app(ExpireStock::class)->run());
        $this->assertSame(1, LotStateChange::count());
    }

    #[Test]
    public function stock_that_is_still_good_is_untouched(): void
    {
        $resin = $this->resin();
        app(ReceiveStock::class)->handle($resin, 5, now()->subDays(10)->toDateString(), lotData: $this->certified());

        $this->assertSame([], app(ExpireStock::class)->run());
        $this->assertSame(LotState::Serviceable, StockLot::sole()->state);
    }

    #[Test]
    public function an_empty_lot_is_not_worth_the_paperwork(): void
    {
        // Nothing on the shelf, nothing to declare unusable.
        $resin = $this->resin();
        app(ReceiveStock::class)->handle($resin, 5, now()->subYears(2)->toDateString(), lotData: $this->certified());

        app(DisposeStock::class)->handle(
            $resin->fresh(), 5, StockLot::sole(), $this->storeman(), 'Weg damit',
        );

        $this->assertSame([], app(ExpireStock::class)->run());
    }

    #[Test]
    public function the_command_reports_and_respects_a_dry_run(): void
    {
        app(ModuleManager::class)->enable('warehouse');
        app(ModuleManager::class)->forgetCache();

        $resin = $this->resin();
        app(ReceiveStock::class)->handle($resin, 5, now()->subYears(2)->toDateString(), lotData: $this->certified());

        $this->artisan('aeronance:expire-stock', ['--dry-run' => true])
            ->expectsOutputToContain('Dry run')
            ->assertSuccessful();

        $this->assertSame(LotState::Serviceable, StockLot::sole()->state, 'Nothing changed.');

        $this->artisan('aeronance:expire-stock')->assertSuccessful();

        $this->assertSame(LotState::Unserviceable, StockLot::sole()->state);
    }

    #[Test]
    public function a_disabled_module_runs_no_jobs(): void
    {
        // Module boundaries hold for scheduled work too.
        $resin = $this->resin();
        app(ReceiveStock::class)->handle($resin, 5, now()->subYears(2)->toDateString(), lotData: $this->certified());

        app(ModuleManager::class)->disable('warehouse');
        app(ModuleManager::class)->forgetCache();

        $this->artisan('aeronance:expire-stock')
            ->expectsOutputToContain('not enabled')
            ->assertSuccessful();

        $this->assertSame(LotState::Serviceable, StockLot::sole()->state);
    }

    private function resin(): PartType
    {
        return PartType::create([
            'name' => 'Harz L285',
            'classification' => PartClassification::ConsumableMaterial,
            'unit_of_measure' => 'kg',
            'shelf_life_days' => 365,
        ]);
    }

    private function userWith(string ...$permissions): User
    {
        $user = User::factory()->create(['is_active' => true]);

        foreach ($permissions as $permission) {
            $user->givePermissionTo($permission);
        }

        return $user->fresh();
    }

    private function storeman(): User
    {
        return $this->userWith(Permissions::STOCK_SCRAP, Permissions::STOCK_QUARANTINE);
    }

    private function qualifiedMechanic(): User
    {
        $user = $this->userWith(Permissions::STOCK_QUARANTINE_CERTIFY, Permissions::STOCK_SCRAP);

        Qualification::create([
            'user_id' => $user->id,
            'type' => Qualification::TYPE_PART66,
            'reference' => 'DE.66.00000',
            'category' => 'B1',
            'valid_from' => now()->subYear()->toDateString(),
        ]);

        return $user->fresh();
    }

    /**
     * Wareneingang mit Nachweis.
     *
     * Seit „ein los geht erst dann ins lager wenn das form1 da ist" verweigert
     * ReceiveStock die Einbuchung eines Teils, das ein Form 1 verlangt, ohne
     * eines. Diese Tests sind nicht darueber -- sie brauchen nur Bestand.
     */
    private function certified(?string $reference = null): array
    {
        return [
            'document_type' => StockLot::DOCUMENT_FORM_ONE,
            'document_reference' => $reference ?? 'F1-'.mb_substr(md5((string) mt_rand()), 0, 8),
        ];
    }
}
