<?php

declare(strict_types=1);

namespace Tests\Feature\Part66;

use App\Core\Models\Qualification;
use App\Models\User;
use App\Modules\Fleet\Models\Aircraft;
use App\Modules\Part66\Support\RecencyReport;
use App\Modules\TaskCards\Actions\CertifyTaskCard;
use App\Modules\TaskCards\Actions\ManageWorkOrder;
use App\Modules\TaskCards\Enums\ParticipationKind;
use App\Modules\TaskCards\Models\WorkOrder;
use App\Modules\TaskCards\Permissions as CardPermissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * The last two years, for 66.A.20(b).
 *
 * The thing under test is as much what this does NOT do: it reports figures and
 * refuses to declare compliance. Six months of experience in two years is clear
 * for somebody in employment and genuinely not for a volunteer who works three
 * Saturdays a month -- six months of what? A number invented here would be worse
 * than none, because somebody would rely on it.
 */
final class RecencyTest extends TestCase
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
    public function it_counts_days_not_entries(): void
    {
        // Three cards on one Saturday is one day of experience. Counting entries
        // would turn a busy afternoon into three.
        $mechanic = $this->mechanic();
        $order = $this->workOrder();

        foreach (['Ölwechsel', 'Gurte prüfen', 'Haube reinigen'] as $title) {
            $card = app(ManageWorkOrder::class)->addCard($order, $title);
            app(ManageWorkOrder::class)->recordTime(
                $card, $mechanic, 40, ParticipationKind::Executed, now()->subMonth()->toDateString(),
            );
        }

        $report = app(RecencyReport::class)->for($mechanic);

        $this->assertSame(3, $report['entries']->count());
        $this->assertSame(1, $report['days'], 'One Saturday.');
        $this->assertSame(1, $report['months']);
        $this->assertSame(2.0, $report['hours']);
    }

    #[Test]
    public function it_counts_distinct_calendar_months(): void
    {
        $mechanic = $this->mechanic();
        $order = $this->workOrder();
        $card = app(ManageWorkOrder::class)->addCard($order, 'Ölwechsel');

        /*
         * Explicit months, not now()->subMonths().
         *
         * This test passed for months and failed on the 31st: from the 31st,
         * Carbon's subMonths overflows into the following month, so "one month
         * ago" and "three months ago" landed in the same one and four distinct
         * months became three. A date-dependent test that is wrong for three
         * days a month is worse than no test -- it is a test somebody re-runs
         * and shrugs at.
         */
        foreach ([1, 1, 2, 3, 8] as $monthsAgo) {
            app(ManageWorkOrder::class)->recordTime(
                $card, $mechanic, 60, ParticipationKind::Executed,
                now()->startOfMonth()->subMonthsNoOverflow($monthsAgo)->toDateString(),
            );
        }

        $this->assertSame(4, app(RecencyReport::class)->for($mechanic)['months']);
    }

    #[Test]
    public function work_older_than_the_window_falls_out(): void
    {
        $mechanic = $this->mechanic();
        $card = app(ManageWorkOrder::class)->addCard($this->workOrder(), 'Ölwechsel');

        app(ManageWorkOrder::class)->recordTime(
            $card, $mechanic, 60, ParticipationKind::Executed, now()->subMonths(30)->toDateString(),
        );
        app(ManageWorkOrder::class)->recordTime(
            $card, $mechanic, 60, ParticipationKind::Executed, now()->subMonths(3)->toDateString(),
        );

        $report = app(RecencyReport::class)->for($mechanic);

        $this->assertSame(1, $report['days'], 'The 30-month-old entry is outside the two years.');
        $this->assertSame(24, RecencyReport::WINDOW_MONTHS);
    }

    #[Test]
    public function it_reports_the_gap_since_the_last_entry(): void
    {
        // The figure somebody actually wants: a total spread over two years does
        // not show that nobody has touched an aircraft since last spring.
        $mechanic = $this->mechanic();
        $card = app(ManageWorkOrder::class)->addCard($this->workOrder(), 'Ölwechsel');

        app(ManageWorkOrder::class)->recordTime(
            $card, $mechanic, 60, ParticipationKind::Executed, now()->subDays(200)->toDateString(),
        );

        $report = app(RecencyReport::class)->for($mechanic);

        $this->assertSame(200, $report['gap_days']);
        $this->assertSame(now()->subDays(200)->toDateString(), $report['last_worked']->toDateString());
    }

    #[Test]
    public function it_says_what_is_worth_noticing_without_passing_judgement(): void
    {
        // Observations, not verdicts. None of them says whether the licence is in
        // order -- that is not a call this class can make.
        $mechanic = $this->mechanic();
        $card = app(ManageWorkOrder::class)->addCard($this->workOrder(), 'Ölwechsel');

        app(ManageWorkOrder::class)->recordTime(
            $card, $mechanic, 60, ParticipationKind::Executed, now()->subDays(200)->toDateString(),
        );

        $service = app(RecencyReport::class);
        $notes = $service->observations($service->for($mechanic));

        $this->assertNotEmpty($notes);

        $joined = implode(' ', $notes);
        $this->assertStringContainsString('nicht entschieden', $joined, 'It says the question is open.');
        $this->assertStringContainsString('200', $joined);
    }

    #[Test]
    public function an_empty_window_says_so_plainly(): void
    {
        $service = app(RecencyReport::class);
        $notes = $service->observations($service->for($this->mechanic()));

        $this->assertCount(1, $notes);
        $this->assertStringContainsString('keine Arbeit erfasst', $notes[0]);
    }

    #[Test]
    public function provisional_entries_are_flagged_in_the_notes(): void
    {
        // Unreleased work can still change, so those figures are not settled --
        // and somebody assembling a licence application should know which.
        $mechanic = $this->mechanic();
        $card = app(ManageWorkOrder::class)->addCard($this->workOrder(), 'Ölwechsel');
        app(ManageWorkOrder::class)->recordTime($card, $mechanic, 60, ParticipationKind::Executed);

        $service = app(RecencyReport::class);
        $notes = $service->observations($service->for($mechanic));

        $this->assertStringContainsString('nicht freigegebenen', implode(' ', $notes));
    }

    #[Test]
    public function certifications_and_releases_are_counted_separately(): void
    {
        // Hours worked, cards signed and releases issued are three different
        // records of experience, and a licence assessment looks at all three.
        $mechanic = $this->mechanic();
        $inspector = $this->inspector();

        $card = app(ManageWorkOrder::class)->addCard($this->workOrder(), 'Ölwechsel');
        app(ManageWorkOrder::class)->recordTime($card, $mechanic, 60, ParticipationKind::Executed);
        app(CertifyTaskCard::class)->complete($card->fresh(), $mechanic, 'Gemacht');
        app(CertifyTaskCard::class)->certify($card->fresh(), $inspector);

        $report = app(RecencyReport::class)->for($inspector);

        $this->assertSame(1, $report['certifications']);
        $this->assertSame(0.0, $report['hours'], 'He checked it, he did not do it.');
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
