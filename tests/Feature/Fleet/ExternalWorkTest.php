<?php

declare(strict_types=1);

namespace Tests\Feature\Fleet;

use App\Core\Models\Qualification;
use App\Models\User;
use App\Modules\Fleet\Actions\CommissionExternalWork;
use App\Modules\Fleet\Actions\ListInMaintenanceProgramme;
use App\Modules\Fleet\Airworthiness\AirworthinessCheck;
use App\Modules\Fleet\Enums\ExternalWorkState;
use App\Modules\Fleet\Enums\InstallationOrigin;
use App\Modules\Fleet\Enums\ReleasedBy;
use App\Modules\Fleet\Models\Aircraft;
use App\Modules\Fleet\Models\AirworthinessReview;
use App\Modules\Fleet\Models\ExternalWorkOrder;
use App\Modules\Fleet\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Work given to another organisation.
 *
 * Vorgabe: "Es kann sein das ich eine Wartung oder Reparatur extern vergebe. Wenn
 * dabei Teile reinkommen muss ich das irgendwie dokumentieren. Es ist dabei
 * offen ob ich selbst freigebe oder die fremdwerft."
 *
 * The open question is the interesting one, and it must stay answerable
 * afterwards: signing for work somebody else performed is a different position
 * from recording that they signed it themselves.
 */
