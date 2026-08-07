<?php

declare(strict_types=1);

namespace Tests\Feature\TaskCards;

use App\Core\Models\Qualification;
use App\Core\Modules\ModuleManager;
use App\Models\User;
use App\Modules\Fleet\Actions\ListInMaintenanceProgramme;
use App\Modules\Fleet\Models\Aircraft;
use App\Modules\Part66\Support\ExperienceLog;
use App\Modules\TaskCards\Actions\CertifyTaskCard;
use App\Modules\TaskCards\Actions\IssuePartToCard;
use App\Modules\TaskCards\Actions\IssueRelease;
use App\Modules\TaskCards\Actions\ManageWorkOrder;
use App\Modules\TaskCards\Actions\RecordFinding;
use App\Modules\TaskCards\Enums\FindingState;
use App\Modules\TaskCards\Enums\ParticipationKind;
use App\Modules\TaskCards\Models\ReleaseToService;
use App\Modules\TaskCards\Models\TaskCard;
use App\Modules\TaskCards\Models\WorkOrder;
use App\Modules\TaskCards\Permissions;
use App\Modules\TaskCards\Support\OwnWorkOnly;
use App\Modules\Warehouse\Enums\PartClassification;
use App\Modules\Warehouse\Models\PartType;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * What the adversarial review found, nailed down.
 *
 * Every test here reproduces a hole the review claimed and proves it closed.
 * The review's own verification stage died on a session limit, so this file IS
 * the verification -- a claim that did not reproduce did not get a fix or a
 * test.
 */
