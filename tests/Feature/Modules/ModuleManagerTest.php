<?php

declare(strict_types=1);

namespace Tests\Feature\Modules;

use App\Core\Access\PermissionDefinition;
use App\Core\Modules\Contracts\AeronanceModule;
use App\Core\Modules\DependencyResolver;
use App\Core\Modules\Events\ModuleDisabled;
use App\Core\Modules\Events\ModuleEnabled;
use App\Core\Modules\Manifest;
use App\Core\Modules\ModuleManager;
use App\Core\Modules\ModuleRegistry;
use Filament\Panel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

/**
 * Runs against MariaDB, like every test here -- see phpunit.xml.
 */
final class ModuleManagerTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function nothing_is_active_in_a_fresh_installation(): void
    {
        $this->assertSame([], $this->manager()->enabled());
    }

    #[Test]
    public function enabling_a_module_persists_it(): void
    {
        $manager = $this->manager();

        $manager->enable('warehouse');

        $this->assertTrue($manager->isEnabled('warehouse'));
        $this->assertDatabaseHas('modules', ['name' => 'warehouse']);

        // and survives a fresh instance, so it really came from the database
        $this->assertTrue($this->manager()->isEnabled('warehouse'));
    }

    #[Test]
    public function enabling_pulls_in_what_the_module_requires(): void
    {
        $manager = $this->manager();

        $enabled = $manager->enable('taskcards');

        $this->assertEqualsCanonicalizing(['fleet', 'taskcards'], $enabled);
        $this->assertTrue($manager->isEnabled('fleet'));
    }

    #[Test]
    public function disabling_keeps_the_row_and_the_data(): void
    {
        $manager = $this->manager();
        $manager->enable('warehouse');

        $manager->disable('warehouse');

        $this->assertFalse($manager->isEnabled('warehouse'));

        // Deactivating is not uninstalling: the record stays, only the flag drops.
        $this->assertDatabaseHas('modules', ['name' => 'warehouse', 'enabled_at' => null]);
    }

    #[Test]
    public function it_refuses_to_disable_a_module_another_active_module_needs(): void
    {
        $manager = $this->manager();
        $manager->enable('taskcards');

        $this->expectException(RuntimeException::class);

        $manager->disable('fleet');
    }

    #[Test]
    public function it_reports_events_for_every_module_switched(): void
    {
        Event::fake([ModuleEnabled::class, ModuleDisabled::class]);

        $manager = $this->manager();
        $manager->enable('taskcards');

        Event::assertDispatched(ModuleEnabled::class, fn (ModuleEnabled $e): bool => $e->module === 'fleet');
        Event::assertDispatched(ModuleEnabled::class, fn (ModuleEnabled $e): bool => $e->module === 'taskcards');

        $manager->disable('taskcards');

        Event::assertDispatched(ModuleDisabled::class, fn (ModuleDisabled $e): bool => $e->module === 'taskcards');
    }

    #[Test]
    public function enabling_twice_changes_nothing(): void
    {
        $manager = $this->manager();
        $manager->enable('warehouse');

        $this->assertSame([], $manager->enable('warehouse'));
        $this->assertSame(1, \DB::table('modules')->where('name', 'warehouse')->count());
    }

    #[Test]
    public function a_module_dropped_from_the_release_is_ignored(): void
    {
        // A row survives from an older version whose module no longer ships.
        \DB::table('modules')->insert([
            'name' => 'retired-module',
            'version' => '1.0.0',
            'enabled_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->assertNotContains('retired-module', $this->manager()->enabled());
    }

    #[Test]
    public function it_survives_a_missing_table_instead_of_dying(): void
    {
        // The state the setup wizard runs in: application booted, nothing
        // migrated. Reading the module state must not throw here, or the wizard
        // could never be reached.
        Schema::drop('modules');

        $manager = $this->manager();

        $this->assertSame([], $manager->enabled());
        $this->assertFalse($manager->isEnabled('warehouse'));
        $this->assertFalse($manager->isInstalled());
    }

    private function manager(): ModuleManager
    {
        $registry = new ModuleRegistry([
            $this->module('warehouse'),
            $this->module('fleet'),
            $this->module('taskcards', requires: ['fleet']),
        ]);

        return new ModuleManager($registry, new DependencyResolver($registry));
    }

    /**
     * @param  list<string>  $requires
     */
    private function module(string $name, array $requires = []): AeronanceModule
    {
        return new class($name, $requires) implements AeronanceModule
        {
            /** @param  list<string>  $requires */
            public function __construct(
                private readonly string $name,
                private readonly array $requires,
            ) {}

            public function getId(): string
            {
                return $this->name;
            }

            public function manifest(): Manifest
            {
                return new Manifest(
                    name: $this->name,
                    version: '1.0.0',
                    title: ucfirst($this->name),
                    description: '',
                    requires: $this->requires,
                );
            }

            /** @return list<PermissionDefinition> */
            public function permissions(): array
            {
                return [];
            }

            public function register(Panel $panel): void {}

            public function boot(Panel $panel): void {}
        };
    }
}
