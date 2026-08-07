<?php

declare(strict_types=1);

namespace Tests\Feature\TaskCards;

use App\Core\Models\Qualification;
use App\Models\User;
use App\Modules\Fleet\Actions\CommissionExternalWork;
use App\Modules\Fleet\Models\Aircraft;
use App\Modules\Fleet\Models\ExternalWorkOrder;
use App\Modules\Fleet\Permissions as FleetPermissions;
use App\Modules\TaskCards\Actions\CertifyTaskCard;
use App\Modules\TaskCards\Actions\IssueRelease;
use App\Modules\TaskCards\Actions\ManageWorkOrder;
use App\Modules\TaskCards\Enums\ParticipationKind;
use App\Modules\TaskCards\Models\WorkOrder;
use App\Modules\TaskCards\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Tying a visit to the external order it commissioned.
 *
 * The column had existed since the first migration and nothing wrote it. The
 * point of the link: the annual whose engine went to a shop shows, on the same
 * page, which order that was -- instead of two records describing the same
 * event without knowing of each other.
 */
final class ExternalOrderLinkTest extends TestCase
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
        ] as $p) {
            Permission::findOrCreate($p, 'web');
        }
    }

    #[Test]
    public function a_visit_records_which_external_order_it_commissioned(): void
    {
        $aircraft = $this->aircraft();
        $order = $this->workOrder($aircraft);
        $external = $this->externalOrder($aircraft);

        $linked = app(ManageWorkOrder::class)->linkExternalOrder(
            $order, $external, $this->manager(),
        );

        $this->assertSame($external->id, $linked->external_work_order_id);
        $this->assertSame($external->id, $linked->externalWorkOrder->id);
    }

    #[Test]
    public function an_order_for_a_different_aircraft_is_refused(): void
    {
        // An overhaul commissioned for D-KXYZ says nothing about D-KABC's
        // annual, and linking it would put a false trace into both records.
        $order = $this->workOrder($this->aircraft());
        $other = Aircraft::create(['registration' => 'D-KXYZ', 'model' => 'DG 300']);
        $external = $this->externalOrder($other);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/false trace/');

        app(ManageWorkOrder::class)->linkExternalOrder($order, $external, $this->manager());
    }

    #[Test]
    public function a_released_visit_refuses_the_link_through_its_freeze(): void
    {
        // No special case in the action: the freeze in the model answers, which
        // is the point of enforcing it there.
        $aircraft = $this->aircraft();
        $order = $this->workOrder($aircraft);

        $card = app(ManageWorkOrder::class)->addCard($order, 'Ölwechsel');
        $mechanic = $this->manager();
        app(ManageWorkOrder::class)->recordTime($card, $mechanic, 60, ParticipationKind::Executed);
        app(CertifyTaskCard::class)->complete($card->fresh(), $mechanic, 'Gemacht');
        app(CertifyTaskCard::class)->certify($card->fresh(), $this->inspector());
        app(IssueRelease::class)->handle($order->fresh(), $this->inspector());

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/frozen/');

        app(ManageWorkOrder::class)->linkExternalOrder(
            $order->fresh(), $this->externalOrder($aircraft), $this->manager(),
        );
    }

    #[Test]
    public function a_cancelled_visit_records_nothing_further(): void
    {
        $aircraft = $this->aircraft();
        $order = $this->workOrder($aircraft);
        $order->update(['state' => WorkOrder::STATE_CANCELLED]);

        $this->expectException(RuntimeException::class);

        app(ManageWorkOrder::class)->linkExternalOrder(
            $order->fresh(), $this->externalOrder($aircraft), $this->manager(),
        );
    }

    private function aircraft(): Aircraft
    {
        return Aircraft::firstOrCreate(
            ['registration' => 'D-KABC'],
            ['model' => 'ASK 21'],
        );
    }

    private function workOrder(Aircraft $aircraft): WorkOrder
    {
        return app(ManageWorkOrder::class)->open($aircraft, 'Jahresnachprüfung', $this->manager());
    }

    private function externalOrder(Aircraft $aircraft): ExternalWorkOrder
    {
        return app(CommissionExternalWork::class)->commission(
            $aircraft, 'Musterwerft GmbH', 'Motorüberholung',
            $this->userWith(FleetPermissions::EXTERNAL_WORK_MANAGE),
        );
    }

    private ?User $managerUser = null;

    private ?User $inspectorUser = null;

    private function manager(): User
    {
        return $this->managerUser ??= $this->userWith(
            Permissions::WORK_ORDERS_MANAGE, Permissions::CARDS_WORK,
        );
    }

    private function inspector(): User
    {
        if ($this->inspectorUser !== null) {
            return $this->inspectorUser->fresh();
        }

        $user = $this->userWith(Permissions::CARDS_CERTIFY);

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
