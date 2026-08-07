<?php

declare(strict_types=1);

namespace Tests\Feature\TaskCards;

use App\Core\Access\AccessSetup;
use App\Core\Modules\ModuleManager;
use App\Models\User;
use App\Modules\TaskCards\Filament\Resources\Findings\FindingResource;
use App\Modules\TaskCards\Filament\Resources\WorkOrders\WorkOrderResource;
use App\Modules\TaskCards\Models\Finding;
use App\Modules\TaskCards\Models\WorkOrder;
use App\Modules\TaskCards\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The module's screens and its dependency.
 *
 * Task cards are the project's first hard requirement: without a fleet there is
 * nothing to record work against, and cards floating free would be notes.
 */
final class TaskCardsInterfaceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        app(AccessSetup::class)->run();
    }

    #[Test]
    public function it_cannot_be_enabled_without_the_fleet(): void
    {
        // The dependency graph doing its job. A module that requires another
        // says so in its manifest, and the manager refuses rather than leaving
        // somebody with half a system.
        $manager = app(ModuleManager::class);

        $decision = $manager->canEnable('taskcards');

        $this->assertTrue($decision->allowed, 'Allowed -- it pulls the fleet in with it.');
        $this->assertContains('fleet', $decision->alsoAffects);
    }

    #[Test]
    public function enabling_it_brings_the_fleet_along(): void
    {
        $manager = app(ModuleManager::class);
        $manager->enable('taskcards');
        $manager->forgetCache();

        $this->assertTrue($manager->isEnabled('fleet'), 'Pulled in by the requirement.');
        $this->assertTrue($manager->isEnabled('taskcards'));
    }

    #[Test]
    public function the_fleet_cannot_be_switched_off_underneath_it(): void
    {
        $manager = app(ModuleManager::class);
        $manager->enable('taskcards');
        $manager->forgetCache();

        $decision = $manager->canDisable('fleet');

        $this->assertFalse($decision->allowed, 'Task cards would be left with nothing to point at.');
    }

    #[Test]
    public function a_module_that_was_off_at_boot_has_no_routes(): void
    {
        $this->assertFalse(Route::has('filament.admin.resources.work-orders.index'));
    }

    #[Test]
    public function without_the_permission_the_screens_do_not_exist(): void
    {
        app(ModuleManager::class)->enable('taskcards');
        app(ModuleManager::class)->forgetCache();

        $this->actingAs($this->userWith());

        $this->assertFalse(WorkOrderResource::canViewAny());
        $this->assertFalse(FindingResource::canViewAny());
    }

    #[Test]
    public function working_and_certifying_are_different_rights(): void
    {
        // The module's permissions come into being when it is enabled, which is
        // the point of declaring them in the manifest rather than seeding them.
        $this->enableModule();

        // The whole point of the two signatures, expressed as permissions: a
        // mechanic may finish his card and may not sign it off.
        $mechanic = $this->userWith(Permissions::WORK_ORDERS_VIEW, Permissions::CARDS_WORK);

        $this->assertTrue($mechanic->can(Permissions::CARDS_WORK));
        $this->assertFalse($mechanic->can(Permissions::CARDS_CERTIFY));

        $inspector = $this->userWith(Permissions::CARDS_CERTIFY);

        $this->assertTrue($inspector->can(Permissions::CARDS_CERTIFY));
        $this->assertFalse($inspector->can(Permissions::CARDS_WORK), 'Nor the other way round by accident.');
    }

    #[Test]
    public function recording_a_finding_and_deferring_one_are_different_rights_too(): void
    {
        $this->enableModule();

        // Noticing is not deciding. Anybody may report a crack; deciding it
        // holds until the next inspection is something else.
        $anybody = $this->userWith(Permissions::FINDINGS_RECORD);

        $this->assertTrue($anybody->can(Permissions::FINDINGS_RECORD));
        $this->assertFalse($anybody->can(Permissions::FINDINGS_DEFER));
    }

    #[Test]
    public function findings_and_visits_are_never_deleted(): void
    {
        // They are the record of what happened. A visit is closed and a finding
        // is resolved or dismissed -- neither is removed.
        app(ModuleManager::class)->enable('taskcards');
        app(ModuleManager::class)->forgetCache();

        $this->actingAs($this->userWith(Permissions::WORK_ORDERS_MANAGE, Permissions::WORK_ORDERS_VIEW));

        $this->assertFalse(WorkOrderResource::canDelete(new WorkOrder));
        $this->assertFalse(FindingResource::canDelete(new Finding));
        $this->assertFalse(FindingResource::canCreate(), 'A finding is recorded where it was noticed.');
    }

    private function enableModule(): void
    {
        app(ModuleManager::class)->enable('taskcards');
        app(ModuleManager::class)->forgetCache();
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