final class FreezeHardeningTest extends TestCase
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

    // ── Deletion and reparenting ────────────────────────────────────────────

    #[Test]
    public function a_released_visit_cannot_be_deleted(): void
    {
        // The bypass: soft-delete the visit, its cards' guards resolve the
        // trashed parent to null, everything unfreezes, restore. Closed at the
        // first link -- the delete itself refuses.
        $order = $this->releasedVisit();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/certificate refers to it/');

        $order->delete();
    }

    #[Test]
    public function a_card_never_changes_visits(): void
    {
        // The reparenting bypass: the guard checked the NEW parent, so pointing
        // a frozen card at an open visit let it leave the released record.
        $released = $this->releasedVisit();
        $open = $this->openVisit('Zweiter Vorgang');

        $card = $released->taskCards->first();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/stays in the visit/');

        $card->update(['work_order_id' => $open->id]);
    }

    #[Test]
    public function hours_never_change_cards(): void
    {
        $released = $this->releasedVisit();
        $open = $this->openVisit('Zweiter Vorgang');
        $openCard = app(ManageWorkOrder::class)->addCard($open, 'Andere Arbeit');

        $time = $released->taskCards->first()->times->first();

        $this->expectException(RuntimeException::class);

        $time->update(['task_card_id' => $openCard->id]);
    }

    // ── The release gate and findings ───────────────────────────────────────

    #[Test]
    public function a_scheduled_blocking_finding_still_blocks_the_release(): void
    {
        // Scheduling needs no qualification, so reading "scheduled" as "out of
        // the way" would let anyone clear the gate by clicking einplanen.
        $order = $this->finishedVisit();

        $finding = app(RecordFinding::class)->record(
            $order->aircraft, 'Riss im Holmgurt', 'Rechte Fläche', $this->mechanic(),
        );
        app(RecordFinding::class)->schedule($finding, $order, $this->inspector());

        // The scheduled card is open now, so the release refuses on cards; cancel
        // it to isolate the finding-gate -- which puts the finding back to open.
        $scheduledCard = TaskCard::query()
            ->where('work_order_id', $order->id)
            ->orderByDesc('id')
            ->first();
        app(CertifyTaskCard::class)->cancel($scheduledCard, $this->inspector(), 'Test');

        $this->assertSame(FindingState::Open, $finding->fresh()->state, 'Cancel returns it to open.');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/outstanding and not deferred/');

        app(IssueRelease::class)->handle($order->fresh(), $this->inspector());
    }

    #[Test]
    public function a_lapsed_deferral_blocks_the_release_again(): void
    {
        // The airworthiness check already said so; the release gate saying
        // otherwise was one module contradicting itself.
        $order = $this->finishedVisit();

        $finding = app(RecordFinding::class)->record(
            $order->aircraft, 'Lackriss', 'Beobachten', $this->mechanic(),
        );
        app(RecordFinding::class)->defer(
            $finding, $this->inspector(), 'Bis zur Nachprüfung',
            until: now()->subDay()->toDateString(),
        );

        $this->expectException(RuntimeException::class);

        app(IssueRelease::class)->handle($order->fresh(), $this->inspector());
    }

    #[Test]
    public function certifying_the_fixing_card_resolves_the_finding(): void
    {
        // Promised in three places, delivered in none -- the finding sat in
        // "scheduled" forever.
        $order = $this->openVisit();

        $finding = app(RecordFinding::class)->record(
            $order->aircraft, 'Riss im Holmgurt', 'Rechte Fläche', $this->mechanic(),
        );
        $card = app(RecordFinding::class)->schedule($finding, $order, $this->inspector());

        app(ManageWorkOrder::class)->recordTime($card, $this->mechanic(), 120, ParticipationKind::Executed);
        app(CertifyTaskCard::class)->complete($card->fresh(), $this->mechanic(), 'Geschäftet');
        app(CertifyTaskCard::class)->certify($card->fresh(), $this->inspector());

        $fresh = $finding->fresh();
        $this->assertSame(FindingState::Resolved, $fresh->state);
        $this->assertStringContainsString($card->number, $fresh->resolution);
    }

    #[Test]
    public function a_finding_cannot_be_scheduled_into_another_aircrafts_visit(): void
    {
        $other = Aircraft::create(['registration' => 'D-KXYZ', 'model' => 'DG 300']);
        $finding = app(RecordFinding::class)->record(
            $other, 'Riss', 'Fläche', $this->mechanic(),
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/false trace/');

        app(RecordFinding::class)->schedule($finding, $this->openVisit(), $this->inspector());
    }

    #[Test]
    public function resolving_and_dismissing_are_determinations(): void
    {
        // A blocking finding stands in the way of the release; resolve() without
        // a qualification let anyone clear that gate with a sentence.
        $finding = app(RecordFinding::class)->record(
            $this->aircraft(), 'Riss', 'Fläche', $this->mechanic(),
        );

        $unqualified = $this->userWith(Permissions::FINDINGS_DEFER);

        try {
            app(RecordFinding::class)->resolve($finding, $unqualified, 'Passt schon');
            $this->fail('Resolve without a qualification must refuse.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('qualified staff', $e->getMessage());
        }

        $this->expectException(RuntimeException::class);
        app(RecordFinding::class)->dismiss($finding->fresh(), $unqualified, 'Kein Befund');
    }

    // ── Lifecycle and races ─────────────────────────────────────────────────

    #[Test]
    public function the_release_closes_the_visit(): void
    {
        // Before: releasing an open visit deadlocked it -- close() runs
        // update(), the freeze refuses every update, state stayed "open" with a
        // button that always errored.
        $order = $this->finishedVisit();

        $this->assertSame(WorkOrder::STATE_OPEN, $order->state);

        app(IssueRelease::class)->handle($order, $this->inspector());

        $fresh = $order->fresh();
        $this->assertSame(WorkOrder::STATE_CLOSED, $fresh->state);
        $this->assertNotNull($fresh->closed_at);
        $this->assertNotNull($fresh->counters_at_close);
    }

    #[Test]
    public function a_correction_by_a_pilot_owner_over_foreign_work_is_refused(): void
    {
        // A correction is a new certificate over the SAME work. This path
        // skipped the own-work check -- an owner could have "corrected" a
        // Part-66 release of a mechanic's work and put their own name on it.
        $order = $this->finishedVisit();
        $release = app(IssueRelease::class)->handle($order, $this->inspector());

        $owner = $this->userWith(Permissions::CARDS_CERTIFY);
        app(ListInMaintenanceProgramme::class)->add($order->aircraft, $owner);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/carried out personally/');

        app(IssueRelease::class)->correct($release, $owner->fresh(), 'Tippfehler');
    }

    #[Test]
    public function a_release_cannot_be_corrected_twice_even_at_the_index(): void
    {
        // The unique index on supersedes_release_id is the backstop under the
        // action's lock -- proven here by writing past the action.
        $order = $this->finishedVisit();
        $release = app(IssueRelease::class)->handle($order, $this->inspector());
        app(IssueRelease::class)->correct($release, $this->inspector(), 'Erste Korrektur');

        $this->expectException(QueryException::class);

        ReleaseToService::query()->getQuery()->insert([
            'work_order_id' => $order->id,
            'aircraft_id' => $order->aircraft_id,
            'aircraft_registration' => 'D-KABC',
            'number' => 'CRS-2099-999',
            'statement' => 'x',
            'released_at' => now(),
            'released_by_name' => 'x',
            'qualification_type' => 'part66',
            'supersedes_release_id' => $release->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    #[Test]
    public function card_numbers_survive_deletion_without_duplicating(): void
    {
        // Count-based numbering handed out /02 twice once a card was deleted.
        $order = $this->openVisit();
        app(ManageWorkOrder::class)->addCard($order, 'Erste');
        $second = app(ManageWorkOrder::class)->addCard($order, 'Zweite');

        $second->delete();

        $third = app(ManageWorkOrder::class)->addCard($order, 'Dritte');

        $this->assertStringEndsWith('/03', $third->number, 'Not /02 again.');
    }

    // ── Parts and own work ──────────────────────────────────────────────────

    #[Test]
    public function no_parts_for_cancelled_cards_or_released_visits(): void
    {
        Qualification::query();  // autoload nudge
        app(ModuleManager::class)->enable('warehouse');
        app(ModuleManager::class)->forgetCache();
        Permission::findOrCreate(\App\Modules\Warehouse\Permissions::STOCK_ISSUE, 'web');

        $part = PartType::create([
            'name' => 'Ölfilter', 'classification' => PartClassification::Component,
            'unit_of_measure' => 'St',
        ]);

        $released = $this->releasedVisit();
        $frozenCard = $released->taskCards->first();

        try {
            app(IssuePartToCard::class)->handle(
                $frozenCard, $part, 1, $this->mechanic(),
            );
            $this->fail('Parts must not book onto a certified card.');
        } catch (RuntimeException $e) {
            // Certified card of a released visit -- either refusal is correct.
            $this->assertNotEmpty($e->getMessage());
        }

        $open = $this->openVisit('Zweiter');
        $cancelled = app(ManageWorkOrder::class)->addCard($open, 'Doch nicht');
        app(CertifyTaskCard::class)->cancel($cancelled, $this->mechanic(), 'Nicht nötig');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/nobody did/');

        app(IssuePartToCard::class)->handle(
            $cancelled->fresh(), $part, 1, $this->mechanic(),
        );
    }

    #[Test]
    public function an_assisted_row_is_not_own_work_even_when_it_is_the_owners_only_row(): void
    {
        // "Assisted" means somebody else did the executing, whether or not that
        // person recorded time. Checking only the user id read it as own work.
        $owner = $this->userWith(Permissions::CARDS_WORK);
        $order = $this->openVisit();
        $card = app(ManageWorkOrder::class)->addCard($order, 'Motorwechsel');

        app(ManageWorkOrder::class)->recordTime($card, $owner, 300, ParticipationKind::Assisted);

        $this->assertFalse(OwnWorkOnly::isEntirelyOwnWork($card->fresh(), $owner));
    }

    #[Test]
    public function a_superseded_release_still_counts_for_its_signer(): void
    {
        // An experience record, not a validity record: correcting the paperwork
        // later does not unmake the act of certification.
        $order = $this->finishedVisit();
        $release = app(IssueRelease::class)->handle($order, $this->inspector());
        app(IssueRelease::class)->correct($release, $this->inspector(), 'Tippfehler in der Unterlage');

        $count = app(ExperienceLog::class)
            ->releasesBy($this->inspector())
            ->count();

        $this->assertSame(2, $count, 'Original and correction are both acts of certification.');
    }

    // ── Helpers ─────────────────────────────────────────────────────────────

    private function aircraft(): Aircraft
    {
        return Aircraft::firstOrCreate(['registration' => 'D-KABC'], ['model' => 'ASK 21']);
    }

    private function openVisit(string $title = 'Jahresnachprüfung'): WorkOrder
    {
        return app(ManageWorkOrder::class)->open($this->aircraft(), $title, $this->inspector());
    }

    /** Every card certified, nothing released. */
    private function finishedVisit(): WorkOrder
    {
        $order = $this->openVisit();
        $card = app(ManageWorkOrder::class)->addCard($order, 'Ölwechsel');

        app(ManageWorkOrder::class)->recordTime($card, $this->mechanic(), 60, ParticipationKind::Executed);
        app(CertifyTaskCard::class)->complete($card->fresh(), $this->mechanic(), 'Gemacht');
        app(CertifyTaskCard::class)->certify($card->fresh(), $this->inspector());

        return $order->fresh();
    }

    private function releasedVisit(): WorkOrder
    {
        $order = $this->finishedVisit();
        app(IssueRelease::class)->handle($order, $this->inspector());

        return $order->fresh()->load('taskCards.times');
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

    private function userWith(string ...$permissions): User
    {
        $user = User::factory()->create(['is_active' => true]);

        foreach ($permissions as $permission) {
            $user->givePermissionTo($permission);
        }

        return $user->fresh();
    }
}
