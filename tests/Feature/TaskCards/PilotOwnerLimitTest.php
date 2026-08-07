<?php

declare(strict_types=1);

namespace Tests\Feature\TaskCards;

use App\Core\Models\Qualification;
use App\Models\User;
use App\Modules\Fleet\Actions\CommissionExternalWork;
use App\Modules\Fleet\Actions\ListInMaintenanceProgramme;
use App\Modules\Fleet\Enums\ReleasedBy;
use App\Modules\Fleet\Models\Aircraft;
use App\Modules\Fleet\Permissions as FleetPermissions;
use App\Modules\TaskCards\Actions\CertifyTaskCard;
use App\Modules\TaskCards\Actions\ManageWorkOrder;
use App\Modules\TaskCards\Enums\ParticipationKind;
use App\Modules\TaskCards\Models\TaskCard;
use App\Modules\TaskCards\Models\WorkOrder;
use App\Modules\TaskCards\Permissions;
use App\Modules\TaskCards\Support\OwnWorkOnly;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * A pilot-owner signs only for their own work.
 *
 * A correction to two places where I had treated the two qualifications as
 * interchangeable: "crs darf fremdarbeiten freigeben. PO explizit nur das was er
 * selbst gemacht hat. das steht hart in der 1321/2014 drin."
 *
 * Part-66 certifying staff release work whoever performed it -- that is what the
 * licence is for. A pilot-owner authorisation lets somebody sign for their own
 * limited maintenance on their own aircraft and nothing else. They are not
 * degrees of the same privilege.
 */
