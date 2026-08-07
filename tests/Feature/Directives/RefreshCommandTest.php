<?php

declare(strict_types=1);

namespace Tests\Feature\Directives;

use App\Core\Modules\ModuleManager;
use App\Models\User;
use App\Modules\Directives\Enums\DirectiveKind;
use App\Modules\Directives\Enums\SubjectKind;
use App\Modules\Directives\Permissions;
use App\Modules\Directives\Sources\DirectiveRow;
use App\Modules\Directives\Sources\DirectiveSource;
use App\Modules\Directives\Sources\SourceRegistry;
use App\Modules\Directives\Sources\UnknownType;
use App\Modules\Fleet\Models\Aircraft;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * The weekly fetch.
 *
 * What the tests below are actually guarding is one sentence: a refresh that
 * reports success while having covered three of five manufacturers is worse
 * than one that fails outright. A club reads "done" and believes it is current.
 *
 * So every case where a source does not run has to end up on screen -- missing
 * credentials, a server that threw, a manufacturer with nothing new. And the
 * failure of one must never stop the others, because the alternative is a
 * single unreachable website blocking the whole fleet's overview.
 */
final class RefreshCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Permission::findOrCreate(Permissions::DIRECTIVES_MANAGE, 'web');
        app(ModuleManager::class)->enable('fleet');
        app(ModuleManager::class)->enable('directives');
    }

    #[Test]
    public function it_declines_politely_when_the_module_is_off(): void
    {
        app(ModuleManager::class)->disable('directives');

        // Not an error: the scheduler entry exists whether or not a club uses
        // the module, and a nightly failure mail for a switched-off feature is
        // how people learn to ignore failure mails.
        $this->artisan('aeronance:refresh-directives')
            ->expectsOutputToContain('nicht aktiv')
            ->assertSuccessful();
    }

    #[Test]
    public function it_refuses_to_run_without_an_account_to_write_under(): void
    {
        // No user holds directives.manage. The import would be an anonymous
        // write into an audit trail, which is not a thing this application does.
        $this->artisan('aeronance:refresh-directives')
            ->expectsOutputToContain('directives.manage')
            ->assertFailed();
    }

    #[Test]
    public function a_source_that_throws_does_not_stop_the_others(): void
    {
        $this->manager();
        Aircraft::create(['registration' => 'D-KABC', 'model' => 'ASK 21', 'is_active' => true]);

        $registry = app(SourceRegistry::class);
        $registry->register($this->source('kaputt', fn () => throw new RuntimeException('Server weg')));
        $registry->register($this->source('heil', fn (): array => [$this->row('TM 1', 'Beschlag prüfen')]));

        $this->artisan('aeronance:refresh-directives', ['--source' => ['kaputt', 'heil']])
            // Named, with the reason, rather than counted -- "4 von 5 Quellen"
            // tells nobody which one to go and look at.
            ->expectsOutputToContain('Server weg')
            ->expectsOutputToContain('heil')
            ->assertSuccessful();

        $this->assertDatabaseHas('directives', ['number' => 'TM 1', 'source' => 'heil']);
    }

    #[Test]
    public function a_manufacturer_who_does_not_build_that_aircraft_is_not_a_warning(): void
    {
        $this->manager();
        Aircraft::create(['registration' => 'D-KABC', 'model' => 'ASK 21', 'is_active' => true]);

        app(SourceRegistry::class)->register($this->source(
            'dg',
            fn () => throw new UnknownType('DG kennt kein Muster "ASK 21".'),
        ));

        /*
         * Every source is asked about every type the club flies, so Schleicher
         * gets asked about a DG-300 every week and DG about an ASK 21. Both
         * answer correctly that they do not build one.
         *
         * If that read as a warning, a weekly run would produce a dozen of them
         * and nobody would read the one that mattered.
         */
        $this->artisan('aeronance:refresh-directives', ['--source' => ['dg']])
            ->expectsOutputToContain('nicht zuständig')
            ->doesntExpectOutputToContain('übersprungen')
            ->assertSuccessful();
    }

    #[Test]
    public function what_it_brings_in_is_unassessed_and_says_so(): void
    {
        $this->manager();
        Aircraft::create(['registration' => 'D-KABC', 'model' => 'ASK 21', 'is_active' => true]);

        app(SourceRegistry::class)->register($this->source('heil', fn (): array => [
            $this->row('TM 2', 'Ruderanschluss tauschen'),
        ]));

        /*
         * The point of the whole module. A machine may notice that a
         * manufacturer published something; only a person may say what it means
         * for an aircraft. The warning exists so nobody reads a green run as
         * "nothing to do".
         */
        $this->artisan('aeronance:refresh-directives', ['--source' => ['heil']])
            ->expectsOutputToContain('unbeurteilt')
            ->assertSuccessful();
    }

    #[Test]
    public function a_dry_run_writes_nothing(): void
    {
        $this->manager();
        Aircraft::create(['registration' => 'D-KABC', 'model' => 'ASK 21', 'is_active' => true]);

        app(SourceRegistry::class)->register($this->source('heil', function (): array {
            $this->fail('Ein Probelauf darf die Quelle nicht abrufen.');
        }));

        $this->artisan('aeronance:refresh-directives', ['--source' => ['heil'], '--dry-run' => true])
            ->assertSuccessful();

        $this->assertDatabaseCount('directives', 0);
    }

    #[Test]
    public function it_asks_only_about_the_types_the_club_actually_flies(): void
    {
        $this->manager();
        Aircraft::create(['registration' => 'D-KABC', 'model' => 'ASK 21', 'is_active' => true]);
        Aircraft::create(['registration' => 'D-KXYZ', 'model' => 'ASW 19', 'is_active' => false]);

        $asked = [];

        app(SourceRegistry::class)->register($this->source('heil', function (array $options) use (&$asked): array {
            $asked[] = $options['model'] ?? null;

            return [];
        }));

        $this->artisan('aeronance:refresh-directives', ['--source' => ['heil']])->assertSuccessful();

        // A manufacturer has forty types and a club flies one. Fetching the
        // other thirty-nine every week is rude to somebody else's server -- and
        // the retired ASW 19 is not asked about either.
        $this->assertSame(['ASK 21'], $asked);
    }

    /** One line as a manufacturer would deliver it. */
    private function row(string $number, string $title): DirectiveRow
    {
        return new DirectiveRow(
            number: $number,
            title: $title,
            kind: DirectiveKind::Tm,
            subjectKind: SubjectKind::AircraftModel,
            subjectModel: 'ASK 21',
        );
    }

    private function manager(): User
    {
        return tap(User::factory()->create(['is_active' => true]))
            ->givePermissionTo(Permissions::DIRECTIVES_MANAGE);
    }

    /**
     * A stand-in manufacturer.
     *
     * @param  callable(array<string, mixed>): list<DirectiveRow>  $fetch
     */
    private function source(string $name, callable $fetch): DirectiveSource
    {
        return new class($name, $fetch) implements DirectiveSource
        {
            /** @param callable(array<string, mixed>): list<DirectiveRow> $fetch */
            public function __construct(private string $name, private $fetch) {}

            public function name(): string
            {
                return $this->name;
            }

            public function label(): string
            {
                return $this->name;
            }

            public function isAutomatic(): bool
            {
                return true;
            }

            public function fetch(array $options = []): array
            {
                return ($this->fetch)($options);
            }
        };
    }
}
