<?php

declare(strict_types=1);

namespace Tests\Feature\Directives;

use App\Core\Http\FormFetcher;
use App\Core\Http\HttpFetcher;
use App\Core\Models\Qualification;
use App\Models\User;
use App\Modules\Directives\Actions\AssessDirective;
use App\Modules\Directives\Actions\ImportDirectives;
use App\Modules\Directives\Enums\ComplianceState;
use App\Modules\Directives\Enums\DirectiveKind;
use App\Modules\Directives\Enums\SubjectKind;
use App\Modules\Directives\Models\Directive;
use App\Modules\Directives\Permissions;
use App\Modules\Directives\Sources\Configured\ConfiguredSource;
use App\Modules\Directives\Sources\Configured\SourceSpec;
use App\Modules\Directives\Sources\CsvSource;
use App\Modules\Directives\Sources\SourceRegistry;
use App\Modules\Fleet\Models\Aircraft;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Spatie\Permission\Models\Permission;
use Symfony\Component\Yaml\Yaml;
use Tests\TestCase;

/**
 * Bringing a list in, and what an import must never do.
 *
 * Vorgabe: "Die Übersichtsliste ändert sich herstellerseitig nicht oder wird
 * länger." So the importer only ever adds or updates -- a list that silently
 * loses lines is worse than one that grows, because nobody notices the loss.
 */