final class PilotOwnerLimitTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        foreach ([
            Permissions::CARDS_WORK,
            Permissions::CARDS_CERTIFY,
            Permissions::WORK_ORDERS_MANAGE,
            FleetPermissions::EXTERNAL_WORK_MANAGE,
            FleetPermissions::EXTERNAL_WORK_ACCEPT,
        ] as $p) {
            Permission::findOrCreate($p, 'web');
        }
    }

    #[Test]
    public function a_pilot_owner_may_sign_a_card_they_did_alone(): void
    {
        $owner = $this->pilotOwner();
        $card = $this->cardWorkedOnBy([$owner]);

        app(CertifyTaskCard::class)->complete($card, $owner, 'Ölwechsel gemacht');

        $certified = app(CertifyTaskCard::class)->certify($card->fresh(), $owner->fresh());

        $this->assertTrue($certified->isCertified());
        $this->assertSame(Qualification::TYPE_PILOT_OWNER, $certified->qualification_type);
    }

    #[Test]
    public function but_not_one_somebody_else_worked_on(): void
    {
        // THE rule. Reading it loosely -- "he did some of it" -- would let a
        // pilot-owner certify a mechanic's work by putting his name on ten
        // minutes of it, which is the arrangement the rule exists to prevent.
        $owner = $this->pilotOwner();
        $mechanic = $this->userWith(Permissions::CARDS_WORK);

        $card = $this->cardWorkedOnBy([$owner, $mechanic]);
        app(CertifyTaskCard::class)->complete($card, $owner, 'Zusammen gemacht');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/carried out personally/');

        app(CertifyTaskCard::class)->certify($card->fresh(), $owner->fresh());
    }

    #[Test]
    public function the_refusal_names_who_else_was_on_it(): void
    {
        // So the person reading it knows what to do next, rather than being told
        // only that they may not.
        $owner = $this->pilotOwner();
        $mechanic = $this->userWith(Permissions::CARDS_WORK);

        $card = $this->cardWorkedOnBy([$owner, $mechanic]);
        app(CertifyTaskCard::class)->complete($card, $owner, 'Zusammen gemacht');

        try {
            app(CertifyTaskCard::class)->certify($card->fresh(), $owner->fresh());
            $this->fail('Should have been refused.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString($mechanic->name, $e->getMessage());
        }
    }

    #[Test]
    public function a_part_66_licence_signs_the_same_card_without_trouble(): void
    {
        // The other half of the distinction: releasing somebody else's work is
        // exactly what the licence is for.
        $owner = $this->pilotOwner();
        $mechanic = $this->userWith(Permissions::CARDS_WORK);

        $card = $this->cardWorkedOnBy([$owner, $mechanic]);
        app(CertifyTaskCard::class)->complete($card, $mechanic, 'Gemacht');

        $certified = app(CertifyTaskCard::class)->certify($card->fresh(), $this->licensedInspector());

        $this->assertTrue($certified->isCertified());
        $this->assertSame(Qualification::TYPE_PART66, $certified->qualification_type);
    }

    #[Test]
    public function being_supervised_counts_as_somebody_elses_involvement(): void
    {
        // Being supervised means another person answered for how it was done,
        // and signing that off would be signing for their judgement too.
        $owner = $this->pilotOwner();
        $supervisor = $this->userWith(Permissions::CARDS_WORK);

        $card = app(ManageWorkOrder::class)->addCard($this->workOrder(), 'Ölwechsel');
        app(ManageWorkOrder::class)->recordTime($card, $owner, 90, ParticipationKind::Executed);
        app(ManageWorkOrder::class)->recordTime($card, $supervisor, 20, ParticipationKind::Supervised);

        app(CertifyTaskCard::class)->complete($card->fresh(), $owner, 'Gemacht');

        $this->expectException(RuntimeException::class);
        app(CertifyTaskCard::class)->certify($card->fresh(), $owner->fresh());
    }

    #[Test]
    public function a_pilot_owner_may_never_release_external_work(): void
    {
        // Not a threshold but a definition: external work IS work somebody else
        // performed, so no version of this act falls inside the authorisation.
        // My first version accepted either qualification here, which let an
        // owner sign off a shop's work on their own aircraft.
        $aircraft = $this->aircraft();
        $owner = $this->pilotOwner($aircraft, FleetPermissions::EXTERNAL_WORK_ACCEPT);

        $order = app(CommissionExternalWork::class)->commission(
            $aircraft, 'Musterwerft GmbH', 'Motorüberholung',
            $this->userWith(FleetPermissions::EXTERNAL_WORK_MANAGE),
        );
        app(CommissionExternalWork::class)->receive(
            $order, $this->userWith(FleetPermissions::EXTERNAL_WORK_MANAGE), 'Bericht 2026-114',
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/carried out\s+personally/');

        app(CommissionExternalWork::class)->release(
            $order->fresh(), ReleasedBy::Internal, $owner->fresh(),
        );
    }

    #[Test]
    public function a_part_66_licence_may(): void
    {
        $aircraft = $this->aircraft();

        $order = app(CommissionExternalWork::class)->commission(
            $aircraft, 'Musterwerft GmbH', 'Motorüberholung',
            $this->userWith(FleetPermissions::EXTERNAL_WORK_MANAGE),
        );
        app(CommissionExternalWork::class)->receive(
            $order, $this->userWith(FleetPermissions::EXTERNAL_WORK_MANAGE), 'Bericht',
        );

        $released = app(CommissionExternalWork::class)->release(
            $order->fresh(),
            ReleasedBy::Internal,
            $this->licensedInspector(FleetPermissions::EXTERNAL_WORK_ACCEPT),
        );

        $this->assertSame(Qualification::TYPE_PART66, $released->qualification_type);
    }

    #[Test]
    public function and_the_shop_signing_its_own_work_is_unaffected(): void
    {
        // Recording that somebody else signed claims nothing of ours, so no
        // qualification is involved at all.
        $aircraft = $this->aircraft();

        $order = app(CommissionExternalWork::class)->commission(
            $aircraft, 'Musterwerft GmbH', 'Motorüberholung',
            $this->userWith(FleetPermissions::EXTERNAL_WORK_MANAGE),
        );
        app(CommissionExternalWork::class)->receive(
            $order, $this->userWith(FleetPermissions::EXTERNAL_WORK_MANAGE), 'Bericht',
        );

        $released = app(CommissionExternalWork::class)->release(
            $order->fresh(),
            ReleasedBy::External,
            $this->userWith(FleetPermissions::EXTERNAL_WORK_MANAGE),
            externalSignatory: 'H. Ankert',
        );

        $this->assertSame(ReleasedBy::External, $released->released_by);
    }

    #[Test]
    public function a_card_with_no_hours_is_not_own_work_either(): void
    {
        // Nothing recorded is not the same as "all mine". The completion check
        // catches this first, but the rule has to hold on its own.
        $owner = $this->pilotOwner();
        $card = app(ManageWorkOrder::class)->addCard($this->workOrder(), 'Ölwechsel');

        $this->assertFalse(OwnWorkOnly::isEntirelyOwnWork($card, $owner));
    }

    private function aircraft(): Aircraft
    {
        return Aircraft::firstOrCreate(
            ['registration' => 'D-KABC'],
            ['model' => 'ASK 21'],
        );
    }

    private function workOrder(): WorkOrder
    {
        return app(ManageWorkOrder::class)->open(
            $this->aircraft(),
            'Jahresnachprüfung',
            $this->userWith(Permissions::WORK_ORDERS_MANAGE),
        );
    }

    /** @param  list<User>  $people */
    private function cardWorkedOnBy(array $people): TaskCard
    {
        $card = app(ManageWorkOrder::class)->addCard($this->workOrder(), 'Ölwechsel');

        foreach ($people as $person) {
            app(ManageWorkOrder::class)->recordTime($card, $person, 60, ParticipationKind::Executed);
        }

        return $card->fresh();
    }

    private function userWith(string ...$permissions): User
    {
        $user = User::factory()->create(['is_active' => true]);

        foreach ($permissions as $permission) {
            $user->givePermissionTo($permission);
        }

        return $user->fresh();
    }

    private function pilotOwner(?Aircraft $aircraft = null, string ...$extra): User
    {
        $user = $this->userWith(Permissions::CARDS_WORK, Permissions::CARDS_CERTIFY, ...$extra);

        app(ListInMaintenanceProgramme::class)->add($aircraft ?? $this->aircraft(), $user);

        return $user->fresh();
    }

    private function licensedInspector(string ...$extra): User
    {
        $user = $this->userWith(Permissions::CARDS_CERTIFY, ...$extra);

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
