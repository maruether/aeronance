<?php

declare(strict_types=1);

namespace Tests\Feature\Modules;

use App\Core\Access\AccessSetup;
use App\Core\Access\CorePermissions;
use App\Core\Access\PermissionRegistry;
use App\Core\Filament\Pages\ManageModules;
use App\Core\Modules\DependencyResolver;
use App\Core\Modules\ModuleManager;
use App\Core\Modules\ModuleRegistry;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\Fixtures\Modules\AlphaModule;
use Tests\Fixtures\Modules\BetaModule;
use Tests\TestCase;

/**
 * The module management screen, driven through the real config path.
 *
 * Covers the second and third layers of D3 as well: a screen that merely hides
 * itself is not switched off, so the access check is tested rather than assumed.
 */
final class ManageModulesPageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('aeronance.modules', [
            AlphaModule::class,
            BetaModule::class,
        ]);

        // Rebuild the singletons so they see the config set above.
        $this->app->forgetInstance(ModuleRegistry::class);
        $this->app->forgetInstance(DependencyResolver::class);
        $this->app->forgetInstance(ModuleManager::class);

        app(AccessSetup::class)->run();
    }

    #[Test]
    public function someone_without_the_permission_cannot_reach_it(): void
    {
        $this->actingAs($this->userWithout());

        $this->assertFalse(ManageModules::canAccess());

        // And not by typing the address either -- hiding the navigation entry
        // is not switching something off.
        $this->get(ManageModules::getUrl())->assertForbidden();
    }

    #[Test]
    public function an_administrator_can_reach_it(): void
    {
        $this->actingAs($this->administrator());

        $this->assertTrue(ManageModules::canAccess());
        $this->get(ManageModules::getUrl())->assertSuccessful();
    }

    /**
     * Der Knopf muss im HTML STEHEN, nicht nur die Methode existieren.
     *
     * Auf test.aeronance.de stand diese Seite mit Status-Abzeichen und ohne
     * einen einzigen Schalter da: Der Blade-Slot hiess "footerActions", die
     * Komponente kennt nur "footer", und einen unbekannten Slot verwirft Blade
     * stillschweigend. Alle Livewire-Tests hier riefen enableModule() direkt
     * auf und blieben gruen -- niemand hatte je auf den Knopf geprueft.
     */
    #[Test]
    public function the_toggle_buttons_are_actually_on_the_page(): void
    {
        $this->actingAs($this->administrator());

        $this->get(ManageModules::getUrl())
            ->assertSuccessful()
            ->assertSee(__('modules.action.enable'));
    }

    #[Test]
    public function it_lists_the_shipped_modules(): void
    {
        $this->actingAs($this->administrator());

        $rows = (new ManageModules)->getModuleRows();

        $this->assertCount(2, $rows);
        $this->assertSame('alpha', $rows[0]['name']);
        $this->assertFalse($rows[0]['enabled']);
    }

    #[Test]
    public function it_says_what_will_come_along_before_anything_happens(): void
    {
        $this->actingAs($this->administrator());

        $rows = collect((new ManageModules)->getModuleRows())->keyBy('name');

        $this->assertSame(['Alpha'], $rows['beta']['alsoAffects']);
        $this->assertSame(['Alpha'], $rows['beta']['requires']);
    }

    #[Test]
    public function enabling_a_module_pulls_in_its_dependency(): void
    {
        $this->actingAs($this->administrator());

        Livewire::test(ManageModules::class)
            ->call('enableModule', 'beta')
            ->assertHasNoErrors();

        $manager = app(ModuleManager::class);
        $this->assertTrue($manager->isEnabled('beta'));
        $this->assertTrue($manager->isEnabled('alpha'), 'The dependency must be switched on too.');
    }

    #[Test]
    public function enabling_a_module_creates_its_permissions(): void
    {
        $this->actingAs($this->administrator());

        $this->assertFalse(Permission::query()->where('name', 'alpha.view')->exists());

        Livewire::test(ManageModules::class)->call('enableModule', 'alpha');

        $this->assertTrue(
            Permission::query()->where('name', 'alpha.view')->exists(),
            'A module that has just been enabled must bring its permissions with it, '
            .'or the role editor shows an empty list.',
        );

        /*
         * Und der Admin HAELT sie sofort -- nicht nur "sie existieren".
         *
         * Auf test.aeronance.de existierten nach dem Aktivieren aller Module
         * 47 Rechte, die admin-Rolle hielt 8: Die Module deklarierten keine
         * Default-Rollen, die Rechte gehoerten niemandem, und der Administrator
         * stand vor einer leeren Oberflaeche. Seither haengt
         * PermissionDefinition den Admin zentral an jede Deklaration.
         */
        $admin = Role::findByName('admin');
        $this->assertTrue(
            $admin->hasPermissionTo('alpha.view'),
            'A freshly created module permission must land on the admin role, '
            .'or an administrator faces an empty interface after enabling modules.',
        );
    }

    #[Test]
    public function it_explains_why_a_module_cannot_be_switched_off(): void
    {
        $this->actingAs($this->administrator());

        app(ModuleManager::class)->enable('beta');

        $rows = collect((new ManageModules)->getModuleRows())->keyBy('name');

        $this->assertFalse($rows['alpha']['canToggle']);
        $this->assertNotEmpty($rows['alpha']['blockedBy']);
        $this->assertStringContainsString('Beta', implode(' ', $rows['alpha']['blockedBy']));
    }

    #[Test]
    public function switching_off_keeps_the_data(): void
    {
        $this->actingAs($this->administrator());

        $manager = app(ModuleManager::class);
        $manager->enable('alpha');

        Livewire::test(ManageModules::class)->call('disableModule', 'alpha');

        $this->assertFalse(app(ModuleManager::class)->isEnabled('alpha'));
        $this->assertDatabaseHas('modules', ['name' => 'alpha', 'enabled_at' => null]);

        // The permission survives too: the assignment belongs to the role.
        $this->assertTrue(Permission::query()->where('name', 'alpha.view')->exists());
    }

    #[Test]
    public function a_disabled_module_contributes_no_permissions_to_the_editor(): void
    {
        $this->actingAs($this->administrator());

        $registry = app(PermissionRegistry::class);
        $before = count($registry->active());

        app(ModuleManager::class)->enable('alpha');
        $this->app->forgetInstance(PermissionRegistry::class);

        $this->assertGreaterThan($before, count(app(PermissionRegistry::class)->active()));
    }

    private function administrator(): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->givePermissionTo(CorePermissions::MODULES_MANAGE);

        return $user->fresh();
    }

    private function userWithout(): User
    {
        return User::factory()->create(['is_active' => true]);
    }
}