final class ImportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        foreach ([Permissions::DIRECTIVES_MANAGE, Permissions::DIRECTIVES_ASSESS] as $p) {
            Permission::findOrCreate($p, 'web');
        }

        // The module's boot() does this in the app; done here too because the
        // test panel does not necessarily boot every module.
        app(SourceRegistry::class)->register(new CsvSource);
    }

    #[Test]
    public function a_semicolon_list_with_a_header_comes_in(): void
    {
        $csv = <<<'CSV'
        Nummer;Titel;Datum;Frist;Muster
        LTA 2026-005;Beschlag Höhenruder prüfen;14.03.2026;01.09.2026;ASK 21
        LTA 2026-006;Ruderanschluss tauschen;2026-04-01;;ASK 21
        CSV;

        $result = app(ImportDirectives::class)->fromSource('csv', $this->manager(), ['body' => $csv]);

        $this->assertSame(2, $result['created']);

        $first = Directive::where('number', 'LTA 2026-005')->sole();
        $this->assertSame('Beschlag Höhenruder prüfen', $first->title);
        $this->assertSame('2026-03-14', $first->issued_at->toDateString());
        $this->assertSame('2026-09-01', $first->comply_before->toDateString());
        $this->assertSame('ASK 21', $first->subject_model);
        $this->assertSame('csv', $first->source);
    }

    #[Test]
    public function a_comma_list_works_too(): void
    {
        // German Excel produces semicolons, everything else commas. Guessing
        // wrong turns the whole file into one column, and it is the first thing
        // that goes wrong in practice.
        $csv = "Number,Title\nAD-2026-11,Inspect wing attachment";

        $result = app(ImportDirectives::class)->fromSource('csv', $this->manager(), [
            'body' => $csv, 'kind' => 'ad',
        ]);

        $this->assertSame(1, $result['created']);
        $this->assertSame(DirectiveKind::Ad, Directive::sole()->kind);
    }

    #[Test]
    public function a_list_without_a_header_is_read_positionally(): void
    {
        $csv = 'LTA 2026-007;Sichtprüfung Bremsklappen;01.05.2026';

        app(ImportDirectives::class)->fromSource('csv', $this->manager(), ['body' => $csv]);

        $d = Directive::sole();
        $this->assertSame('LTA 2026-007', $d->number);
        $this->assertSame('Sichtprüfung Bremsklappen', $d->title);
        $this->assertSame('2026-05-01', $d->issued_at->toDateString());
    }

    #[Test]
    public function lines_without_a_number_or_title_are_skipped(): void
    {
        // A blank imported row is a line somebody has to assess for nothing.
        $csv = "Nummer;Titel\nLTA-1;Echte Zeile\n;Ohne Nummer\nLTA-3;\n;;";

        $result = app(ImportDirectives::class)->fromSource('csv', $this->manager(), ['body' => $csv]);

        $this->assertSame(1, $result['created']);
    }

    #[Test]
    public function an_unparseable_date_stays_empty_rather_than_being_guessed(): void
    {
        // A wrong deadline is worse than an empty one, because an empty field is
        // visible.
        $csv = "Nummer;Titel;Frist\nLTA-1;Prüfung;irgendwann im Frühjahr";

        app(ImportDirectives::class)->fromSource('csv', $this->manager(), ['body' => $csv]);

        $this->assertNull(Directive::sole()->comply_before);
    }

    #[Test]
    public function an_empty_list_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        app(ImportDirectives::class)->fromSource('csv', $this->manager(), ['body' => "   \n\n"]);
    }

    #[Test]
    public function re_importing_updates_and_never_removes(): void
    {
        // The rule: a row that has vanished from the manufacturer's file is not
        // deleted here. A shortened export and a broken parser look identical.
        app(ImportDirectives::class)->fromSource('csv', $this->manager(), [
            'body' => "Nummer;Titel\nLTA-1;Alter Titel\nLTA-2;Zweite Zeile",
        ]);

        $result = app(ImportDirectives::class)->fromSource('csv', $this->manager(), [
            'body' => "Nummer;Titel\nLTA-1;Neuer Titel",
        ]);

        $this->assertSame(0, $result['created']);
        $this->assertSame(1, $result['updated']);
        $this->assertSame('Neuer Titel', Directive::where('number', 'LTA-1')->sole()->title);
        $this->assertSame(2, Directive::count(), 'LTA-2 stays.');
    }

    #[Test]
    public function an_unchanged_row_is_reported_as_unchanged(): void
    {
        $body = "Nummer;Titel\nLTA-1;Gleicher Titel";

        app(ImportDirectives::class)->fromSource('csv', $this->manager(), ['body' => $body]);
        $result = app(ImportDirectives::class)->fromSource('csv', $this->manager(), ['body' => $body]);

        $this->assertSame(['created' => 0, 'updated' => 0, 'unchanged' => 1], [
            'created' => $result['created'],
            'updated' => $result['updated'],
            'unchanged' => $result['unchanged'],
        ]);
    }

    #[Test]
    public function an_import_never_touches_a_hand_typed_line(): void
    {
        // Why ManualSource exists as a source rather than as the absence of one:
        // a manufacturer refresh must not overwrite a line somebody typed, even
        // one with the same number.
        $typed = Directive::create([
            'source' => 'manual',
            'number' => 'LTA-1',
            'title' => 'Von Hand erfasst',
            'kind' => DirectiveKind::Lta,
            'subject_kind' => SubjectKind::AircraftModel,
        ]);

        app(ImportDirectives::class)->fromSource('csv', $this->manager(), [
            'body' => "Nummer;Titel\nLTA-1;Aus der Herstellerliste",
        ]);

        $this->assertSame('Von Hand erfasst', $typed->fresh()->title);
        $this->assertSame(2, Directive::count(), 'Same number, different sources, two rows.');
    }

    #[Test]
    public function an_import_never_touches_an_assessment(): void
    {
        // The whole reason directives and applications are two tables.
        app(ImportDirectives::class)->fromSource('csv', $this->manager(), [
            'body' => "Nummer;Titel\nLTA-1;Prüfung",
        ]);

        $directive = Directive::sole();
        $aircraft = Aircraft::create(['registration' => 'D-KABC', 'model' => 'ASK 21']);

        app(AssessDirective::class)->comply($directive, $aircraft, $this->inspector(), 'Gemacht');

        app(ImportDirectives::class)->fromSource('csv', $this->manager(), [
            'body' => "Nummer;Titel\nLTA-1;Prüfung, überarbeitet",
        ]);

        $application = app(AssessDirective::class)->applicationFor($directive->fresh(), $aircraft);

        $this->assertSame(ComplianceState::Complied, $application->state);
        $this->assertSame('Gemacht', $application->method);
    }

    #[Test]
    public function a_newer_directive_supersedes_an_older_one_without_deleting_it(): void
    {
        // The record has to show that the old one was dealt with, and by what.
        app(ImportDirectives::class)->fromSource('csv', $this->manager(), [
            'body' => "Nummer;Titel\nLTA-1;Erste Fassung\nLTA-2;Zweite Fassung",
        ]);

        $old = Directive::where('number', 'LTA-1')->sole();
        $new = Directive::where('number', 'LTA-2')->sole();

        app(ImportDirectives::class)->supersede($old, $new, $this->manager());

        $this->assertTrue($old->fresh()->isSuperseded());
        $this->assertSame($new->id, $old->fresh()->supersededBy->id);
        $this->assertSame(2, Directive::count());
        $this->assertSame(1, Directive::current()->count());
    }

    #[Test]
    public function a_directive_cannot_supersede_itself_or_form_a_loop(): void
    {
        app(ImportDirectives::class)->fromSource('csv', $this->manager(), [
            'body' => "Nummer;Titel\nLTA-1;Erste\nLTA-2;Zweite",
        ]);

        $a = Directive::where('number', 'LTA-1')->sole();
        $b = Directive::where('number', 'LTA-2')->sole();

        try {
            app(ImportDirectives::class)->supersede($a, $a, $this->manager());
            $this->fail('Self-supersession must be refused.');
        } catch (RuntimeException) {
        }

        app(ImportDirectives::class)->supersede($a, $b, $this->manager());

        $this->expectException(RuntimeException::class);
        app(ImportDirectives::class)->supersede($b->fresh(), $a->fresh(), $this->manager());
    }

    #[Test]
    public function importing_needs_the_manage_permission(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/permission/');

        app(ImportDirectives::class)->fromSource('csv', $this->userWith(), [
            'body' => "Nummer;Titel\nLTA-1;Prüfung",
        ]);
    }

    #[Test]
    public function the_same_number_twice_in_one_list_is_reported_not_swallowed(): void
    {
        /*
         * Schleicher's Rotax page lists TM SB-2ST-000 twice -- once for the 275
         * series, once for the 505 -- with different dates. Both are real.
         *
         * A directive is keyed by source and number, so the second row would
         * land in the update branch and overwrite the first. The counts would
         * read "1 neu, 1 aktualisiert", which is true and completely hides that
         * a directive vanished. That is the failure this module exists to
         * prevent, arriving through the front door.
         *
         * Nothing is merged and no number is invented to tell them apart --
         * there is no honest way to derive one from the page. The first is
         * kept, the rest are named.
         */
        $csv = <<<'CSV'
        Nummer;Titel;Datum
        TM SB-2ST-000;Verzeichnis der gültigen Dokumentationen;19.01.2015
        TM SB-2ST-000;Verzeichnis der gültigen Dokumentationen;19.01.2011
        TM SB-275;Neuer Wartungsplan;04.05.2007
        CSV;

        $result = app(ImportDirectives::class)->fromSource('csv', $this->manager(), ['body' => $csv]);

        $this->assertSame(2, $result['created']);
        $this->assertSame(0, $result['updated']);
        $this->assertSame(['TM SB-2ST-000'], $result['collisions']);

        // The FIRST one survives, not the last -- so a second run over an
        // unchanged page produces the same database, rather than alternating.
        $this->assertSame(
            '2015-01-19',
            Directive::where('number', 'TM SB-2ST-000')->sole()->issued_at->toDateString(),
        );
    }

    #[Test]
    public function the_registry_knows_which_sources_can_run_unattended(): void
    {
        $registry = app(SourceRegistry::class);

        // Manual entry and a pasted CSV are the two that need a person in front
        // of them -- a scheduler must never pick either up.
        $this->assertTrue($registry->has('csv'));
        $this->assertFalse($registry->get('csv')->isAutomatic());
        $this->assertFalse($registry->get('manual')->isAutomatic());

        $automatic = array_keys($registry->automatic());

        $this->assertNotContains('csv', $automatic);
        $this->assertNotContains('manual', $automatic);

        // Every shipped manufacturer file is one. Named individually rather than
        // counted: a count passes just as happily when a spec has silently
        // stopped loading, which is this module's whole failure mode.
        $this->assertContains('schleicher', $automatic);
    }

    #[Test]
    public function the_sources_are_available_outside_a_panel(): void
    {
        /*
         * The registry used to be filled by the Filament plugin's boot(), which
         * meant it was populated for browser requests and empty for everything
         * else -- so the scheduled refresh reported "no source available" while
         * looking straight at the YAML files. Sources belong to the application,
         * not to the panel.
         *
         * This test would have caught it: it never boots a panel.
         */
        $this->assertNotSame([], app(SourceRegistry::class)->automatic());
    }

    private ?User $managerUser = null;

    private ?User $inspectorUser = null;

    private function manager(): User
    {
        return $this->managerUser ??= $this->userWith(Permissions::DIRECTIVES_MANAGE);
    }

    private function inspector(): User
    {
        if ($this->inspectorUser !== null) {
            return $this->inspectorUser->fresh();
        }

        $user = $this->userWith(Permissions::DIRECTIVES_ASSESS);

        Qualification::create([
            'user_id' => $user->id,
            'type' => Qualification::TYPE_PART66,
            'reference' => 'DE.66.12345',
            'category' => 'B1',
            'valid_from' => now()->subYear()->toDateString(),
        ]);

        return $this->inspectorUser = $user->fresh();
    }

    #[Test]
    public function a_range_wide_ceapr_bulletin_survives_the_import_unclipped(): void
    {
        /*
         * Feldtest, aus dem echten Fehler: SB 090702 gilt für die komplette
         * Robin-Palette -- 135+ Zeichen Musteraufzählung, und subject_model
         * war 96 breit. Der Import starb mit "Data too long", und zwar für
         * die GANZE Quelle. Das Fixture ist die echte Liste; die Zeile muss
         * VOLLSTÄNDIG ankommen -- gekürzt hieße still Muster verlieren.
         */
        app(SourceRegistry::class)->register(new ConfiguredSource(
            SourceSpec::fromArray(
                Yaml::parseFile(resource_path('directive-sources/ceapr.yaml')),
                'ceapr.yaml',
            ),
            new SavedCeaprList(__DIR__.'/../../Fixtures/Ceapr/ls-bs-liste.json'),
        ));

        app(ImportDirectives::class)->fromSource(
            'ceapr',
            $this->userWith(Permissions::DIRECTIVES_MANAGE),
        );

        $sammel = Directive::query()->where('number', 'SB 090702')->firstOrFail();

        $this->assertGreaterThan(96, mb_strlen((string) $sammel->subject_model));
        $this->assertStringContainsString('DR380', (string) $sammel->subject_model);
    }

    #[Test]
    public function the_kind_is_read_from_the_number_where_it_names_itself(): void
    {
        /*
         * Feldtest: "koennen wir beim import auf die 'art' verzichten und das
         * automatisch rausfinden?" Wo die Nummer die Art selbst nennt: ja --
         * das ist das eigene Wort des Dokuments, keine Raterei. Ohne Kuerzel
         * gilt der gewaehlte Vorgabewert.
         */
        $rows = (new CsvSource)->fetch([
            'body' => implode("\n", [
                'TM 300/12;Hoehenruderanlenkung pruefen',
                'SB 090702;Installation of battery',
                'AD 2020-15;Spar inspection',
                'LTA 03-001;Bruchrippe',
                '72-123;Ohne Kuerzel -- nimmt den Vorgabewert',
            ]),
            'kind' => 'tm',
        ]);

        $this->assertSame(
            ['tm', 'sb', 'ad', 'lta', 'tm'],
            array_map(fn ($r): string => $r->kind->value, $rows),
        );
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

/**
 * Die gespeicherte C.E.A.P.R.-Liste als Fetcher -- eigener Stub statt des
 * SavedFile aus PoweredAircraftTest, weil Klassen in Testdateien nur laden,
 * wenn ihre Datei laeuft (ein --filter auf diese Datei fande sie sonst nicht).
 */
final class SavedCeaprList implements FormFetcher, HttpFetcher
{
    public function __construct(private string $path) {}

    public function get(string $url, array $headers = []): string
    {
        return (string) file_get_contents($this->path);
    }

    public function post(string $url, array $form, array $headers = []): string
    {
        return (string) file_get_contents($this->path);
    }
}