final class ExternalWorkTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        foreach ([Permissions::EXTERNAL_WORK_MANAGE, Permissions::EXTERNAL_WORK_ACCEPT] as $p) {
            Permission::findOrCreate($p, 'web');
        }
    }

    #[Test]
    public function coming_back_is_not_the_same_as_being_released(): void
    {
        // The gap that matters. The aircraft is in the hangar and looks
        // finished, which is exactly when somebody flies it because it is
        // "wieder da".
        $order = $this->commissioned();

        $order = app(CommissionExternalWork::class)->receive(
            $order, $this->storeman(), 'Arbeitsbericht 2026-114',
        );

        $this->assertSame(ExternalWorkState::Returned, $order->state);
        $this->assertFalse($order->isReleased());
        $this->assertTrue($order->isAwaitingRelease());
    }

    #[Test]
    public function an_aircraft_back_without_a_release_shows_up_as_open(): void
    {
        $order = $this->commissioned();
        app(CommissionExternalWork::class)->receive($order, $this->storeman());

        $items = app(AirworthinessCheck::class)->openItemsFor($order->aircraft->fresh());

        $this->assertCount(1, $items);
        $this->assertStringContainsString('noch keine Freigabe', $items[0]->detail);
    }

    #[Test]
    public function an_aircraft_still_away_does_not(): void
    {
        // It is somewhere else and nobody is about to fly it. Reporting that as
        // an open item would be noise on every list for weeks.
        $order = $this->commissioned();

        $this->assertSame([], app(AirworthinessCheck::class)->openItemsFor($order->aircraft->fresh()));
    }

    #[Test]
    public function the_shop_can_sign_its_own_work(): void
    {
        // Their authority, their approval number. We write down what the paper
        // says and claim nothing ourselves.
        $order = $this->returned();

        $released = app(CommissionExternalWork::class)->release(
            $order,
            ReleasedBy::External,
            $this->storeman(),
            releaseReference: 'CRS-2026-77',
            externalSignatory: 'H. Ankert',
            externalApproval: 'DE.145.0123',
        );

        $this->assertSame(ReleasedBy::External, $released->released_by);
        $this->assertSame('H. Ankert', $released->released_by_name);
        $this->assertSame('DE.145.0123', $released->released_by_approval);
        $this->assertNull($released->qualification_reference, 'Nothing of ours is claimed.');
        $this->assertTrue($released->isReleased());
    }

    #[Test]
    public function an_external_release_has_to_name_who_signed(): void
    {
        // A certificate with no signatory is not one.
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/name who signed/');

        app(CommissionExternalWork::class)->release(
            $this->returned(), ReleasedBy::External, $this->storeman(),
        );
    }

    #[Test]
    public function signing_it_ourselves_is_a_determination(): void
    {
        // Somebody here accepts work they did not watch, on the strength of a
        // report. That is a judgement, and the credential is frozen with it.
        $order = $this->returned();
        $mechanic = $this->qualifiedMechanic();

        $released = app(CommissionExternalWork::class)->release(
            $order, ReleasedBy::Internal, $mechanic, releaseReference: 'CRS-2026-9',
        );

        $this->assertSame(ReleasedBy::Internal, $released->released_by);
        $this->assertSame($mechanic->name, $released->released_by_name);
        $this->assertSame('DE.66.12345', $released->qualification_reference);
        $this->assertSame($mechanic->id, $released->released_by_user);
    }

    #[Test]
    public function without_a_licence_we_cannot_sign_it_ourselves(): void
    {
        // Two different refusals with two different messages, which is the point
        // of the two-stage check: lacking the permission is an administrative
        // matter, lacking the licence is a statement about the person. The first
        // version of this test conflated them and got the wrong message.
        $order = $this->returned();

        try {
            app(CommissionExternalWork::class)->release(
                $order, ReleasedBy::Internal, $this->storeman(),
            );
            $this->fail('Without the permission this must be refused.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('fleet.external_work.accept', $e->getMessage());
        }

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/qualified staff/');

        app(CommissionExternalWork::class)->release(
            $order->fresh(), ReleasedBy::Internal, $this->userWith(Permissions::EXTERNAL_WORK_ACCEPT),
        );
    }

    #[Test]
    public function a_pilot_owner_may_never_release_external_work(): void
    {
        // This test asserted the opposite until the rule was pointed out:
        // "crs darf fremdarbeiten freigeben. PO explizit nur das was er selbst
        // gemacht hat."
        //
        // It is a definition rather than a threshold. External work IS work
        // somebody else performed, so no version of this act falls inside a
        // pilot-owner authorisation -- not even on their own aircraft, which is
        // exactly the case the old version let through.
        $order = $this->returned();
        $owner = $this->userWith(Permissions::EXTERNAL_WORK_ACCEPT);

        app(ListInMaintenanceProgramme::class)->add($order->aircraft, $owner);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/carried out\s+personally/');

        app(CommissionExternalWork::class)->release(
            $order, ReleasedBy::Internal, $owner->fresh(),
        );
    }

    #[Test]
    public function a_part_the_shop_fitted_carries_its_own_provenance(): void
    {
        // A third position: not witnessed by us like a stock issue, not
        // historical like an onboarding line. It came out of their store, into
        // our aircraft, while it was our responsibility.
        $order = $this->returned();

        $part = app(CommissionExternalWork::class)->recordFittedPart(
            $order,
            'Zylinderkopfdichtung',
            $this->storeman(),
            attributes: ['part_number' => '825-165'],
        );

        $this->assertSame(InstallationOrigin::External, $part->origin);
        $this->assertTrue($part->wasTranscribed(), 'Not our own evidence.');
        $this->assertStringContainsString('Arbeitsbericht', $part->transcribed_from);
        $this->assertSame($order->id, $part->external_work_order_id);
    }

    #[Test]
    public function the_chain_back_to_the_order_is_walkable(): void
    {
        $order = $this->returned();

        app(CommissionExternalWork::class)->recordFittedPart(
            $order, 'Zylinderkopfdichtung', $this->storeman(),
        );

        $this->assertSame(1, $order->fresh()->installations()->count());
        $this->assertSame(
            $order->shop_name,
            $order->fresh()->installations()->sole()->externalWorkOrder->shop_name,
        );
    }

    #[Test]
    public function what_was_commissioned_has_to_be_recorded(): void
    {
        // "External work" on its own answers nothing a year later.
        $this->expectException(InvalidArgumentException::class);

        app(CommissionExternalWork::class)->commission(
            $this->aircraft(), 'Musterwerft GmbH', '   ', $this->storeman(),
        );
    }

    #[Test]
    public function it_cannot_be_released_twice_or_received_twice(): void
    {
        $order = $this->returned();
        $action = app(CommissionExternalWork::class);

        $action->release($order, ReleasedBy::External, $this->storeman(), externalSignatory: 'H. Ankert');

        try {
            $action->receive($order->fresh(), $this->storeman());
            $this->fail('An order that is already back must not be received again.');
        } catch (RuntimeException) {
        }

        $this->expectException(RuntimeException::class);
        $action->release($order->fresh(), ReleasedBy::External, $this->storeman(), externalSignatory: 'H. Ankert');
    }

    #[Test]
    public function an_overdue_order_can_be_spotted(): void
    {
        $order = $this->commissioned();
        $order->update(['expected_back_at' => now()->subWeeks(3)->toDateString()]);

        $this->assertTrue($order->fresh()->isOverdue());
        $this->assertSame(1, ExternalWorkOrder::open()->count());
    }

    private function aircraft(): Aircraft
    {
        $aircraft = Aircraft::create([
            'registration' => 'D-K'.strtoupper(substr(uniqid(), -4)),
            'model' => 'ASK 21 Mi',
            'optional_counters' => ['engine_hours'],
        ]);

        AirworthinessReview::create([
            'aircraft_id' => $aircraft->id,
            'issued_at' => now()->subMonths(2)->toDateString(),
            'valid_until' => now()->addMonths(10)->toDateString(),
        ]);

        return $aircraft->fresh();
    }

    private function commissioned(): ExternalWorkOrder
    {
        return app(CommissionExternalWork::class)->commission(
            $this->aircraft(),
            'Musterwerft GmbH',
            'Motorüberholung nach 1000 h',
            $this->storeman(),
            shopApproval: 'DE.145.0123',
        );
    }

    private function returned(): ExternalWorkOrder
    {
        return app(CommissionExternalWork::class)->receive(
            $this->commissioned(), $this->storeman(), 'Arbeitsbericht 2026-114',
        );
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
        return $this->userWith(Permissions::EXTERNAL_WORK_MANAGE);
    }

    private function qualifiedMechanic(): User
    {
        $user = $this->userWith(Permissions::EXTERNAL_WORK_MANAGE, Permissions::EXTERNAL_WORK_ACCEPT);

        Qualification::create([
            'user_id' => $user->id,
            'type' => Qualification::TYPE_PART66,
            'reference' => 'DE.66.12345',
            'category' => 'B1',
            'valid_from' => now()->subYear()->toDateString(),
        ]);

        return $user->fresh();
    }
}
