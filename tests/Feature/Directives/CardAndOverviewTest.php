<?php

declare(strict_types=1);

namespace Tests\Feature\Directives;

use App\Core\Access\AccessSetup;
use App\Core\Models\Qualification;
use App\Core\Modules\ModuleManager;
use App\Models\User;
use App\Modules\Directives\Actions\AssessDirective;
use App\Modules\Directives\Actions\ScheduleDirectiveCard;
use App\Modules\Directives\Airworthiness\OutstandingDirectives;
use App\Modules\Directives\Enums\Bindingness;
use App\Modules\Directives\Enums\DirectiveKind;
use App\Modules\Directives\Enums\SubjectKind;
use App\Modules\Directives\Models\Directive;
use App\Modules\Directives\Permissions;
use App\Modules\Fleet\Airworthiness\AirworthinessCheck;
use App\Modules\Fleet\Models\Aircraft;
use App\Modules\TaskCards\Actions\CertifyTaskCard;
use App\Modules\TaskCards\Actions\IssueRelease;
use App\Modules\TaskCards\Actions\ManageWorkOrder;
use App\Modules\TaskCards\Enums\ActivityKind;
use App\Modules\TaskCards\Enums\ParticipationKind;
use App\Modules\TaskCards\Models\WorkOrder;
use App\Modules\TaskCards\Permissions as CardPermissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * The task card from a line, the printed overview, and the release gate.
 *
 * The last one is the cross-module consequence of the "nicht beurteilt ist
 * ne red flag und verhindert die freigabe" -- reached through the fleet's
 * airworthiness check, because task cards know nothing of directives.
 */
