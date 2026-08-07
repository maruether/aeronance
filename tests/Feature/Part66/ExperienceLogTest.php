<?php

declare(strict_types=1);

namespace Tests\Feature\Part66;

use App\Core\Models\Qualification;
use App\Models\User;
use App\Modules\Fleet\Models\Aircraft;
use App\Modules\Part66\Support\ExperienceLog;
use App\Modules\TaskCards\Actions\CertifyTaskCard;
use App\Modules\TaskCards\Actions\IssueRelease;
use App\Modules\TaskCards\Actions\ManageWorkOrder;
use App\Modules\TaskCards\Enums\ActivityKind;
use App\Modules\TaskCards\Enums\ParticipationKind;
use App\Modules\TaskCards\Models\TaskCardTime;
use App\Modules\TaskCards\Models\WorkOrder;
use App\Modules\TaskCards\Permissions as CardPermissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * The experience log -- the original request, finally answered.
 *
 * Vorgabe: "ich habe ein halbfertiges lagertool und will einen besseren weg mein
 * part 66 log zu führen."
 *
 * It adds no tables and writes nothing. All the work was done earlier, by putting
 * the Part-66 fields on the very first card rather than adding them once there
 * was a year of cards without them.
 */
final class ExperienceLogTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        foreach ([
            CardPermissions::CARDS_WORK,
            CardPermissions::CARDS_CERTIFY,
            CardPermissions::WORK_ORDERS_MANAGE,
        ] as $p) {
            Permission::findOrCreate($p, 'web');
        }
    }

    #[Test]
    public function it_carries_every_part_66_field_from_the_card(): void
    {
        // The fields CLAUDE.md named for the first card, which is exactly why
        // they were put there: date, registration, model, ATA, kind of work,
        // duration, executed/assisted, certifying person.
        $mechanic = $this->mechanic();
        $order = $this->workOrder();

        $card = app(ManageWorkOrder::class)->addCard(
            $order, 'Jahresnachprüfung Rumpf', null, ActivityKind::Inspection, '53',
        );
        app(ManageWorkOrder::class)->recordTime(
            $card, $mechanic, 105, ParticipationKind::Executed, '2026-03-14',
        );
        app(CertifyTaskCard::class)->complete($card->fresh(), $mechanic, 'Rumpf geprüft, o. B.');
        app(CertifyTaskCard::class)->certify($card->fresh(), $this->inspector());

        $entry = app(ExperienceLog::class)->for($mechanic)->sole();

        $this->assertSame('2026-03-14', $entry->date->toDateString());
        $this->assertSame('D-KABC', $entry->registration);
        $this->assertSame('ASK 21', $entry->model);
        $this->assertSame('53', $entry->ataChapter);
        $this->assertSame(ActivityKind::Inspection, $entry->activity);
        $this->assertSame('1:45', $entry->duration());
        $this->assertSame(ParticipationKind::Executed, $entry->participation);
        $this->assertSame('Rumpf geprüft, o. B.', $entry->workPerformed);
        $this->assertSame($this->inspector()->name, $entry->certifiedByName);
    }

    #[Test]
    public function two_people_on_one_card_get_two_different_entries(): void
    {
        // 66.A.20(b) counts what somebody did, so a shared card is two logbook
        // lines -- and assisting is a different line again.
        $mechanic = $this->mechanic();
        $helper = $this->userWith(CardPermissions::CARDS_WORK);

        $card = app(ManageWorkOrder::class)->addCard($this->workOrder(), 'Flächenmontage');
        app(ManageWorkOrder::class)->recordTime($card, $mechanic, 120, ParticipationKind::Executed);
        app(ManageWorkOrder::class)->recordTime($card, $helper, 60, ParticipationKind::Assisted);

        $mine = app(ExperienceLog::class)->for($mechanic);
        $theirs = app(ExperienceLog::class)->for($helper);

        $this->assertCount(1, $mine);
        $this->assertCount(1, $theirs);
        $this->assertSame(2.0, $mine->first()->hours());
        $this->assertSame(ParticipationKind::Assisted, $theirs->first()->participation);
    }

    #[Test]
    public function work_in_an_open_visit_is_marked_provisional(): void
    {
        // The honest half of a derived log: unreleased cards can still change, so
        // their figures are not settled yet.
        $mechanic = $this->mechanic();
        $card = app(ManageWorkOrder::class)->addCard($this->workOrder(), 'Ölwechsel');
        app(ManageWorkOrder::class)->recordTime($card, $mechanic, 60, ParticipationKind::Executed);

        $this->assertTrue(app(ExperienceLog::class)->for($mechanic)->first()->provisional);
    }

    #[Test]
    public function the_release_settles_it_and_names_the_certificate(): void
    {
        // This is what makes a derived log trustworthy at all: released work is
        // frozen, so its log lines are as fixed as the cards behind them. Without
        // the freeze a derived log would quietly rewrite itself.
        $mechanic = $this->mechanic();
        $order = $this->workOrder();

        $card = app(ManageWorkOrder::class)->addCard($order, 'Ölwechsel');
        app(ManageWorkOrder::class)->recordTime($card, $mechanic, 60, ParticipationKind::Executed);
        app(CertifyTaskCard::class)->complete($card->fresh(), $mechanic, 'Gemacht');
        app(CertifyTaskCard::class)->certify($card->fresh(), $this->inspector());

        $release = app(IssueRelease::class)->handle($order->fresh(), $this->inspector());

        $entry = app(ExperienceLog::class)->for($mechanic)->sole();

        $this->assertFalse($entry->provisional);
        $this->assertSame($release->number, $entry->releaseNumber);
    }

    #[Test]
    public function hours_split_by_activity_model_and_participation(): void
    {
        // The splits a licence assessment looks at. "300 hours of maintenance"
        // says far less than the division between inspection, repair and
        // modification.
        $mechanic = $this->mechanic();
        $order = $this->workOrder();

        $a = app(ManageWorkOrder::class)->addCard($order, 'Prüfung', null, ActivityKind::Inspection);
        $b = app(ManageWorkOrder::class)->addCard($order, 'Reparatur', null, ActivityKind::Repair);

        app(ManageWorkOrder::class)->recordTime($a, $mechanic, 120, ParticipationKind::Executed);
        app(ManageWorkOrder::class)->recordTime($b, $mechanic, 30, ParticipationKind::Assisted);

        $log = app(ExperienceLog::class);
        $entries = $log->for($mechanic);

        $this->assertSame(['inspection' => 2.0, 'repair' => 0.5], $log->hoursByActivity($entries));
        $this->assertSame(['ASK 21' => 2.5], $log->hoursByModel($entries));
        $this->assertSame(
            ['executed' => 2.0, 'assisted' => 0.5],
            $log->hoursByParticipation($entries),
        );
    }

    #[Test]
    public function certifying_somebody_elses_work_is_its_own_record(): void
    {
        // Somebody who spends a year checking other people's work has experience
        // that no hours entry captures.
        $mechanic = $this->mechanic();
        $inspector = $this->inspector();

        $card = app(ManageWorkOrder::class)->addCard($this->workOrder(), 'Ölwechsel');
        app(ManageWorkOrder::class)->recordTime($card, $mechanic, 60, ParticipationKind::Executed);
        app(CertifyTaskCard::class)->complete($card->fresh(), $mechanic, 'Gemacht');
        app(CertifyTaskCard::class)->certify($card->fresh(), $inspector);

        $log = app(ExperienceLog::class);

        $this->assertSame(1, $log->certificationCountBy($inspector));
        $this->assertCount(0, $log->for($inspector), 'No hours -- he did not do the work.');
    }

    #[Test]
    public function the_window_can_be_narrowed(): void
    {
        $mechanic = $this->mechanic();
        $card = app(ManageWorkOrder::class)->addCard($this->workOrder(), 'Ölwechsel');

        app(ManageWorkOrder::class)->recordTime($card, $mechanic, 60, ParticipationKind::Executed, '2024-05-01');
        app(ManageWorkOrder::class)->recordTime($card, $mechanic, 60, ParticipationKind::Executed, '2026-05-01');

        $this->assertCount(2, app(ExperienceLog::class)->for($mechanic));
        $this->assertCount(1, app(ExperienceLog::class)->for($mechanic, '2026-01-01'));
    }

    #[Test]
    public function this_module_owns_no_tables_at_all(): void
    {
        // The whole design: "eine Auswertung, keine Extra-Pflege". A stored copy
        // would be a second truth, and the first time it drifted nobody could say
        // which was right.
        $mechanic = $this->mechanic();
        $card = app(ManageWorkOrder::class)->addCard($this->workOrder(), 'Ölwechsel');
        app(ManageWorkOrder::class)->recordTime($card, $mechanic, 60, ParticipationKind::Executed);

        $tables = collect(DB::select('SHOW TABLES'))
            ->map(fn ($row): string => array_values((array) $row)[0]);

        $this->assertFalse($tables->contains('experience_entries'));
        $this->assertFalse($tables->contains('part66_logs'));

        app(ExperienceLog::class)->for($mechanic);

        $this->assertSame(1, TaskCardTime::count(), 'Reading changed nothing.');
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
            $this->aircraft(), 'Jahresnachprüfung', $this->inspector(),
        );
    }

    private ?User $mechanicUser = null;

    private ?User $inspectorUser = null;

    private function mechanic(): User
    {
        return $this->mechanicUser ??= $this->userWith(CardPermissions::CARDS_WORK);
    }

    private function inspector(): User
    {
        if ($this->inspectorUser !== null) {
            return $this->inspectorUser->fresh();
        }

        $user = $this->userWith(
            CardPermissions::CARDS_WORK,
            CardPermissions::CARDS_CERTIFY,
            CardPermissions::WORK_ORDERS_MANAGE,
        );

        Qualification::create([
            'user_id' => $user->id,
            'type' => Qualification::TYPE_PART66,
            'reference' => 'DE.66.12345',
            'category' => 'B1',
            'valid_from' => now()->subYears(3)->toDateString(),
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
