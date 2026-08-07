<?php

declare(strict_types=1);

namespace Tests\Feature\Access;

use App\Core\Access\AccessSetup;
use App\Core\Access\CoreRoles;
use App\Core\Modules\ModuleManager;
use App\Models\User;
use App\Modules\Part66\Permissions as Part66Permissions;
use App\Modules\TaskCards\Permissions as CardPermissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Permissions a module hands to a role out of the box.
 *
 * The rule that had to be squared with the existing one: AccessSetup never
 * reassigns anything, so that a club which has tailored its roles keeps that
 * tailoring. A default assignment looks like a contradiction and is not -- it
 * only ever touches a permission that has just come into being, and nobody could
 * have had an opinion about a permission that did not exist a moment ago.
 */
final class DefaultRolePermissionsTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function the_workshop_manager_gets_to_read_other_peoples_logs(): void
    {
        // the call: reading somebody else's experience log is exactly what a
        // workshop manager has to do to confirm it, and exactly what nobody else
        // has business doing.
        app(ModuleManager::class)->enable('part66');
        app(ModuleManager::class)->forgetCache();
        app(AccessSetup::class)->run();

        $manager = Role::where('name', CoreRoles::WORKSHOP_MANAGER)->sole();

        $this->assertTrue($manager->hasPermissionTo(Part66Permissions::LOGS_VIEW_ALL));
    }

    #[Test]
    public function and_nobody_else_does(): void
    {
        app(ModuleManager::class)->enable('part66');
        app(ModuleManager::class)->forgetCache();
        app(AccessSetup::class)->run();

        foreach ([CoreRoles::CERTIFYING_STAFF, CoreRoles::MECHANIC, CoreRoles::MEMBER] as $roleName) {
            $role = Role::where('name', $roleName)->sole();

            $this->assertFalse(
                $role->hasPermissionTo(Part66Permissions::LOGS_VIEW_ALL),
                sprintf('%s should not read other people\'s logs.', $roleName),
            );
        }
    }

    #[Test]
    public function it_works_when_the_module_is_enabled_long_after_setup(): void
    {
        // The case that makes the feature worth having at all: roles are created
        // during setup, modules are switched on months later. A default that only
        // applied when both happened in the same second would apply to nobody.
        app(AccessSetup::class)->run();

        $manager = Role::where('name', CoreRoles::WORKSHOP_MANAGER)->sole();

        // Checked without hasPermissionTo, which throws for a permission that
        // does not exist yet -- and at this point it genuinely does not.
        $this->assertFalse($this->holds($manager, Part66Permissions::LOGS_VIEW_ALL));

        app(ModuleManager::class)->enable('part66');
        app(ModuleManager::class)->forgetCache();
        app(AccessSetup::class)->run();

        $this->assertTrue($manager->fresh()->hasPermissionTo(Part66Permissions::LOGS_VIEW_ALL));
    }

    #[Test]
    public function a_permission_taken_away_deliberately_stays_away(): void
    {
        // The rule this had to be squared with. Once a permission exists, who
        // holds it is the club's business -- and a later run must not quietly put
        // back what somebody removed on purpose.
        app(ModuleManager::class)->enable('part66');
        app(ModuleManager::class)->forgetCache();
        app(AccessSetup::class)->run();

        $manager = Role::where('name', CoreRoles::WORKSHOP_MANAGER)->sole();
        $manager->revokePermissionTo(Part66Permissions::LOGS_VIEW_ALL);

        app(AccessSetup::class)->run();

        $this->assertFalse(
            $manager->fresh()->hasPermissionTo(Part66Permissions::LOGS_VIEW_ALL),
            'A second run must not undo a deliberate removal.',
        );
    }

    #[Test]
    public function modules_without_declared_defaults_hand_out_nothing(): void
    {
        // Most permissions have no default, and that is the safe direction: they
        // exist, and somebody decides who gets them.
        app(ModuleManager::class)->enable('taskcards');
        app(ModuleManager::class)->forgetCache();
        app(AccessSetup::class)->run();

        foreach (CoreRoles::all() as $roleName) {
            $role = Role::where('name', $roleName)->sole();

            $this->assertFalse(
                $role->hasPermissionTo(CardPermissions::CARDS_CERTIFY),
                sprintf('%s should not certify cards by default.', $roleName),
            );
        }
    }

    #[Test]
    public function enabling_the_module_alone_is_enough(): void
    {
        // Which is what the listener is for, and why this test exists in this
        // shape: the first version called AccessSetup afterwards and so proved
        // nothing about the path an administrator actually takes -- clicking
        // "enable" in the module management page.
        app(AccessSetup::class)->run();
        app(ModuleManager::class)->enable('part66');
        app(ModuleManager::class)->forgetCache();

        $manager = Role::where('name', CoreRoles::WORKSHOP_MANAGER)->sole();

        $this->assertTrue($manager->hasPermissionTo(Part66Permissions::LOGS_VIEW_ALL));
    }

    #[Test]
    public function what_was_granted_is_reported_back(): void
    {
        // Widening what a role may do is worth saying out loud even when it is
        // intended, so the caller is told rather than left to notice.
        //
        // The permission is removed first because enabling the module already
        // created and granted it -- the report only ever covers what THIS run
        // did, which is the point of it.
        app(ModuleManager::class)->enable('part66');
        app(ModuleManager::class)->forgetCache();

        Permission::where('name', Part66Permissions::LOGS_VIEW_ALL)->delete();
        app()->forgetInstance(PermissionRegistrar::class);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $result = app(AccessSetup::class)->run();

        $this->assertArrayHasKey('granted', $result);
        $this->assertContains(
            Part66Permissions::LOGS_VIEW_ALL,
            $result['granted'][CoreRoles::WORKSHOP_MANAGER] ?? [],
        );
    }

    /**
     * Whether a role holds a permission, without throwing when it does not exist.
     */
    private function holds(Role $role, string $permission): bool
    {
        return $role->permissions->pluck('name')->contains($permission);
    }

    #[Test]
    public function a_second_run_reports_nothing_new(): void
    {
        app(ModuleManager::class)->enable('part66');
        app(ModuleManager::class)->forgetCache();
        app(AccessSetup::class)->run();

        $this->assertSame([], app(AccessSetup::class)->run()['granted']);
    }

    #[Test]
    public function a_workshop_manager_can_then_actually_read_a_log(): void
    {
        // End to end, because a permission nobody can use is not a permission.
        app(ModuleManager::class)->enable('part66');
        app(ModuleManager::class)->forgetCache();
        app(AccessSetup::class)->run();

        $manager = User::factory()->create(['is_active' => true]);
        $manager->assignRole(CoreRoles::WORKSHOP_MANAGER);

        $other = User::factory()->create(['is_active' => true]);

        $this->actingAs($manager->fresh())
            ->get(route('part66.log', ['person' => $other->id]))
            ->assertSuccessful();
    }
}
