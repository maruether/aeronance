<?php

declare(strict_types=1);

namespace Tests\Feature\TaskCards;

use App\Core\Models\Qualification;
use App\Models\User;
use App\Modules\Fleet\Actions\ListInMaintenanceProgramme;
use App\Modules\Fleet\Enums\CounterKind;
use App\Modules\Fleet\Enums\LimitKind;
use App\Modules\Fleet\Models\Aircraft;
use App\Modules\Fleet\Models\ComponentLimit;
use App\Modules\Fleet\Models\CounterReading;
use App\Modules\Fleet\Models\Installation;
use App\Modules\TaskCards\Actions\CertifyTaskCard;
use App\Modules\TaskCards\Actions\IssueRelease;
use App\Modules\TaskCards\Actions\ManageWorkOrder;
use App\Modules\TaskCards\Enums\ParticipationKind;
use App\Modules\TaskCards\Enums\TaskCardState;
use App\Modules\TaskCards\Models\TaskCard;
use App\Modules\TaskCards\Models\WorkOrder;
use App\Modules\TaskCards\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Two signatures on a card.
 *
 * the choice over a single one: "wer die arbeit gemacht hat, meldet sie
 * fertig. ein Qualifizierter zeichnet sie danach ab. das bildet die
 * werkstattrealität ab."
 *
 * It also resolves something one signature cannot. A mechanic without a licence
 * has to be able to finish his own card -- otherwise somebody else signs for an
 * afternoon they did not spend -- and unchecked work must not read as certified.
 */
