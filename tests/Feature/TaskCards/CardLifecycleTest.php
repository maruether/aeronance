<?php

declare(strict_types=1);

namespace Tests\Feature\TaskCards;

use App\Core\Models\Qualification;
use App\Models\User;
use App\Modules\Fleet\Models\Aircraft;
use App\Modules\TaskCards\Actions\CertifyTaskCard;
use App\Modules\TaskCards\Actions\ManageWorkOrder;
use App\Modules\TaskCards\Actions\RecordFinding;
use App\Modules\TaskCards\Enums\FindingState;
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
 * Cancelling a card, and turning a finding into one.
 *
 * Both existed in the actions and had no way in from the screen -- which for the
 * finding meant the loop the requirement described as the core one was not walkable at
 * all, and for cancellation meant a superfluous card blocked its visit for ever.
 */
final class CardLifecycleTest extends TestCase
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
    public function a_superfluous_card_can_be_cancelled_and_stops_blocking_the_visit(): void
    {
        // Closing requires every card to be certified or cancelled. Without a
        // way to cancel, a card that turns out not to be needed keeps its visit
        // open for ever.
        $order = $this->workOrder();
        $keep = $this->cardWithTime($order);
        $drop = app(ManageWorkOrder::class)->addCard($order, 'Doch nicht nötig');

        app(CertifyTaskCard::class)->complete($keep, $this->mechanic(), 'Gemacht');
        app(CertifyTaskCard::class)->certify($keep->fresh(), $this->inspector());

        try {
            app(ManageWorkOrder::class)->close($order->fresh(), $this->inspector());
            $this->fail('The unneeded card should still be blocking.');
        } catch (RuntimeException) {
        }

        app(CertifyTaskCard::class)->cancel(
            $drop->fresh(), $this->mechanic(), 'Arbeit war beim Vorbesitzer schon erledigt',
        );

        $closed = app(ManageWorkOrder::class)->close($order->fresh(), $this->inspector());

        $this->assertSame(WorkOrder::STATE_CLOSED, $closed->state);
        $this->assertSame(TaskCardState::Cancelled, $drop->fresh()->state);
    }

    #[Test]
    public function a_signed_card_is_never_cancelled(): void
    {
        // It would erase a signature. A new card instead -- the same rule as
        // everywhere else here.
        $card = $this->cardWithTime();
        app(CertifyTaskCard::class)->complete($card, $this->mechanic(), 'Gemacht');
        app(CertifyTaskCard::class)->certify($card->fresh(), $this->inspector());

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/not cancelled/');

        app(CertifyTaskCard::class)->cancel($card->fresh(), $this->mechanic(), 'Doch');
    }

    #[Test]
    public function cancelling_has_to_say_why(): void
    {
        $card = $this->cardWithTime();

        $this->expectException(InvalidArgumentException::class);

        app(CertifyTaskCard::class)->cancel($card, $this->mechanic(), '   ');
    }

    #[Test]
    public function a_finding_becomes_a_card_and_stays_open_until_it_is_signed(): void
    {
        // The loop: finding -> card -> signature -> dealt with. It closes at the
        // signature, which is the only moment anybody can honestly say so.
        $aircraft = $this->aircraft();
        $finding = app(RecordFinding::class)->record(
            $aircraft, 'Riss im Holmgurt', 'Rechte Fläche, 20 mm', $this->mechanic(),
        );

        $order = $this->workOrder($aircraft);
        $card = app(RecordFinding::class)->schedule($finding, $order, $this->inspector());

        $this->assertSame(FindingState::Scheduled, $finding->fresh()->state);
        $this->assertTrue($finding->fresh()->isOutstanding());
        $this->assertSame($finding->title, $card->title);
    }

    #[Test]
    public function a_deferred_finding_can_be_picked_up_later(): void
    {
        // Which is the point of deferring rather than dismissing: it comes back.
        $aircraft = $this->aircraft();
        $finding = app(RecordFinding::class)->record(
            $aircraft, 'Lackabplatzer', 'Rumpfunterseite', $this->mechanic(), isBlocking: false,
        );

        app(RecordFinding::class)->defer(
            $finding, $this->inspector(), 'Kosmetisch', until: now()->addMonths(3)->toDateString(),
        );

        $card = app(RecordFinding::class)->schedule(
            $finding->fresh(), $this->workOrder($aircraft), $this->inspector(),
        );

        $this->assertSame(FindingState::Scheduled, $finding->fresh()->state);
        $this->assertNotNull($card->id);
    }

    #[Test]
    public function a_resolved_finding_cannot_be_scheduled_again(): void
    {
        $aircraft = $this->aircraft();
        $finding = app(RecordFinding::class)->record(
            $aircraft, 'Riss', 'Rechte Fläche', $this->mechanic(),
        );

        app(RecordFinding::class)->resolve($finding, $this->inspector(), 'Instandgesetzt');

        $this->expectException(RuntimeException::class);

        app(RecordFinding::class)->schedule(
            $finding->fresh(), $this->workOrder($aircraft), $this->inspector(),
        );
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

    private function cardWithTime(?WorkOrder $order = null): TaskCard
    {
        $card = app(ManageWorkOrder::class)->addCard($order ?? $this->workOrder(), 'Ölwechsel');

        app(ManageWorkOrder::class)->recordTime(
            $card, $this->mechanic(), 60, ParticipationKind::Executed,
        );

        return $card->fresh();
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