final class CardAndOverviewTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        app(AccessSetup::class)->run();

        foreach ([
            Permissions::DIRECTIVES_VIEW,
            Permissions::DIRECTIVES_ASSESS,
            CardPermissions::CARDS_WORK,
            CardPermissions::CARDS_CERTIFY,
            CardPermissions::WORK_ORDERS_MANAGE,
        ] as $p) {
            Permission::findOrCreate($p, 'web');
        }

        app(ModuleManager::class)->enable('directives');
        app(ModuleManager::class)->enable('taskcards');
        app(ModuleManager::class)->forgetCache();
        app(AirworthinessCheck::class)
            ->register(OutstandingDirectives::class);
    }

    // ── The card ────────────────────────────────────────────────────────────

    #[Test]
    public function a_card_is_raised_for_a_line_with_the_right_activity_kind(): void
    {
        // AdCompliance existed in the task cards module before this one did,
        // which is what makes the two fit without either knowing the other.
        $aircraft = $this->aircraft();
        $directive = $this->directive();
        $order = $this->workOrder($aircraft);

        $card = app(ScheduleDirectiveCard::class)->handle(
            $directive, $aircraft, $order, $this->inspector(),
        );

        $this->assertSame(ActivityKind::AdCompliance, $card->activity_kind);
        $this->assertStringContainsString($directive->number, $card->title);
    }

    #[Test]
    public function the_card_carries_the_deadline_and_the_source(): void
    {
        // The person at the aircraft should not have to go looking.
        $aircraft = $this->aircraft();
        $directive = $this->directive();
        $directive->update([
            'summary' => 'Beschlag auf Risse prüfen',
            'comply_before' => '2026-09-01',
            'reference_url' => 'https://example.test/lta',
        ]);

        $card = app(ScheduleDirectiveCard::class)->handle(
            $directive->fresh(), $aircraft, $this->workOrder($aircraft), $this->inspector(),
        );

        $this->assertStringContainsString('01.09.2026', $card->instruction);
        $this->assertStringContainsString('example.test', $card->instruction);
    }

    #[Test]
    public function raising_a_card_does_not_by_itself_comply(): void
    {
        // Deliberately not automatic: a compliance is a statement to an
        // authority, and somebody qualified says it explicitly.
        $aircraft = $this->aircraft();
        $directive = $this->directive();

        app(ScheduleDirectiveCard::class)->handle(
            $directive, $aircraft, $this->workOrder($aircraft), $this->inspector(),
        );

        $application = app(AssessDirective::class)->applicationFor($directive, $aircraft);

        $this->assertTrue($application->isOutstanding(), 'Raised is not done.');
    }

    #[Test]
    public function a_visit_of_another_aircraft_is_refused(): void
    {
        $aircraft = $this->aircraft();
        $other = Aircraft::create(['registration' => 'D-KXYZ', 'model' => 'ASK 21']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/false trace/');

        app(ScheduleDirectiveCard::class)->handle(
            $this->directive(), $aircraft, $this->workOrder($other), $this->inspector(),
        );
    }

    #[Test]
    public function a_superseded_line_points_at_its_successor_instead(): void
    {
        $aircraft = $this->aircraft();
        $old = $this->directive();
        $new = $this->directive(number: 'LTA-2026-006');
        $old->update(['superseded_by_id' => $new->id]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/raise the card for that one/');

        app(ScheduleDirectiveCard::class)->handle(
            $old->fresh(), $aircraft, $this->workOrder($aircraft), $this->inspector(),
        );
    }

    #[Test]
    public function without_task_cards_the_list_still_works(): void
    {
        app(ModuleManager::class)->disable('taskcards');
        app(ModuleManager::class)->forgetCache();

        $this->assertFalse(app(ScheduleDirectiveCard::class)->isAvailable());

        // And the assessment path is untouched.
        $application = app(AssessDirective::class)->comply(
            $this->directive(), $this->aircraft(), $this->inspector(), 'Von Hand erledigt',
        );

        $this->assertFalse($application->isOutstanding());
    }

    // ── The release gate ────────────────────────────────────────────────────

    #[Test]
    public function an_unassessed_line_stops_the_release(): void
    {
        // the rule, across a module boundary the task cards do not declare.
        $aircraft = $this->aircraft();
        $this->directive();
        $order = $this->finishedVisit($aircraft);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/signing over an unknown/');

        app(IssueRelease::class)->handle($order, $this->inspector());
    }

    #[Test]
    public function assessing_it_lets_the_release_through(): void
    {
        $aircraft = $this->aircraft();
        $directive = $this->directive();
        $order = $this->finishedVisit($aircraft);

        app(AssessDirective::class)->markNotApplicable(
            $directive, $aircraft, $this->inspector(), 'Ausrüstung nicht verbaut',
        );

        $release = app(IssueRelease::class)->handle($order->fresh(), $this->inspector());

        $this->assertNotNull($release->id);
    }

    #[Test]
    public function an_expired_arc_does_not_stop_a_release(): void
    {
        // The distinction the flag exists for: an aircraft may be unairworthy
        // while the maintenance done on it is perfectly releasable. A CRS
        // certifies work, not flightworthiness.
        $aircraft = $this->aircraft();
        $order = $this->finishedVisit($aircraft);

        // No ARC on file at all -- the fleet reports it, blocking, and it must
        // not gate the release.
        $items = app(AirworthinessCheck::class)
            ->openItemsFor($aircraft->fresh());
        $this->assertNotEmpty(array_filter($items, fn ($i): bool => $i->source === 'fleet'));

        $release = app(IssueRelease::class)->handle($order, $this->inspector());

        $this->assertNotNull($release->id);
    }

    #[Test]
    public function the_refusal_is_visible_before_the_button_is_pressed(): void
    {
        $aircraft = $this->aircraft();
        $this->directive();
        $order = $this->finishedVisit($aircraft);

        $refusal = app(IssueRelease::class)->refusalFor($order, $this->inspector());

        $this->assertNotNull($refusal);
        $this->assertStringContainsString('LTA-2026-005', $refusal);
    }

    // ── The printed overview ────────────────────────────────────────────────

    #[Test]
    public function the_sheet_lists_every_line_and_flags_the_unread_ones(): void
    {
        $aircraft = $this->aircraft();
        $this->directive();
        $optional = $this->directive(
            kind: DirectiveKind::Tm, number: 'TM-2026-77', bindingness: Bindingness::Optional,
        );

        app(AssessDirective::class)->comply($optional, $aircraft, $this->inspector(), 'Gemacht');

        $this->actingAs($this->inspector())
            ->get(route('directives.overview', ['aircraft' => $aircraft]))
            ->assertSuccessful()
            ->assertSee('Übersicht LTA / TM', false)
            ->assertSee('D-KABC')
            ->assertSee('LTA-2026-005')
            ->assertSee('TM-2026-77')
            ->assertSee('verhindert die Freigabe', false)
            ->assertSee('DE.66.12345');
    }

    #[Test]
    public function the_sheet_needs_the_view_permission_and_the_module(): void
    {
        $aircraft = $this->aircraft();

        $this->actingAs($this->userWith())
            ->get(route('directives.overview', ['aircraft' => $aircraft]))
            ->assertForbidden();

        app(ModuleManager::class)->disable('directives');
        app(ModuleManager::class)->forgetCache();

        $this->actingAs($this->inspector())
            ->get(route('directives.overview', ['aircraft' => $aircraft]))
            ->assertNotFound();
    }

    // ── Helpers ─────────────────────────────────────────────────────────────

    private function aircraft(): Aircraft
    {
        return Aircraft::firstOrCreate(['registration' => 'D-KABC'], ['model' => 'ASK 21']);
    }

    private function directive(
        DirectiveKind $kind = DirectiveKind::Lta,
        string $number = 'LTA-2026-005',
        ?Bindingness $bindingness = null,
    ): Directive {
        return Directive::create([
            'source' => 'manual',
            'number' => $number,
            'title' => 'Beschlag prüfen',
            'kind' => $kind,
            'bindingness' => $bindingness ?? ($kind->isMandatory()
                ? Bindingness::Mandatory
                : Bindingness::Optional),
            'subject_kind' => SubjectKind::AircraftModel,
            'subject_model' => 'ASK 21',
        ]);
    }

    private function workOrder(Aircraft $aircraft): WorkOrder
    {
        return app(ManageWorkOrder::class)->open($aircraft, 'Jahresnachprüfung', $this->inspector());
    }

    private function finishedVisit(Aircraft $aircraft): WorkOrder
    {
        $order = $this->workOrder($aircraft);
        $card = app(ManageWorkOrder::class)->addCard($order, 'Ölwechsel');

        app(ManageWorkOrder::class)->recordTime(
            $card, $this->inspector(), 60, ParticipationKind::Executed,
        );
        app(CertifyTaskCard::class)->complete($card->fresh(), $this->inspector(), 'Gemacht');
        app(CertifyTaskCard::class)->certify($card->fresh(), $this->inspector());

        return $order->fresh();
    }

    private ?User $inspectorUser = null;

    private function inspector(): User
    {
        if ($this->inspectorUser !== null) {
            return $this->inspectorUser->fresh();
        }

        $user = $this->userWith(
            Permissions::DIRECTIVES_VIEW,
            Permissions::DIRECTIVES_ASSESS,
            CardPermissions::CARDS_WORK,
            CardPermissions::CARDS_CERTIFY,
            CardPermissions::WORK_ORDERS_MANAGE,
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
