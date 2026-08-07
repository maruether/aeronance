<?php

declare(strict_types=1);

namespace Tests\Feature\TaskCards;

use App\Core\Access\AccessSetup;
use App\Core\Models\Qualification;
use App\Core\Modules\ModuleManager;
use App\Models\User;
use App\Modules\Fleet\Airworthiness\AirworthinessCheck;
use App\Modules\Fleet\Models\Aircraft;
use App\Modules\Fleet\Models\AirworthinessReview;
use App\Modules\TaskCards\Actions\CertifyTaskCard;
use App\Modules\TaskCards\Actions\IssueRelease;
use App\Modules\TaskCards\Actions\ManageWorkOrder;
use App\Modules\TaskCards\Actions\RecordFinding;
use App\Modules\TaskCards\Airworthiness\OutstandingFindings;
use App\Modules\TaskCards\Enums\FindingState;
use App\Modules\TaskCards\Enums\ParticipationKind;
use App\Modules\TaskCards\Models\Finding;
use App\Modules\TaskCards\Models\WorkOrder;
use App\Modules\TaskCards\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

/**
 * Findings, and what becomes of them.
 *
 * Their own entity because that is what they are: you take out a screw and see a
 * crack. It is not part of the card you were doing, and it does not go away
 * because that card is finished.
 *
 * Deferring is the state that earns the design -- and the act with teeth.
 */
