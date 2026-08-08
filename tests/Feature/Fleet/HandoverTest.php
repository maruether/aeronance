<?php

declare(strict_types=1);

namespace Tests\Feature\Fleet;

use App\Core\Modules\ModuleManager;
use App\Models\User;
use App\Modules\Fleet\Enums\CounterKind;
use App\Modules\Fleet\Models\Aircraft;
use App\Modules\Fleet\Models\CounterReading;
use App\Modules\Fleet\Models\Installation;
use App\Modules\Warehouse\Actions\IssueStock;
use App\Modules\Warehouse\Actions\ReceiveStock;
use App\Modules\Warehouse\Enums\PartClassification;
use App\Modules\Warehouse\Models\PartType;
use App\Modules\Warehouse\Models\StockLot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The warehouse hands a part -- and its paper -- to the fleet.
 *
 * The first module interface in the project, and the analysis called for it long
 * before either side existed: "Lager übergibt Nachweis an Flotte."
 *
 * What is being checked here is as much the boundary as the behaviour. The
 * warehouse announces and stops caring; the fleet decides what to make of it;
 * neither reads the other's tables.
 */
final class HandoverTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        app(ModuleManager::class)->enable('warehouse');
        app(ModuleManager::class)->enable('fleet');
        app(ModuleManager::class)->forgetCache();
    }

    #[Test]
    public function the_certificate_travels_with_the_part(): void
    {
        // Vorgabe: "wenn ein Form 1 oder CoC dranhängt geht das papier mit aufs
        // flugzeug über. das muss erfasst sein."
        $aircraft = $this->aircraft();
        $filters = $this->filterPart();

        app(ReceiveStock::class)->handle($filters, 4, '2026-07-01', lotData: [
            'document_type' => StockLot::DOCUMENT_FORM_ONE,
            'document_reference' => 'F1-2026-8842',
            'document_issuer' => 'Musterwerft GmbH',
            'document_issuer_approval' => 'DE.145.0123',
        ]);

        app(IssueStock::class)->handle(
            $filters->fresh(), 1, StockLot::sole(), $this->mechanic(),
            aircraftReference: 'D-KABC',
        );

        $installation = Installation::sole();

        $this->assertSame($aircraft->id, $installation->aircraft_id);
        $this->assertSame('F1-2026-8842', $installation->document_reference);
        $this->assertSame('Musterwerft GmbH', $installation->document_issuer);
        $this->assertSame('DE.145.0123', $installation->document_issuer_approval);
        $this->assertSame(StockLot::sole()->lot_number, $installation->stock_lot_number);
    }

    #[Test]
    public function one_form_one_can_end_up_in_several_aircraft(): void
    {
        // The analysis: "Die vier Ölfilter aus einem Los gehen an vier
        // verschiedene Luftfahrzeuge — ein Dokument, mehrere Lebenslaufakten."
        // So the handover is a copy, never a move.
        $this->aircraft('D-KABC');
        $this->aircraft('D-KXYZ');
        $filters = $this->filterPart();

        app(ReceiveStock::class)->handle($filters, 4, '2026-07-01', lotData: [
            'document_type' => StockLot::DOCUMENT_FORM_ONE,
            'document_reference' => 'F1-2026-8842',
        ]);

        $lot = StockLot::sole();
        app(IssueStock::class)->handle($filters->fresh(), 1, $lot, $this->mechanic(), aircraftReference: 'D-KABC');
        app(IssueStock::class)->handle($filters->fresh(), 1, $lot->fresh(), $this->mechanic(), aircraftReference: 'D-KXYZ');

        $this->assertSame(2, Installation::count());
        $this->assertSame(
            ['F1-2026-8842', 'F1-2026-8842'],
            Installation::pluck('document_reference')->all(),
        );
        $this->assertSame(1, StockLot::count(), 'Still one document, one lot.');
    }

    #[Test]
    public function standard_parts_stay_out_of_the_life_record(): void
    {
        // "niemanden interessiert die mutter oder niete von würth"
        $this->aircraft();
        $nuts = PartType::create([
            'name' => 'Mutter M6 DIN 934',
            'classification' => PartClassification::StandardPart,
            'unit_of_measure' => 'St',
        ]);

        app(ReceiveStock::class)->handle($nuts, 500, '2026-07-01');
        app(IssueStock::class)->handle($nuts->fresh(), 20, null, $this->mechanic(), aircraftReference: 'D-KABC');

        $this->assertSame(0, Installation::count());
    }

    #[Test]
    public function consumables_do_get_a_line(): void
    {
        // Everything that is not a standard part. An oil filter belongs there
        // even though it will simply go with the next engine service.
        $this->aircraft();
        $oil = PartType::create([
            'name' => 'Öl Aeroshell W100',
            'classification' => PartClassification::ConsumableMaterial,
            'unit_of_measure' => 'l',
        ]);

        app(ReceiveStock::class)->handle($oil, 20, '2026-07-01');
        app(IssueStock::class)->handle($oil->fresh(), 4, null, $this->mechanic(), aircraftReference: 'D-KABC');

        $this->assertSame(1, Installation::count());
        $this->assertSame(4.0, Installation::sole()->quantity);
    }

    #[Test]
    public function an_issue_to_nowhere_records_nothing(): void
    {
        $this->aircraft();
        $filters = $this->filterPart();

        // With its certificate: this part requires one, so without it the lot
        // is quarantined and the issue is refused before any handover happens.
        app(ReceiveStock::class)->handle($filters, 4, '2026-07-01', lotData: [
            'document_type' => StockLot::DOCUMENT_FORM_ONE,
            'document_reference' => 'F1-2026-0001',
        ]);
        app(IssueStock::class)->handle($filters->fresh(), 1, StockLot::sole(), $this->mechanic());

        $this->assertSame(0, Installation::count());
    }

    #[Test]
    public function an_unknown_registration_is_left_alone(): void
    {
        // Better absent than misfiled. The warehouse takes a registration as
        // free text and always has -- it need not have a fleet behind it.
        $this->aircraft('D-KABC');
        $filters = $this->filterPart();

        // With its certificate: this part requires one, so without it the lot
        // is quarantined and the issue is refused before any handover happens.
        app(ReceiveStock::class)->handle($filters, 4, '2026-07-01', lotData: [
            'document_type' => StockLot::DOCUMENT_FORM_ONE,
            'document_reference' => 'F1-2026-0001',
        ]);
        app(IssueStock::class)->handle(
            $filters->fresh(), 1, StockLot::sole(), $this->mechanic(),
            aircraftReference: 'D-NEVERHEARD',
        );

        $this->assertSame(0, Installation::count());
    }

    #[Test]
    public function the_warehouse_works_perfectly_well_without_a_fleet(): void
    {
        // The boundary in its plainest form: switch the fleet off and the issue
        // still books. The warehouse announces and stops caring.
        $this->aircraft();
        $filters = $this->filterPart();

        app(ModuleManager::class)->disable('fleet');
        app(ModuleManager::class)->forgetCache();

        // With its certificate: this part requires one, so without it the lot
        // is quarantined and the issue is refused before any handover happens.
        app(ReceiveStock::class)->handle($filters, 4, '2026-07-01', lotData: [
            'document_type' => StockLot::DOCUMENT_FORM_ONE,
            'document_reference' => 'F1-2026-0001',
        ]);
        app(IssueStock::class)->handle(
            $filters->fresh(), 1, StockLot::sole(), $this->mechanic(),
            aircraftReference: 'D-KABC',
        );

        $this->assertSame(0, Installation::count());
        $this->assertSame(3.0, $filters->fresh()->currentStock(), 'The booking stands.');
    }

    #[Test]
    public function no_life_limits_are_invented(): void
    {
        // "nicht jedes bauteil eine laufzeit hat". A guess here would be wrong
        // more often than right, and wrong in the direction of a false sense of
        // control.
        $this->aircraft();
        $filters = $this->filterPart();

        // With its certificate: this part requires one, so without it the lot
        // is quarantined and the issue is refused before any handover happens.
        app(ReceiveStock::class)->handle($filters, 4, '2026-07-01', lotData: [
            'document_type' => StockLot::DOCUMENT_FORM_ONE,
            'document_reference' => 'F1-2026-0001',
        ]);
        app(IssueStock::class)->handle($filters->fresh(), 1, StockLot::sole(), $this->mechanic(), aircraftReference: 'D-KABC');

        $this->assertCount(0, Installation::sole()->limits);
        $this->assertNull(Installation::sole()->nextDue());
    }

    #[Test]
    public function the_counters_are_snapshotted_so_usage_can_be_worked_out_later(): void
    {
        $aircraft = $this->aircraft();
        CounterReading::create([
            'aircraft_id' => $aircraft->id,
            'kind' => CounterKind::FlightHours,
            'value' => 1234.5,
            'read_at' => '2026-07-01',
        ]);

        $filters = $this->filterPart();
        // With its certificate: this part requires one, so without it the lot
        // is quarantined and the issue is refused before any handover happens.
        app(ReceiveStock::class)->handle($filters, 4, '2026-07-01', lotData: [
            'document_type' => StockLot::DOCUMENT_FORM_ONE,
            'document_reference' => 'F1-2026-0001',
        ]);
        app(IssueStock::class)->handle($filters->fresh(), 1, StockLot::sole(), $this->mechanic(), aircraftReference: 'D-KABC');

        $this->assertSame(
            1234.5,
            Installation::sole()->counters_at_installation['flight_hours'],
        );
    }

    #[Test]
    public function a_part_fitted_before_the_first_reading_has_unknown_usage_not_a_jump(): void
    {
        // The trap this guards: currentValue() used to answer 0.0 for a counter
        // that had never been read, and that zero got FROZEN into the snapshot.
        // When the real reading arrived later (3000 h), usage() computed
        // 3000 - 0 -- TSN/TSO high by the aircraft's whole life, for every part
        // fitted in that window. "Never read" is "unknown", and unknown must
        // stay unknown instead of turning into a comforting zero.
        $aircraft = $this->aircraft();

        $filters = $this->filterPart();
        app(ReceiveStock::class)->handle($filters, 4, '2026-07-01', lotData: [
            'document_type' => StockLot::DOCUMENT_FORM_ONE,
            'document_reference' => 'F1-2026-0001',
        ]);
        app(IssueStock::class)->handle(
            $filters->fresh(), 1, StockLot::sole(), $this->mechanic(),
            aircraftReference: 'D-KABC',
        );

        $installation = Installation::sole();

        // The snapshot records what was known: nothing.
        $this->assertArrayNotHasKey(
            'flight_hours',
            $installation->counters_at_installation ?? [],
        );

        // The first real reading arrives -- and the part inherits NOTHING.
        CounterReading::create([
            'aircraft_id' => $aircraft->id,
            'kind' => CounterKind::FlightHours,
            'value' => 3000.0,
            'read_at' => '2026-07-15',
        ]);

        $this->assertNull(
            $installation->fresh()->timeSinceNew(CounterKind::FlightHours),
            'Usage since an unknown starting point is unknown, not 3000 h.',
        );
    }

    private function aircraft(string $registration = 'D-KABC'): Aircraft
    {
        return Aircraft::create(['registration' => $registration, 'model' => 'ASK 21']);
    }

    private function filterPart(): PartType
    {
        return PartType::create([
            'name' => 'Ölfilter Rotax 912',
            'classification' => PartClassification::Component,
            'unit_of_measure' => 'St',
            'requires_form_one' => true,
        ]);
    }

    private function mechanic(): User
    {
        return User::factory()->create(['is_active' => true]);
    }
}
