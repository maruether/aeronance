<?php

declare(strict_types=1);

namespace Tests\Feature\TaskCards;

use App\Core\Models\Qualification;
use App\Models\User;
use App\Modules\Fleet\Actions\ListInMaintenanceProgramme;
use App\Modules\Fleet\Enums\CounterKind;
use App\Modules\Fleet\Models\Aircraft;
use App\Modules\Fleet\Models\CounterReading;
use App\Modules\TaskCards\Actions\CertifyTaskCard;
use App\Modules\TaskCards\Actions\IssueRelease;
use App\Modules\TaskCards\Actions\ManageWorkOrder;
use App\Modules\TaskCards\Actions\RecordFinding;
use App\Modules\TaskCards\Enums\ParticipationKind;
use App\Modules\TaskCards\Models\ReleaseToService;
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
 * The certificate of release to service.
 *
 * The third signature, and the only one an operator acts on. "Fertig gemeldet"
 * says the work is finished, "abgezeichnet" says it was done properly, this says
 * the aircraft may fly.
 *
 * It also brings the leitplanke that has been waiting since the beginning:
 * "Vorgänge mit erteilter CRS sind eingefroren. Korrekturen nur als neue,
 * referenzierende Einträge."
 */
final class ReleaseTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        foreach ([
            Permissions::CARDS_WORK,
            Permissions::CARDS_CERTIFY,
            Permissions::WORK_ORDERS_MANAGE,
            Permissions::FINDINGS_RECORD,
            Permissions::FINDINGS_DEFER,
        ] as $p) {
            Permission::findOrCreate($p, 'web');
        }
    }

    #[Test]
    public function a_finished_visit_is_released_and_the_certificate_names_who_signed(): void
    {
        $order = $this->finishedVisit();
        $inspector = $this->inspector();

        $release = app(IssueRelease::class)->handle($order->fresh(), $inspector, 'AMP ASK 21, Ausgabe 4');

        $this->assertStringStartsWith('CRS-', $release->number);
        $this->assertSame($inspector->name, $release->released_by_name);
        $this->assertSame('DE.66.12345', $release->qualification_reference);
        $this->assertSame('AMP ASK 21, Ausgabe 4', $release->maintenance_data);
        $this->assertStringContainsString('D-KABC', $release->statement);
    }

    #[Test]
    public function the_visit_is_frozen_afterwards(): void
    {
        // The leitplanke, and it is enforced in the model rather than the screen:
        // a rule that lives in a form is one an import does not know about.
        $order = $this->finishedVisit();
        app(IssueRelease::class)->handle($order->fresh(), $this->inspector());

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/frozen/');

        $order->fresh()->update(['title' => 'Anders']);
    }

    #[Test]
    public function so_are_its_cards_and_their_hours(): void
    {
        // Freezing only the work order would have been decoration: the
        // certificate says what the cards say, and the hours are what somebody's
        // licence record is built from.
        $order = $this->finishedVisit();
        app(IssueRelease::class)->handle($order->fresh(), $this->inspector());

        $card = $order->fresh()->taskCards->first();

        try {
            $card->update(['title' => 'Anders']);
            $this->fail('A card of a released visit must not be editable.');
        } catch (RuntimeException) {
        }

        try {
            app(ManageWorkOrder::class)->recordTime(
                $card->fresh(), $this->mechanic(), 30, ParticipationKind::Executed,
            );
            $this->fail('Hours must not be added to a released visit.');
        } catch (RuntimeException) {
        }

        $this->expectException(RuntimeException::class);
        $card->fresh()->times->first()->update(['minutes' => 999]);
    }

    #[Test]
    public function a_visit_with_an_unchecked_card_cannot_be_released(): void
    {
        // It would certify the one thing nobody has checked.
        $order = $this->workOrder();
        $card = $this->cardWithTime($order);
        app(CertifyTaskCard::class)->complete($card, $this->mechanic(), 'Gemacht');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/not signed off yet/');

        app(IssueRelease::class)->handle($order->fresh(), $this->inspector());
    }

    #[Test]
    public function a_visit_with_no_cards_has_nothing_to_certify(): void
    {
        $order = $this->workOrder();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/nothing to release/');

        app(IssueRelease::class)->handle($order, $this->inspector());
    }

    #[Test]
    public function an_open_blocking_finding_prevents_it(): void
    {
        $order = $this->finishedVisit();

        app(RecordFinding::class)->record(
            $order->aircraft, 'Riss im Holmgurt', 'Rechte Fläche', $this->mechanic(),
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/outstanding and not deferred/');

        app(IssueRelease::class)->handle($order->fresh(), $this->inspector());
    }

    #[Test]
    public function but_a_deferred_one_does_not(): void
    {
        // Which is exactly what deferring is for: somebody qualified decided it
        // can wait, and answers for that. This is the place that distinction
        // earns its keep.
        $order = $this->finishedVisit();

        $finding = app(RecordFinding::class)->record(
            $order->aircraft, 'Lackabplatzer', 'Kosmetisch', $this->mechanic(),
        );

        app(RecordFinding::class)->defer(
            $finding, $this->inspector(), 'Hält bis zur nächsten Nachprüfung',
            until: now()->addMonths(6)->toDateString(),
        );

        $release = app(IssueRelease::class)->handle($order->fresh(), $this->inspector());

        $this->assertNotNull($release->id);
    }

    #[Test]
    public function nor_does_a_non_blocking_one(): void
    {
        $order = $this->finishedVisit();

        app(RecordFinding::class)->record(
            $order->aircraft, 'Lackabplatzer', 'Kosmetisch', $this->mechanic(), isBlocking: false,
        );

        $this->assertNotNull(app(IssueRelease::class)->handle($order->fresh(), $this->inspector())->id);
    }

    #[Test]
    public function a_pilot_owner_may_release_a_visit_they_did_alone(): void
    {
        $aircraft = $this->aircraft();
        $owner = $this->pilotOwner($aircraft);

        $order = $this->workOrder($aircraft);
        $card = $this->cardWithTime($order, $owner);
        app(CertifyTaskCard::class)->complete($card, $owner, 'Selbst gemacht');
        app(CertifyTaskCard::class)->certify($card->fresh(), $owner->fresh());

        $release = app(IssueRelease::class)->handle($order->fresh(), $owner->fresh());

        $this->assertSame(Qualification::TYPE_PILOT_OWNER, $release->qualification_type);
    }

    #[Test]
    public function but_not_one_where_a_single_card_was_somebody_elses(): void
    {
        // A release covers the whole visit, so card-by-card would not be enough:
        // one card done by a mechanic puts the entire certificate outside a
        // pilot-owner's authorisation, even if they did the other nine.
        $aircraft = $this->aircraft();
        $owner = $this->pilotOwner($aircraft);
        $mechanic = $this->mechanic();

        $order = $this->workOrder($aircraft);

        $mine = $this->cardWithTime($order, $owner);
        app(CertifyTaskCard::class)->complete($mine, $owner, 'Selbst gemacht');
        app(CertifyTaskCard::class)->certify($mine->fresh(), $owner->fresh());

        $theirs = $this->cardWithTime($order, $mechanic);
        app(CertifyTaskCard::class)->complete($theirs, $mechanic, 'Gemacht');
        app(CertifyTaskCard::class)->certify($theirs->fresh(), $this->inspector());

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/whole visit/');

        app(IssueRelease::class)->handle($order->fresh(), $owner->fresh());
    }

    #[Test]
    public function a_cancelled_card_does_not_count_against_a_pilot_owner(): void
    {
        // Nobody did that work, so there is no foreign work in it.
        $aircraft = $this->aircraft();
        $owner = $this->pilotOwner($aircraft);

        $order = $this->workOrder($aircraft);

        $mine = $this->cardWithTime($order, $owner);
        app(CertifyTaskCard::class)->complete($mine, $owner, 'Selbst gemacht');
        app(CertifyTaskCard::class)->certify($mine->fresh(), $owner->fresh());

        $dropped = app(ManageWorkOrder::class)->addCard($order, 'Doch nicht nötig');
        app(CertifyTaskCard::class)->cancel($dropped, $owner->fresh(), 'Nicht erforderlich');

        $release = app(IssueRelease::class)->handle($order->fresh(), $owner->fresh());

        $this->assertSame(Qualification::TYPE_PILOT_OWNER, $release->qualification_type);
    }

    #[Test]
    public function it_cannot_be_released_twice(): void
    {
        $order = $this->finishedVisit();
        app(IssueRelease::class)->handle($order->fresh(), $this->inspector());

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/already been released/');

        app(IssueRelease::class)->handle($order->fresh(), $this->inspector());
    }

    #[Test]
    public function the_certificate_itself_cannot_be_edited_or_deleted(): void
    {
        // A certificate whose text can change afterwards is not a certificate.
        $order = $this->finishedVisit();
        $release = app(IssueRelease::class)->handle($order->fresh(), $this->inspector());

        try {
            $release->update(['statement' => 'Etwas anderes']);
            $this->fail('A release must not be editable.');
        } catch (RuntimeException) {
        }

        $this->expectException(RuntimeException::class);
        $release->fresh()->delete();
    }

    #[Test]
    public function a_correction_is_a_new_release_pointing_at_the_old_one(): void
    {
        // "Korrekturen nur als neue, referenzierende Einträge — nie durch
        // Editieren des Originals." Somebody signed those words, and they stay
        // signed.
        $order = $this->finishedVisit();
        $original = app(IssueRelease::class)->handle($order->fresh(), $this->inspector());

        $correction = app(IssueRelease::class)->correct(
            $original, $this->inspector(), 'Falsche Instandhaltungsunterlage angegeben',
        );

        $this->assertSame($original->id, $correction->supersedes_release_id);
        $this->assertTrue($correction->isCorrection());
        $this->assertTrue($original->fresh()->isSuperseded());

        // The original keeps its text.
        $this->assertStringContainsString('D-KABC', $original->fresh()->statement);

        // And only the correction stands.
        $this->assertSame($correction->id, $order->fresh()->currentRelease()->id);
        $this->assertSame(1, ReleaseToService::current()->count());
    }

    #[Test]
    public function a_correction_has_to_say_what_was_wrong(): void
    {
        $order = $this->finishedVisit();
        $original = app(IssueRelease::class)->handle($order->fresh(), $this->inspector());

        $this->expectException(InvalidArgumentException::class);

        app(IssueRelease::class)->correct($original, $this->inspector(), '   ');
    }

    #[Test]
    public function a_release_cannot_be_corrected_twice(): void
    {
        $order = $this->finishedVisit();
        $original = app(IssueRelease::class)->handle($order->fresh(), $this->inspector());

        app(IssueRelease::class)->correct($original, $this->inspector(), 'Erste Korrektur');

        $this->expectException(RuntimeException::class);
        app(IssueRelease::class)->correct($original->fresh(), $this->inspector(), 'Noch eine');
    }

    #[Test]
    public function without_a_licence_nobody_releases_anything(): void
    {
        $order = $this->finishedVisit();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/qualified staff/');

        app(IssueRelease::class)->handle(
            $order->fresh(), $this->userWith(Permissions::CARDS_CERTIFY),
        );
    }

    #[Test]
    public function the_counters_at_release_are_kept(): void
    {
        // A release that cannot say when it applied is one nobody can place on a
        // timeline afterwards.
        $aircraft = $this->aircraft();
        CounterReading::create([
            'aircraft_id' => $aircraft->id,
            'kind' => CounterKind::FlightHours,
            'value' => 1240.5,
            'read_at' => now()->toDateString(),
        ]);

        $order = $this->finishedVisit($aircraft->fresh());
        $release = app(IssueRelease::class)->handle($order->fresh(), $this->inspector());

        $this->assertSame(1240.5, (float) $release->counters_at_release['flight_hours']);
    }

    #[Test]
    public function the_reason_can_be_asked_for_before_the_button_is_offered(): void
    {
        // So a button that cannot succeed is not shown, and the reason can be
        // said instead of a refusal after the click.
        $order = $this->workOrder();
        $card = $this->cardWithTime($order);
        app(CertifyTaskCard::class)->complete($card, $this->mechanic(), 'Gemacht');

        $refusal = app(IssueRelease::class)->refusalFor($order->fresh(), $this->inspector());

        $this->assertNotNull($refusal);
        $this->assertStringContainsString('not signed off', $refusal);

        app(CertifyTaskCard::class)->certify($card->fresh(), $this->inspector());

        $this->assertNull(app(IssueRelease::class)->refusalFor($order->fresh(), $this->inspector()));
    }

    private function aircraft(): Aircraft
    {
        return Aircraft::firstOrCreate(
            ['registration' => 'D-KABC'],
            ['model' => 'ASK 21'],
        );
    }

    private function workOrder(?Aircraft $aircraft = null): WorkOrder
    {
        return app(ManageWorkOrder::class)->open(
            $aircraft ?? $this->aircraft(), 'Jahresnachprüfung', $this->inspector(),
        );
    }

    private function cardWithTime(?WorkOrder $order = null, ?User $person = null): TaskCard
    {
        $card = app(ManageWorkOrder::class)->addCard($order ?? $this->workOrder(), 'Ölwechsel');

        app(ManageWorkOrder::class)->recordTime(
            $card, $person ?? $this->mechanic(), 60, ParticipationKind::Executed,
        );

        return $card->fresh();
    }

    /** A visit with one card, done and signed off. */
    private function finishedVisit(?Aircraft $aircraft = null): WorkOrder
    {
        $order = $this->workOrder($aircraft);
        $card = $this->cardWithTime($order);

        app(CertifyTaskCard::class)->complete($card, $this->mechanic(), 'Gemacht');
        app(CertifyTaskCard::class)->certify($card->fresh(), $this->inspector());

        return $order->fresh();
    }

    private ?User $mechanicUser = null;

    private ?User $inspectorUser = null;

    private function mechanic(): User
    {
        return $this->mechanicUser ??= $this->userWith(
            Permissions::CARDS_WORK, Permissions::FINDINGS_RECORD,
        );
    }

    private function inspector(): User
    {
        if ($this->inspectorUser !== null) {
            return $this->inspectorUser->fresh();
        }

        $user = $this->userWith(
            Permissions::CARDS_WORK,
            Permissions::CARDS_CERTIFY,
            Permissions::WORK_ORDERS_MANAGE,
            Permissions::FINDINGS_RECORD,
            Permissions::FINDINGS_DEFER,
        );

        Qualification::create([
            'user_id' => $user->id,
            'type' => Qualification::TYPE_PART66,
            'reference' => 'DE.66.12345',
            'category' => 'B1',
            'valid_from' => now()->subYear()->toDateString(),
        ]);

        return $this->inspectorUser = $user->fresh();
    }

    private function pilotOwner(Aircraft $aircraft): User
    {
        $user = $this->userWith(
            Permissions::CARDS_WORK,
            Permissions::CARDS_CERTIFY,
            Permissions::WORK_ORDERS_MANAGE,
        );

        app(ListInMaintenanceProgramme::class)->add($aircraft, $user);

        return $user->fresh();
    }

    private function userWith(string ...$permissions): User
    {
        $user = User::factory()->create(['is_active' => true]);

        foreach ($permissions as $permission) {
            $user->givePermissionTo($permission);
        }

        return $user->fresh();
    }
}
