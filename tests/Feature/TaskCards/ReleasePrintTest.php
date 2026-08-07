<?php

declare(strict_types=1);

namespace Tests\Feature\TaskCards;

use App\Core\Access\AccessSetup;
use App\Core\Models\Qualification;
use App\Core\Modules\ModuleManager;
use App\Models\User;
use App\Modules\Fleet\Models\Aircraft;
use App\Modules\TaskCards\Actions\CertifyTaskCard;
use App\Modules\TaskCards\Actions\IssueRelease;
use App\Modules\TaskCards\Actions\ManageWorkOrder;
use App\Modules\TaskCards\Enums\ParticipationKind;
use App\Modules\TaskCards\Models\ReleaseToService;
use App\Modules\TaskCards\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * The certificate on paper.
 *
 * The paper in the folder outlives the person who filed it, so the printed
 * version has to say two things the screen does not: a superseded certificate
 * must not look current, and a correction must explain itself without a
 * database.
 */
final class ReleasePrintTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        app(AccessSetup::class)->run();
        app(ModuleManager::class)->enable('taskcards');
        app(ModuleManager::class)->forgetCache();

        foreach ([
            Permissions::CARDS_WORK,
            Permissions::CARDS_CERTIFY,
            Permissions::WORK_ORDERS_MANAGE,
            Permissions::WORK_ORDERS_VIEW,
        ] as $p) {
            Permission::findOrCreate($p, 'web');
        }
    }

    #[Test]
    public function the_sheet_carries_statement_signer_and_credential(): void
    {
        $release = $this->release();

        $this->actingAs($this->userWith(Permissions::WORK_ORDERS_VIEW))
            ->get(route('taskcards.release', $release))
            ->assertSuccessful()
            ->assertSee('Freigabebescheinigung', false)
            ->assertSee($release->number)
            ->assertSee('D-KABC')
            ->assertSee($release->released_by_name)
            ->assertSee('DE.66.12345');
    }

    #[Test]
    public function a_superseded_certificate_prints_with_a_banner(): void
    {
        // The one thing a stale printout must not do is look current.
        $release = $this->release();

        $correction = app(IssueRelease::class)->correct(
            $release, $this->inspector(), 'Falsche Unterlage angegeben',
        );

        $this->actingAs($this->userWith(Permissions::WORK_ORDERS_VIEW))
            ->get(route('taskcards.release', $release))
            ->assertSuccessful()
            ->assertSee('ERSETZT', false)
            ->assertSee($correction->number);
    }

    #[Test]
    public function a_correction_names_what_was_wrong(): void
    {
        // The paper trail has to explain itself without access to the database.
        $release = $this->release();
        $correction = app(IssueRelease::class)->correct(
            $release, $this->inspector(), 'Falsche Unterlage angegeben',
        );

        $this->actingAs($this->userWith(Permissions::WORK_ORDERS_VIEW))
            ->get(route('taskcards.release', $correction))
            ->assertSuccessful()
            ->assertSee('Falsche Unterlage angegeben')
            ->assertSee($release->number);
    }

    #[Test]
    public function it_needs_the_view_permission(): void
    {
        $release = $this->release();

        $this->actingAs($this->userWith(Permissions::CARDS_WORK))
            ->get(route('taskcards.release', $release))
            ->assertForbidden();
    }

    #[Test]
    public function nothing_is_served_while_the_module_is_off(): void
    {
        $release = $this->release();

        app(ModuleManager::class)->disable('taskcards');
        app(ModuleManager::class)->forgetCache();

        $this->actingAs($this->userWith(Permissions::WORK_ORDERS_VIEW))
            ->get(route('taskcards.release', $release))
            ->assertNotFound();
    }

    private function release(): ReleaseToService
    {
        $aircraft = Aircraft::firstOrCreate(
            ['registration' => 'D-KABC'],
            ['model' => 'ASK 21'],
        );

        $order = app(ManageWorkOrder::class)->open($aircraft, 'Jahresnachprüfung', $this->inspector());
        $card = app(ManageWorkOrder::class)->addCard($order, 'Ölwechsel');

        $mechanic = $this->userWith(Permissions::CARDS_WORK);
        app(ManageWorkOrder::class)->recordTime($card, $mechanic, 60, ParticipationKind::Executed);
        app(CertifyTaskCard::class)->complete($card->fresh(), $mechanic, 'Gemacht');
        app(CertifyTaskCard::class)->certify($card->fresh(), $this->inspector());

        return app(IssueRelease::class)->handle($order->fresh(), $this->inspector());
    }

    private ?User $inspectorUser = null;

    private function inspector(): User
    {
        if ($this->inspectorUser !== null) {
            return $this->inspectorUser->fresh();
        }

        $user = $this->userWith(
            Permissions::CARDS_WORK,
            Permissions::CARDS_CERTIFY,
            Permissions::WORK_ORDERS_MANAGE,
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