final class FindingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        app(AccessSetup::class)->run();
        app(ModuleManager::class)->enable('fleet');
        app(ModuleManager::class)->enable('taskcards');
        app(ModuleManager::class)->forgetCache();

        app(AirworthinessCheck::class)->register(OutstandingFindings::class);
    }

    #[Test]
    public function anybody_working_may_report_one(): void
    {
        // Discouraging this by demanding a licence would mean cracks get
        // mentioned in the tea room instead of the record.
        $finding = app(RecordFinding::class)->record(
            $this->aircraft(),
            'Riss im Holmgurt',
            'Etwa 20 mm, rechte Fläche, Wurzelrippe. Beim Ausbau des Bolzens gesehen.',
            $this->userWith(Permissions::FINDINGS_RECORD),
        );

        $this->assertSame(FindingState::Open, $finding->state);
        $this->assertTrue($finding->is_blocking);
        $this->assertNotNull($finding->found_by_name);
    }

    #[Test]
    public function a_description_is_required(): void
    {
        // "Riss" alone tells the next person nothing about where or how big.
        $this->expectException(InvalidArgumentException::class);

        app(RecordFinding::class)->record(
            $this->aircraft(), 'Riss', '   ', $this->userWith(Permissions::FINDINGS_RECORD),
        );
    }

    #[Test]
    public function an_outstanding_finding_shows_up_on_the_aircraft(): void
    {
        // The first use of the fleet's extension point by another module, and it
        // works as designed: the fleet learns nothing about findings.
        $aircraft = $this->aircraft();

        app(RecordFinding::class)->record(
            $aircraft, 'Riss im Holmgurt', 'Rechte Fläche', $this->userWith(Permissions::FINDINGS_RECORD),
        );

        $items = app(AirworthinessCheck::class)->openItemsFor($aircraft->fresh());

        $this->assertCount(1, $items);
        $this->assertSame('workorders', $items[0]->source);
        $this->assertTrue($items[0]->blocking);
    }

    #[Test]
    public function a_non_blocking_finding_is_a_warning_not_a_stopper(): void
    {
        // Whether a crack is cosmetic is a person's judgement. A system that
        // guessed would guess in one direction, and both are wrong.
        $aircraft = $this->aircraft();

        app(RecordFinding::class)->record(
            $aircraft, 'Lackabplatzer', 'Rumpfunterseite, kosmetisch',
            $this->userWith(Permissions::FINDINGS_RECORD),
            isBlocking: false,
        );

        $items = app(AirworthinessCheck::class)->openItemsFor($aircraft->fresh());

        $this->assertCount(1, $items);
        $this->assertFalse($items[0]->blocking);
    }

    #[Test]
    public function deferring_needs_a_qualification(): void
    {
        // Noticing is not deciding. "Holds until the next inspection" is a
        // judgement about airworthiness.
        $finding = $this->finding();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/qualified staff/');

        app(RecordFinding::class)->defer(
            $finding,
            $this->userWith(Permissions::FINDINGS_DEFER),
            'Hält bis zur nächsten Nachprüfung',
        );
    }

    #[Test]
    public function a_deferral_is_frozen_with_the_credential(): void
    {
        $finding = $this->finding();
        $inspector = $this->qualifiedInspector();

        $deferred = app(RecordFinding::class)->defer(
            $finding, $inspector, 'Kosmetisch, hält bis zur nächsten Nachprüfung',
            until: now()->addMonths(6)->toDateString(),
        );

        $this->assertSame(FindingState::Deferred, $deferred->state);
        $this->assertSame($inspector->name, $deferred->deferred_by_name);
        $this->assertSame('DE.66.12345', $deferred->deferral_qualification_reference);
    }

    #[Test]
    public function a_deferred_finding_stays_visible(): void
    {
        // The entire risk of a deferral is that it goes quiet.
        $aircraft = $this->aircraft();
        $finding = $this->finding($aircraft);

        app(RecordFinding::class)->defer(
            $finding, $this->qualifiedInspector(), 'Hält', until: now()->addMonths(6)->toDateString(),
        );

        $this->assertCount(1, app(AirworthinessCheck::class)->openItemsFor($aircraft->fresh()));
    }

    #[Test]
    public function a_lapsed_deferral_blocks_regardless_of_how_it_was_recorded(): void
    {
        // The permission to wait was granted until a date, and that date has
        // passed -- which is exactly when nobody is thinking about it.
        $aircraft = $this->aircraft();
        $finding = $this->finding($aircraft, blocking: false);

        app(RecordFinding::class)->defer(
            $finding, $this->qualifiedInspector(), 'Hält bis zur Nachprüfung',
            until: now()->subWeek()->toDateString(),
        );

        $items = app(AirworthinessCheck::class)->openItemsFor($aircraft->fresh());

        $this->assertTrue($items[0]->blocking, 'Not blocking when recorded, blocking now.');
        $this->assertStringContainsString('abgelaufen', $items[0]->detail);
    }

    #[Test]
    public function a_deferral_has_to_say_why(): void
    {
        // "Later" is not a reason somebody can be held to.
        $this->expectException(InvalidArgumentException::class);

        app(RecordFinding::class)->defer($this->finding(), $this->qualifiedInspector(), '  ');
    }

    #[Test]
    public function scheduling_it_raises_a_card_but_does_not_close_it(): void
    {
        // It closes when the card that fixes it is signed off -- the only moment
        // anybody can honestly say the thing is dealt with.
        $aircraft = $this->aircraft();
        $finding = $this->finding($aircraft);
        $order = $this->workOrder($aircraft);

        $card = app(RecordFinding::class)->schedule($finding, $order, $this->qualifiedInspector());

        $finding = $finding->fresh();

        $this->assertSame(FindingState::Scheduled, $finding->state);
        $this->assertTrue($finding->isOutstanding(), 'Still hanging over the aircraft.');
        $this->assertSame($card->id, $finding->resolving_task_card_id);
        $this->assertSame($finding->title, $card->title);
    }

    #[Test]
    public function resolving_it_takes_it_off_the_list(): void
    {
        $aircraft = $this->aircraft();
        $finding = $this->finding($aircraft);

        app(RecordFinding::class)->resolve(
            $finding, $this->qualifiedInspector(), 'Holmgurt nach Reparaturanweisung instandgesetzt',
        );

        $this->assertSame([], app(AirworthinessCheck::class)->openItemsFor($aircraft->fresh()));
    }

    #[Test]
    public function dismissing_is_not_the_same_as_resolving(): void
    {
        // Nothing was done, and a record saying otherwise would be wrong in a
        // way somebody could rely on.
        $finding = $this->finding();

        $dismissed = app(RecordFinding::class)->dismiss(
            $finding, $this->qualifiedInspector(), 'Kein Riss, Lackfehler im Streiflicht',
        );

        $this->assertSame(FindingState::Dismissed, $dismissed->state);
        $this->assertNotSame(FindingState::Resolved, $dismissed->state);
        $this->assertFalse($dismissed->isOutstanding());
    }

    #[Test]
    public function a_card_finished_but_unchecked_shows_up_too(): void
    {
        // The gap the two signatures exist to make visible.
        $aircraft = $this->aircraft();
        $order = $this->workOrder($aircraft);

        $card = app(ManageWorkOrder::class)->addCard($order, 'Ölwechsel');
        $mechanic = $this->userWith(Permissions::CARDS_WORK);
        app(ManageWorkOrder::class)->recordTime($card, $mechanic, 60, ParticipationKind::Executed);
        app(CertifyTaskCard::class)->complete($card, $mechanic, 'Gemacht');

        $items = app(AirworthinessCheck::class)->openItemsFor($aircraft->fresh());

        $this->assertCount(1, $items);
        $this->assertStringContainsString('nicht abgezeichnet', $items[0]->detail);
    }

    #[Test]
    public function signing_the_card_off_replaces_one_open_item_with_the_next(): void
    {
        // This test used to assert the list went empty here, and it was reading
        // "no unchecked card" as "nothing outstanding". Once the release exists,
        // a visit with every card signed and no CRS is the state that looks most
        // finished from outside -- hangar, ticks everywhere, and nothing saying
        // the aircraft may fly.
        $aircraft = $this->aircraft();
        $order = $this->workOrder($aircraft);

        $card = app(ManageWorkOrder::class)->addCard($order, 'Ölwechsel');
        $mechanic = $this->userWith(Permissions::CARDS_WORK);
        app(ManageWorkOrder::class)->recordTime($card, $mechanic, 60, ParticipationKind::Executed);
        app(CertifyTaskCard::class)->complete($card, $mechanic, 'Gemacht');

        $before = app(AirworthinessCheck::class)->openItemsFor($aircraft->fresh());
        $this->assertStringContainsString('nicht abgezeichnet', $before[0]->detail);

        app(CertifyTaskCard::class)->certify($card->fresh(), $this->qualifiedInspector());

        $after = app(AirworthinessCheck::class)->openItemsFor($aircraft->fresh());

        $this->assertCount(1, $after, 'The card item goes, the release item arrives.');
        $this->assertStringContainsString('keine Freigabe', $after[0]->detail);
    }

    #[Test]
    public function and_the_release_clears_it(): void
    {
        $aircraft = $this->aircraft();
        $order = $this->workOrder($aircraft);

        $card = app(ManageWorkOrder::class)->addCard($order, 'Ölwechsel');
        $mechanic = $this->userWith(Permissions::CARDS_WORK);
        app(ManageWorkOrder::class)->recordTime($card, $mechanic, 60, ParticipationKind::Executed);
        app(CertifyTaskCard::class)->complete($card, $mechanic, 'Gemacht');

        $inspector = $this->qualifiedInspector();
        app(CertifyTaskCard::class)->certify($card->fresh(), $inspector);
        app(IssueRelease::class)->handle($order->fresh(), $inspector);

        $this->assertSame([], app(AirworthinessCheck::class)->openItemsFor($aircraft->fresh()));
    }

    private function aircraft(): Aircraft
    {
        $aircraft = Aircraft::firstOrCreate(
            ['registration' => 'D-KABC'],
            ['model' => 'ASK 21'],
        );

        AirworthinessReview::firstOrCreate(
            ['aircraft_id' => $aircraft->id],
            [
                'issued_at' => now()->subMonths(2)->toDateString(),
                'valid_until' => now()->addMonths(10)->toDateString(),
            ],
        );

        return $aircraft->fresh();
    }

    private function workOrder(?Aircraft $aircraft = null): WorkOrder
    {
        return app(ManageWorkOrder::class)->open(
            $aircraft ?? $this->aircraft(),
            'Jahresnachprüfung',
            $this->userWith(Permissions::WORK_ORDERS_MANAGE),
        );
    }

    private function finding(?Aircraft $aircraft = null, bool $blocking = true): Finding
    {
        return app(RecordFinding::class)->record(
            $aircraft ?? $this->aircraft(),
            'Riss im Holmgurt',
            'Etwa 20 mm, rechte Fläche',
            $this->userWith(Permissions::FINDINGS_RECORD),
            isBlocking: $blocking,
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

    private function qualifiedInspector(): User
    {
        $user = $this->userWith(
            Permissions::FINDINGS_DEFER,
            Permissions::FINDINGS_RECORD,
            Permissions::CARDS_CERTIFY,
            Permissions::WORK_ORDERS_MANAGE,
        );

        Qualification::firstOrCreate([
            'user_id' => $user->id,
            'type' => Qualification::TYPE_PART66,
        ], [
            'reference' => 'DE.66.12345',
            'category' => 'B1',
            'valid_from' => now()->subYear()->toDateString(),
        ]);

        return $user->fresh();
    }
}
