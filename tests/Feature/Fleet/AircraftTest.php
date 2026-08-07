<?php

declare(strict_types=1);

namespace Tests\Feature\Fleet;

use App\Core\Access\AccessSetup;
use App\Core\Access\Authority;
use App\Core\Models\Qualification;
use App\Core\Modules\ModuleManager;
use App\Models\User;
use App\Modules\Fleet\Actions\ListInMaintenanceProgramme;
use App\Modules\Fleet\Enums\CounterKind;
use App\Modules\Fleet\Models\Aircraft;
use App\Modules\Fleet\Models\CounterReading;
use App\Modules\Fleet\Models\Holder;
use App\Modules\Fleet\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Aircraft, their counters, and who may sign for them.
 */
final class AircraftTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function every_aircraft_keeps_flight_time_and_landings(): void
    {
        // Vorgabe: "das muss gesetzlich geregelt erfasst werden". So they are
        // added rather than stored -- no migration, no import and no hand-edited
        // row can produce an aircraft that has stopped keeping them.
        $aircraft = Aircraft::create(['registration' => 'D-KABC', 'model' => 'ASK 21']);

        $this->assertTrue($aircraft->keeps(CounterKind::FlightHours));
        $this->assertTrue($aircraft->keeps(CounterKind::Landings));
        $this->assertCount(2, $aircraft->counters());
    }

    #[Test]
    public function an_engine_counter_is_configured_not_assumed(): void
    {
        // The detail that would have been easy to get wrong: not every aircraft
        // with an engine has an engine counter. Deriving one from the other
        // would have invented readings nobody takes.
        $glider = Aircraft::create(['registration' => 'D-KABC', 'model' => 'ASK 21']);
        $tug = Aircraft::create([
            'registration' => 'D-EFGH',
            'model' => 'Robin DR 400',
            'optional_counters' => [CounterKind::EngineHours->value],
        ]);

        $this->assertFalse($glider->keeps(CounterKind::EngineHours));
        $this->assertTrue($tug->keeps(CounterKind::EngineHours));
        $this->assertCount(3, $tug->counters());
    }

    #[Test]
    public function the_mandatory_counters_cannot_be_switched_off(): void
    {
        // Even asking for nothing but engine hours leaves the two in place.
        $aircraft = Aircraft::create([
            'registration' => 'D-EFGH',
            'model' => 'Robin DR 400',
            'optional_counters' => [CounterKind::EngineHours->value],
        ]);

        $this->assertTrue($aircraft->keeps(CounterKind::FlightHours));
        $this->assertTrue($aircraft->keeps(CounterKind::Landings));
    }

    #[Test]
    public function the_latest_reading_is_the_current_value(): void
    {
        $aircraft = Aircraft::create(['registration' => 'D-KABC', 'model' => 'ASK 21']);

        $this->reading($aircraft, CounterKind::FlightHours, 1200.5, '2026-06-01');
        $this->reading($aircraft, CounterKind::FlightHours, 1240.25, '2026-07-01');

        $this->assertSame(1240.25, $aircraft->fresh()->currentValue(CounterKind::FlightHours));
    }

    #[Test]
    public function a_reading_can_never_be_edited_or_deleted(): void
    {
        // The same rule as the stock ledger, for the same reason: an operating
        // history that can be revised is not a history.
        $aircraft = Aircraft::create(['registration' => 'D-KABC', 'model' => 'ASK 21']);
        $reading = $this->reading($aircraft, CounterKind::Landings, 4200, '2026-07-01');

        try {
            $reading->update(['value' => 10]);
            $this->fail('A reading must not be editable.');
        } catch (RuntimeException) {
        }

        $this->expectException(RuntimeException::class);
        $reading->delete();
    }

    #[Test]
    public function a_wrong_reading_is_corrected_by_another_one(): void
    {
        $aircraft = Aircraft::create(['registration' => 'D-KABC', 'model' => 'ASK 21']);
        $wrong = $this->reading($aircraft, CounterKind::Landings, 42000, '2026-07-01');

        $correction = CounterReading::create([
            'aircraft_id' => $aircraft->id,
            'kind' => CounterKind::Landings,
            'value' => 4200,
            'read_at' => '2026-07-01',
            'corrects_reading_id' => $wrong->id,
            'note' => 'Zahlendreher',
        ]);

        $this->assertSame(4200.0, $aircraft->fresh()->currentValue(CounterKind::Landings));
        $this->assertSame($wrong->id, $correction->corrects->id);
        $this->assertSame(2, $aircraft->readings()->count(), 'Both stay visible.');
    }

    #[Test]
    public function an_unread_counter_reads_nothing_rather_than_guessing(): void
    {
        $aircraft = Aircraft::create(['registration' => 'D-KABC', 'model' => 'ASK 21']);

        $this->assertNull($aircraft->latestReading(CounterKind::FlightHours));
        $this->assertSame(0.0, $aircraft->currentValue(CounterKind::FlightHours));
    }

    #[Test]
    public function a_private_holder_is_an_entity_not_a_name(): void
    {
        // Part-ML pins the continuing airworthiness duty on the holder, so a
        // privately held aircraft in the club's care answers to its owner.
        $member = User::factory()->create(['is_active' => true]);

        $private = Holder::create([
            'name' => 'Erika Mustermann',
            'type' => Holder::TYPE_PRIVATE,
            'user_id' => $member->id,
        ]);
        $club = Holder::create(['name' => 'Akaflieg Freiburg e.V.', 'type' => Holder::TYPE_CLUB]);

        $aircraft = Aircraft::create([
            'registration' => 'D-KABC',
            'model' => 'ASK 21',
            'holder_id' => $private->id,
        ]);

        $this->assertFalse($private->isClub());
        $this->assertTrue($club->isClub());
        $this->assertSame($member->id, $aircraft->holder->user->id);
    }

    #[Test]
    public function being_named_in_the_programme_is_what_grants_pilot_owner_authority(): void
    {
        // THE point from Vorgabe: "ich darf auch an Privatflugzeugen nach
        // Pilot-Owner freigeben, solange ich im AMP aufgeführt bin." Not
        // ownership -- the naming.
        app(AccessSetup::class)->run();

        $someoneElsesAircraft = Aircraft::create([
            'registration' => 'D-KABC',
            'model' => 'ASK 21',
            'holder_id' => Holder::create(['name' => 'Erika Mustermann'])->id,
        ]);

        $mechanic = $this->mechanicWhoMayRelease();

        $authority = app(Authority::class);

        $this->assertFalse(
            $authority->certifiesFor($mechanic, 'releases.issue', 'D-KABC'),
            'Not named yet, so no authority -- even with the permission.',
        );

        app(ListInMaintenanceProgramme::class)->add($someoneElsesAircraft, $mechanic);

        $this->assertTrue(
            $authority->certifiesFor($mechanic->fresh(), 'releases.issue', 'D-KABC'),
            'Named in the AMP of an aircraft they do not own -- and that is enough.',
        );
    }

    #[Test]
    public function the_authority_reaches_only_the_aircraft_it_was_given_for(): void
    {
        app(AccessSetup::class)->run();

        $one = Aircraft::create(['registration' => 'D-KABC', 'model' => 'ASK 21']);
        Aircraft::create(['registration' => 'D-KXYZ', 'model' => 'ASK 21']);

        $mechanic = $this->mechanicWhoMayRelease();

        app(ListInMaintenanceProgramme::class)->add($one, $mechanic);

        $authority = app(Authority::class);

        $this->assertTrue($authority->certifiesFor($mechanic->fresh(), 'releases.issue', 'D-KABC'));
        $this->assertFalse($authority->certifiesFor($mechanic->fresh(), 'releases.issue', 'D-KXYZ'));
    }

    #[Test]
    public function taking_somebody_off_the_programme_ends_the_authority_without_erasing_it(): void
    {
        // It was true until today. A record that vanishes cannot answer whether
        // an act performed last spring was covered.
        app(AccessSetup::class)->run();

        $aircraft = Aircraft::create(['registration' => 'D-KABC', 'model' => 'ASK 21']);
        $mechanic = $this->mechanicWhoMayRelease();

        $action = app(ListInMaintenanceProgramme::class);
        $action->add($aircraft, $mechanic);
        $action->remove($aircraft, $mechanic->fresh());

        $this->assertFalse(
            app(Authority::class)->certifiesFor($mechanic->fresh(), 'releases.issue', 'D-KABC'),
        );

        $qualification = Qualification::where('user_id', $mechanic->id)
            ->where('type', Qualification::TYPE_PILOT_OWNER)
            ->sole();

        $this->assertNotNull($qualification, 'The record stays.');
        $this->assertNotNull($qualification->valid_until, 'It is ended, not deleted.');
    }

    #[Test]
    public function the_module_declares_its_own_permissions(): void
    {
        app(AccessSetup::class)->run();
        app(ModuleManager::class)->enable('fleet');
        app(ModuleManager::class)->forgetCache();

        $admin = User::factory()->create(['is_active' => true]);
        $admin->givePermissionTo(Permissions::FLEET_MANAGE);

        $this->assertTrue($admin->fresh()->can(Permissions::FLEET_MANAGE));
        $this->assertFalse($admin->fresh()->can(Permissions::PROGRAMME_MANAGE));
    }

    /**
     * Somebody allowed to sign a release.
     *
     * The permission itself belongs to the releases module, which does not exist
     * yet -- Authority names it ahead of time so the rule "this needs a
     * qualification" is stated once rather than retrofitted across policies
     * later. Creating it here is what that module will do when it arrives.
     */
    private function mechanicWhoMayRelease(): User
    {
        Permission::findOrCreate('releases.issue', 'web');

        $user = User::factory()->create(['is_active' => true]);
        $user->givePermissionTo('releases.issue');

        return $user->fresh();
    }

    private function reading(Aircraft $aircraft, CounterKind $kind, float $value, string $on): CounterReading
    {
        return CounterReading::create([
            'aircraft_id' => $aircraft->id,
            'kind' => $kind,
            'value' => $value,
            'read_at' => $on,
        ]);
    }
}