final class TaskCardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        foreach ([
            Permissions::CARDS_WORK,
            Permissions::CARDS_CERTIFY,
            Permissions::WORK_ORDERS_MANAGE,
        ] as $p) {
            Permission::findOrCreate($p, 'web');
        }
    }

    #[Test]
    public function a_mechanic_without_a_licence_can_finish_his_own_card(): void
    {
        // The point of the split. Demanding a licence here would mean the
        // licence holder signs for work he did not do.
        $card = $this->cardWithTime($this->mechanic());

        $done = app(CertifyTaskCard::class)->complete(
            $card, $this->mechanic(), 'Ölwechsel durchgeführt, Filter erneuert',
        );

        $this->assertSame(TaskCardState::Completed, $done->state);
        $this->assertTrue($done->awaitsCertification());
        $this->assertFalse($done->isCertified());
    }

    #[Test]
    public function but_he_cannot_sign_it_off(): void
    {
        $card = $this->cardWithTime($this->mechanic());
        app(CertifyTaskCard::class)->complete($card, $this->mechanic(), 'Gemacht');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/workorders\.cards\.certify/');

        app(CertifyTaskCard::class)->certify($card->fresh(), $this->mechanic());
    }

    #[Test]
    public function the_permission_alone_is_not_enough_either(): void
    {
        // Two refusals with two messages: lacking the permission is
        // administrative, lacking the licence is about the person.
        $card = $this->cardWithTime($this->mechanic());
        app(CertifyTaskCard::class)->complete($card, $this->mechanic(), 'Gemacht');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/qualified staff/');

        app(CertifyTaskCard::class)->certify(
            $card->fresh(), $this->userWith(Permissions::CARDS_CERTIFY),
        );
    }

    #[Test]
    public function somebody_qualified_signs_it_and_the_credential_is_frozen(): void
    {
        $card = $this->cardWithTime($this->mechanic());
        app(CertifyTaskCard::class)->complete($card, $this->mechanic(), 'Gemacht');

        $inspector = $this->qualifiedInspector();
        $certified = app(CertifyTaskCard::class)->certify($card->fresh(), $inspector);

        $this->assertSame(TaskCardState::Certified, $certified->state);
        $this->assertSame($inspector->name, $certified->certified_by_name);
        $this->assertSame('DE.66.12345', $certified->qualification_reference);
        $this->assertSame('B1', $certified->qualification_category);

        // And the first signature is still there, on its own.
        $this->assertNotNull($certified->completed_by_name);
        $this->assertNotSame($certified->completed_by_name, $certified->certified_by_name);
    }

    #[Test]
    public function an_unfinished_card_cannot_be_signed_off(): void
    {
        // Somebody has to do the work before anybody can say it was done well.
        $card = $this->cardWithTime($this->mechanic());

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/not been completed/');

        app(CertifyTaskCard::class)->certify($card, $this->qualifiedInspector());
    }

    #[Test]
    public function a_card_without_hours_cannot_be_completed(): void
    {
        // Not bureaucracy: the experience log is derived from these entries, so
        // a card with none never happened as far as anybody's licence goes.
        $order = $this->workOrder();
        $card = app(ManageWorkOrder::class)->addCard($order, 'Ölwechsel');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/experience log/');

        app(CertifyTaskCard::class)->complete($card, $this->mechanic(), 'Gemacht');
    }

    #[Test]
    public function what_was_actually_done_has_to_be_written_down(): void
    {
        // The instruction says what was asked for. It does not say what happened.
        $card = $this->cardWithTime($this->mechanic());

        $this->expectException(InvalidArgumentException::class);

        app(CertifyTaskCard::class)->complete($card, $this->mechanic(), '   ');
    }

    #[Test]
    public function hours_are_kept_per_person_and_per_kind_of_participation(): void
    {
        // 66.A.20(b) counts what somebody did. Two people on one card is two
        // logbook entries, and assisting is a third kind again.
        $order = $this->workOrder();
        $card = app(ManageWorkOrder::class)->addCard($order, 'Jahresnachprüfung Rumpf');

        $mechanic = $this->mechanic();
        $helper = $this->userWith(Permissions::CARDS_WORK);

        $action = app(ManageWorkOrder::class);
        $action->recordTime($card, $mechanic, 105, ParticipationKind::Executed);
        $action->recordTime($card, $helper, 60, ParticipationKind::Assisted);

        $card = $card->fresh();

        $this->assertSame(165, $card->totalMinutes());
        $this->assertSame(105, $card->minutesFor($mechanic));
        $this->assertSame(60, $card->minutesFor($helper, ParticipationKind::Assisted));
        $this->assertSame(0, $card->minutesFor($helper, ParticipationKind::Executed));
    }

    #[Test]
    public function hours_cannot_be_added_after_it_was_signed_off(): void
    {
        // It would change what somebody put their name to.
        $card = $this->cardWithTime($this->mechanic());
        app(CertifyTaskCard::class)->complete($card, $this->mechanic(), 'Gemacht');
        app(CertifyTaskCard::class)->certify($card->fresh(), $this->qualifiedInspector());

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/signed off/');

        app(ManageWorkOrder::class)->recordTime(
            $card->fresh(), $this->mechanic(), 30, ParticipationKind::Executed,
        );
    }

    #[Test]
    public function the_registration_is_copied_so_the_logbook_outlives_the_aircraft(): void
    {
        // An entry records what somebody worked on that day, and that does not
        // change because the aircraft was sold or deleted.
        $order = $this->workOrder();
        $card = app(ManageWorkOrder::class)->addCard($order, 'Ölwechsel');

        $this->assertSame($order->aircraft->registration, $card->aircraft_registration);
        $this->assertSame($order->aircraft->model, $card->aircraft_model);

        $order->aircraft->delete();

        $this->assertSame('D-KABC', $card->fresh()->aircraft_registration);
    }

    #[Test]
    public function signing_a_card_discharges_the_fleet_limit_it_was_raised_against(): void
    {
        // the rule for how the two modules meet: "eine anstehende aufgabe
        // bekommt eine arbeitskarte, wenn diese abgezeichnet ist, ist auch die
        // aufgabe erledigt."
        $order = $this->workOrder();

        $installation = Installation::create([
            'aircraft_id' => $order->aircraft_id,
            'part_name' => 'Tost Schleppkupplung',
            'installed_at' => now()->subMonths(13)->toDateString(),
        ]);

        $limit = ComponentLimit::create([
            'installation_id' => $installation->id,
            'kind' => LimitKind::CalendarMonths,
            'value' => 12,
            'tolerance_absolute' => 2,
        ]);

        $this->assertTrue($limit->isOverdue(), 'A month past due, inside tolerance.');

        $card = app(ManageWorkOrder::class)->addCard(
            $order, 'Schleppkupplung überholen', forLimit: $limit,
        );
        app(ManageWorkOrder::class)->recordTime($card, $this->mechanic(), 120, ParticipationKind::Executed);
        app(CertifyTaskCard::class)->complete($card, $this->mechanic(), 'Überholt');
        app(CertifyTaskCard::class)->certify($card->fresh(), $this->qualifiedInspector());

        $limit = $limit->fresh();

        $this->assertFalse($limit->isOverdue(), 'The limit moved on.');

        // And it moved on the fleet's way: anchored to the OLD due date, so the
        // overrun is spent rather than banked. Restating that rule here would
        // have restated it wrongly within a year.
        $this->assertSame(
            $installation->installed_at->copy()->addMonths(12)->toDateString(),
            $limit->anchorDate()->toDateString(),
        );
    }

    #[Test]
    public function a_pilot_owner_may_sign_for_work_they_did_themselves(): void
    {
        // This test used to hand the card to a mechanic and let the owner sign
        // it, which the rule forbids -- see PilotOwnerLimitTest, where the limit
        // is examined properly. Here it only has to hold that the ordinary case
        // still works: the owner did it, so the owner may sign it.
        $order = $this->workOrder();
        $owner = $this->userWith(Permissions::CARDS_WORK, Permissions::CARDS_CERTIFY);
        app(ListInMaintenanceProgramme::class)->add($order->aircraft, $owner);

        $card = $this->cardWithTime($owner->fresh(), $order);
        app(CertifyTaskCard::class)->complete($card, $owner->fresh(), 'Selbst gemacht');

        $certified = app(CertifyTaskCard::class)->certify($card->fresh(), $owner->fresh());

        $this->assertSame(Qualification::TYPE_PILOT_OWNER, $certified->qualification_type);
    }

    #[Test]
    public function a_visit_cannot_be_closed_over_an_unchecked_card(): void
    {
        // The one thing the second signature exists to surface would be the one
        // thing closing buries.
        $order = $this->workOrder();
        $card = $this->cardWithTime($this->mechanic(), $order);
        app(CertifyTaskCard::class)->complete($card, $this->mechanic(), 'Gemacht');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/not signed off yet/');

        app(ManageWorkOrder::class)->close($order->fresh(), $this->qualifiedInspector());
    }

    #[Test]
    public function completing_a_card_can_record_the_time_in_one_go(): void
    {
        // Feldtest: "Der prozess erst zeit eintragen dann fertig melden nervt."
        $order = $this->workOrder();
        $card = app(ManageWorkOrder::class)->addCard($order, 'Ölwechsel');
        $mechaniker = $this->mechanic();

        $fertig = app(CertifyTaskCard::class)->complete(
            card: $card,
            user: $mechaniker,
            workPerformed: 'Öl gewechselt',
            minutes: 90,
        );

        $this->assertSame(TaskCardState::Completed, $fertig->state);
        $this->assertSame(90, (int) $fertig->times()->sum('minutes'));
    }

    #[Test]
    public function the_release_co_certifies_cards_that_are_only_reported_finished(): void
    {
        /*
         * Feldtest: "Eine Arbeitskarte die zum Zeitpunkt der Freigabe noch
         * nicht abgezeichnet ist sollte durch die freigabe mit abgezeichnet
         * werden ... Die Arbeitskarten müssen dennoch vorher alle
         * fertiggemeldet sein."
         */
        $order = $this->workOrder();
        $card = app(ManageWorkOrder::class)->addCard($order, 'Ölwechsel');

        app(CertifyTaskCard::class)->complete(
            card: $card, user: $this->mechanic(), workPerformed: 'Gemacht', minutes: 60,
        );

        // Fertiggemeldet genügt für die Freigabe -- und die Warnung weiß, welche.
        $this->assertTrue($order->fresh()->isReadyForRelease());
        $this->assertCount(1, $order->fresh()->cardsAwaitingCertification());

        app(IssueRelease::class)->handle($order->fresh(), $this->qualifiedInspector());

        $this->assertSame(TaskCardState::Certified, $card->fresh()->state);
        $this->assertSame(
            $this->qualifiedInspector()->name,
            $card->fresh()->certified_by_name,
            'Wer freigibt, hat die Karte mitgezeichnet -- und steht auch dort.',
        );
    }

    #[Test]
    public function but_a_card_nobody_reported_finished_still_blocks(): void
    {
        // Ohne Fertigmeldung wüsste die Unterschrift nicht, worüber.
        $order = $this->workOrder();
        app(ManageWorkOrder::class)->addCard($order, 'Ölwechsel');

        $this->assertFalse($order->fresh()->isReadyForRelease());

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/reported finished/');

        app(IssueRelease::class)->handle($order->fresh(), $this->qualifiedInspector());
    }

    #[Test]
    public function certified_work_stays_open_until_its_release(): void
    {
        /*
         * Feldtest: "ein vorgang bei dem alle arbeitskarten abgezeichnet
         * sind, aber noch keine freigabe erteilt ist muss auch als offen
         * erscheinen." Der Handgriff schloss frueher genau hier -- und der
         * Vorgang sah fertig aus, waehrend die eine Unterschrift fehlte,
         * auf die es ankommt.
         */
        $order = $this->workOrder();
        $card = $this->cardWithTime($this->mechanic(), $order);
        app(CertifyTaskCard::class)->complete($card, $this->mechanic(), 'Gemacht');
        app(CertifyTaskCard::class)->certify($card->fresh(), $this->qualifiedInspector());

        try {
            app(ManageWorkOrder::class)->close($order->fresh(), $this->qualifiedInspector());
            $this->fail('Abgezeichnete Arbeit ohne Freigabe darf nicht von Hand schliessen.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('release', $e->getMessage());
        }

        // Offen heisst offen: der Vorgang steht weiter auf der Liste.
        $this->assertTrue(WorkOrder::open()->whereKey($order->id)->exists());

        // Die Freigabe beendet ihn -- und schliesst ihn dabei selbst.
        app(IssueRelease::class)->handle($order->fresh(), $this->qualifiedInspector());

        $abgeschlossen = $order->fresh();
        $this->assertSame(WorkOrder::STATE_CLOSED, $abgeschlossen->state);
        $this->assertNotNull($abgeschlossen->closed_at);
    }

    #[Test]
    public function a_visit_without_certified_work_still_closes_by_hand(): void
    {
        // Irrtuemlich eroeffnet oder nur storniert: dafuer bleibt der Weg.
        $order = $this->workOrder();

        $closed = app(ManageWorkOrder::class)->close($order->fresh(), $this->qualifiedInspector());

        $this->assertSame(WorkOrder::STATE_CLOSED, $closed->state);
    }

    #[Test]
    public function the_counters_are_kept_at_both_ends_of_the_visit(): void
    {
        // A card written six weeks later has to say what the aircraft had done
        // when the work began, not what it has done now.
        $aircraft = $this->aircraft();
        $this->reading($aircraft, 1200.0);

        $order = app(ManageWorkOrder::class)->open($aircraft->fresh(), 'Jahresnachprüfung', $this->mechanic());

        $this->assertSame(1200.0, (float) $order->counters_at_open['flight_hours']);

        $card = $this->cardWithTime($this->mechanic(), $order);
        app(CertifyTaskCard::class)->complete($card, $this->mechanic(), 'Gemacht');
        app(CertifyTaskCard::class)->certify($card->fresh(), $this->qualifiedInspector());

        $this->reading($aircraft->fresh(), 1240.0);

        // Abgezeichnete Arbeit endet mit der Freigabe -- die schreibt die
        // Schlusszahlen in derselben Transaktion.
        app(IssueRelease::class)->handle($order->fresh(), $this->qualifiedInspector());

        $this->assertSame(1240.0, (float) $order->fresh()->counters_at_close['flight_hours']);
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
            $this->aircraft(), 'Jahresnachprüfung 2026', $this->mechanic(),
        );
    }

    private function cardWithTime(User $person, ?WorkOrder $order = null): TaskCard
    {
        $card = app(ManageWorkOrder::class)->addCard($order ?? $this->workOrder(), 'Ölwechsel');

        app(ManageWorkOrder::class)->recordTime($card, $person, 90, ParticipationKind::Executed);

        return $card->fresh();
    }

    private function reading(Aircraft $aircraft, float $hours): void
    {
        CounterReading::create([
            'aircraft_id' => $aircraft->id,
            'kind' => CounterKind::FlightHours,
            'value' => $hours,
            'read_at' => now()->toDateString(),
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

    /*
     * Held per test, not static.
     *
     * A static cache survived RefreshDatabase and handed the second test a user
     * from the first one's rolled-back transaction -- which showed up as a
     * foreign key violation on work_orders and looked, for a moment, like a
     * schema problem.
     */
    private ?User $mechanic = null;

    private ?User $inspector = null;

    private function mechanic(): User
    {
        return $this->mechanic ??= $this->userWith(Permissions::CARDS_WORK);
    }

    private function qualifiedInspector(): User
    {
        if ($this->inspector !== null) {
            return $this->inspector->fresh();
        }

        $inspector = $this->userWith(Permissions::CARDS_CERTIFY, Permissions::WORK_ORDERS_MANAGE);

        Qualification::create([
            'user_id' => $inspector->id,
            'type' => Qualification::TYPE_PART66,
            'reference' => 'DE.66.12345',
            'category' => 'B1',
            'valid_from' => now()->subYear()->toDateString(),
        ]);

        return $this->inspector = $inspector->fresh();
    }
}
