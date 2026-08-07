<?php

declare(strict_types=1);

namespace Tests\Unit\Modules;

use App\Core\Access\PermissionDefinition;
use App\Core\Modules\Contracts\AeronanceModule;
use App\Core\Modules\DependencyResolver;
use App\Core\Modules\Manifest;
use App\Core\Modules\ModuleRegistry;
use Filament\Panel;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The dependency resolver takes no database and no framework, so the awkward
 * cases can be stated directly: diamonds, cycles, and conflicts that only
 * appear through a dependency.
 */
final class DependencyResolverTest extends TestCase
{
    #[Test]
    public function it_allows_enabling_a_module_without_dependencies(): void
    {
        $decision = $this->resolver([
            $this->module('warehouse'),
        ])->canEnable('warehouse', []);

        $this->assertTrue($decision->allowed);
        $this->assertSame([], $decision->alsoAffects);
    }

    #[Test]
    public function it_pulls_in_required_modules(): void
    {
        $decision = $this->resolver([
            $this->module('fleet'),
            $this->module('taskcards', requires: ['fleet']),
        ])->canEnable('taskcards', []);

        $this->assertTrue($decision->allowed);
        $this->assertSame(['fleet'], $decision->alsoAffects);
    }

    #[Test]
    public function it_pulls_in_dependencies_of_dependencies(): void
    {
        // releases -> taskcards -> fleet, the chain CLAUDE.md describes
        $resolver = $this->resolver([
            $this->module('fleet'),
            $this->module('taskcards', requires: ['fleet']),
            $this->module('releases', requires: ['taskcards']),
        ]);

        $decision = $resolver->canEnable('releases', []);

        $this->assertTrue($decision->allowed);
        $this->assertEqualsCanonicalizing(['taskcards', 'fleet'], $decision->alsoAffects);
    }

    #[Test]
    public function it_does_not_list_already_active_modules_as_additions(): void
    {
        $decision = $this->resolver([
            $this->module('fleet'),
            $this->module('taskcards', requires: ['fleet']),
        ])->canEnable('taskcards', ['fleet']);

        $this->assertTrue($decision->allowed);
        $this->assertSame([], $decision->alsoAffects);
    }

    #[Test]
    public function it_refuses_a_direct_conflict(): void
    {
        $decision = $this->resolver([
            $this->module('alpha', conflicts: ['beta']),
            $this->module('beta'),
        ])->canEnable('alpha', ['beta']);

        $this->assertFalse($decision->allowed);
        $this->assertNotEmpty($decision->blockedBy);
    }

    #[Test]
    public function it_refuses_a_conflict_that_only_arrives_through_a_dependency(): void
    {
        // Enabling "releases" drags in "taskcards", which conflicts with
        // something already active. The naive check -- only looking at the
        // module being switched on -- would miss this.
        $decision = $this->resolver([
            $this->module('taskcards', conflicts: ['legacy']),
            $this->module('releases', requires: ['taskcards']),
            $this->module('legacy'),
        ])->canEnable('releases', ['legacy']);

        $this->assertFalse($decision->allowed);
        $this->assertStringContainsString('would pull in', implode(' ', $decision->blockedBy));
    }

    #[Test]
    public function it_refuses_disabling_a_module_another_active_module_needs(): void
    {
        $decision = $this->resolver([
            $this->module('fleet'),
            $this->module('taskcards', requires: ['fleet']),
        ])->canDisable('fleet', ['fleet', 'taskcards']);

        $this->assertFalse($decision->allowed);
        $this->assertStringContainsString('needs', implode(' ', $decision->blockedBy));
    }

    #[Test]
    public function it_allows_disabling_once_the_dependent_module_is_off(): void
    {
        $decision = $this->resolver([
            $this->module('fleet'),
            $this->module('taskcards', requires: ['fleet']),
        ])->canDisable('fleet', ['fleet']);

        $this->assertTrue($decision->allowed);
    }

    #[Test]
    public function it_handles_a_diamond_without_duplicating(): void
    {
        //     d
        //    / \
        //   b   c
        //    \ /
        //     a
        $resolver = $this->resolver([
            $this->module('a'),
            $this->module('b', requires: ['a']),
            $this->module('c', requires: ['a']),
            $this->module('d', requires: ['b', 'c']),
        ]);

        $closure = $resolver->requirementClosure('d');

        $this->assertEqualsCanonicalizing(['a', 'b', 'c', 'd'], $closure);
        $this->assertSame(count($closure), count(array_unique($closure)));
    }

    #[Test]
    public function it_reports_a_circular_dependency_instead_of_looping_forever(): void
    {
        $resolver = $this->resolver([
            $this->module('a', requires: ['b']),
            $this->module('b', requires: ['a']),
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/[Cc]ircular/');

        $resolver->requirementClosure('a');
    }

    #[Test]
    public function it_warns_when_two_modules_provide_the_same_capability(): void
    {
        // Two identity providers side by side are allowed -- switching from
        // Vereinsflieger to Active Directory would otherwise be a hard cut --
        // but member data then arrives from two sources.
        $decision = $this->resolver([
            $this->module('vf-identity', provides: ['identity-provider']),
            $this->module('ldap-identity', provides: ['identity-provider']),
        ])->canEnable('ldap-identity', ['vf-identity']);

        $this->assertTrue($decision->allowed, 'Two identity providers must be permitted.');
        $this->assertCount(1, $decision->warnings);
        $this->assertSame('identity-provider', $decision->warnings[0]->capability);
        $this->assertEqualsCanonicalizing(
            ['Vf-identity', 'Ldap-identity'],
            $decision->warnings[0]->moduleTitles,
        );
    }

    #[Test]
    public function it_does_not_warn_for_a_single_provider(): void
    {
        $decision = $this->resolver([
            $this->module('vf-identity', provides: ['identity-provider']),
            $this->module('ldap-identity', provides: ['identity-provider']),
        ])->canEnable('vf-identity', []);

        $this->assertTrue($decision->allowed);
        $this->assertSame([], $decision->warnings);
    }

    #[Test]
    public function it_rejects_a_manifest_referring_to_a_module_that_does_not_ship(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/not part of this release/');

        $this->resolver([
            $this->module('taskcards', requires: ['fleet']),
        ]);
    }

    /**
     * @param  list<AeronanceModule>  $modules
     */
    private function resolver(array $modules): DependencyResolver
    {
        return new DependencyResolver(new ModuleRegistry($modules));
    }

    /**
     * @param  list<string>  $requires
     * @param  list<string>  $conflicts
     * @param  list<string>  $provides
     */
    private function module(string $name, array $requires = [], array $conflicts = [], array $provides = []): AeronanceModule
    {
        return new class($name, $requires, $conflicts, $provides) implements AeronanceModule
        {
            /**
             * @param  list<string>  $requires
             * @param  list<string>  $conflicts
             * @param  list<string>  $provides
             */
            public function __construct(
                private readonly string $name,
                private readonly array $requires,
                private readonly array $conflicts,
                private readonly array $provides,
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
                    conflicts: $this->conflicts,
                    provides: $this->provides,
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
