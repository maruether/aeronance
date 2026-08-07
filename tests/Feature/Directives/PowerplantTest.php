<?php

declare(strict_types=1);

namespace Tests\Feature\Directives;

use App\Core\Http\HttpFetcher;
use App\Modules\Directives\Enums\DirectiveKind;
use App\Modules\Directives\Enums\SubjectKind;
use App\Modules\Directives\Sources\Configured\ConfiguredSource;
use App\Modules\Directives\Sources\Configured\SourceSpec;
use App\Modules\Directives\Sources\DirectiveRow;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Yaml\Yaml;
use Tests\TestCase;

/**
 * Engines and propellers -- the second class of source.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * With motor gliders and powered aircraft the powerplant becomes a source in its
 * own right: a motor has its own notes, its own running times and its own
 * airworthiness directives, independent of the airframe it hangs under. The
 * three Schleicher files only ever read foreign engine notes because Schleicher
 * republishes them.
 *
 * The last test in this file is the one that matters most: every one of these
 * sources was once filed as an AIRCRAFT model, because subject_kind fell back
 * silently when the value was misspelt.
 * ─────────────────────────────────────────────────────────────────────────────
 */
final class PowerplantTest extends TestCase
{
    #[Test]
    public function rotax_reads_the_whole_document_search(): void
    {
        $rows = $this->rows('rotax', 'Rotax/dokumentensuche.html');

        $this->assertCount(490, $rows);
        $this->assertSame('SI-05-1998', $rows[0]->number);
        $this->assertSame('1998-06-01', $rows[0]->issuedAt);
    }

    #[Test]
    public function rotax_keeps_the_manufacturers_own_designation(): void
    {
        /*
         * The number already says what it is. Prefixing the kind would yield
         * "SB ASB-2026-001" -- a designation nobody at Rotax would recognise and
         * that no longer matches the document it names.
         */
        $rows = $this->rows('rotax', 'Rotax/dokumentensuche.html');

        $prefixed = array_filter(
            $rows,
            static fn (DirectiveRow $r): bool => str_starts_with($r->number, 'SB SB-')
                || str_starts_with($r->number, 'SB ASB-')
                || str_starts_with($r->number, 'SB SI-'),
        );

        $this->assertSame([], $prefixed);
    }

    #[Test]
    public function rotax_takes_nothing_from_the_second_table(): void
    {
        /*
         * Beside the result table stands another with identical columns, headed
         * "this engine/engine type might ALSO be affected by following
         * documents". A table pattern not anchored on topTable mixes the two,
         * and foreign documents end up filed as directives against the type.
         *
         * The fixture carries both tables, so this counts what anchoring saves.
         */
        $page = (string) file_get_contents(__DIR__.'/../../Fixtures/Rotax/dokumentensuche.html');

        $this->assertStringContainsString('bottomTable', $page, 'Die Fixture muss die zweite Tabelle enthalten.');

        $rows = $this->rows('rotax', 'Rotax/dokumentensuche.html');

        $this->assertCount(490, $rows);
    }

    #[Test]
    public function limbach_reads_the_technical_notes_only(): void
    {
        /*
         * Eight tables share one page and are told apart by the ROW class, not
         * by the table: technicalBulletins (51) beside maintenanceInstructions
         * (15). The maintenance instructions are not directives.
         */
        $rows = $this->rows('limbach', 'Limbach/downloads.html');

        $this->assertCount(51, $rows);
        $this->assertSame('TM 5.0', $rows[0]->number);
    }

    #[Test]
    public function limbach_reads_the_mixed_language_date(): void
    {
        // "23. Mar 2006" -- German day, English month. Unambiguous because a
        // month name cannot be mistaken for a day.
        $rows = $this->rows('limbach', 'Limbach/downloads.html');

        $this->assertSame('2006-03-23', $rows[0]->issuedAt);

        $undated = array_filter($rows, static fn (DirectiveRow $r): bool => blank($r->issuedAt));
        $this->assertSame([], $undated);
    }

    #[Test]
    public function solo_tells_the_authoritys_directives_from_its_own(): void
    {
        /*
         * SOLO lists its own notes and the EASA directives for the same engines
         * in ONE table, distinguished only by how the number reads. Without
         * authority_number_pattern twelve airworthiness directives sit in the
         * record as technical notes -- and the difference between the two is the
         * difference between "must" and "may".
         */
        $rows = $this->rows('solo', 'Solo/downloads.html');

        $this->assertCount(61, $rows);

        $ads = array_filter($rows, static fn (DirectiveRow $r): bool => $r->kind === DirectiveKind::Ad);
        $this->assertCount(12, $ads);

        $tms = array_filter($rows, static fn (DirectiveRow $r): bool => $r->kind === DirectiveKind::Tm);
        $this->assertCount(49, $tms);
    }

    #[Test]
    public function mt_propeller_leaves_its_own_index_out_of_the_list(): void
    {
        /*
         * The first row of the bulletin table is the LIST of bulletins. Filed as
         * a directive it becomes an open point nobody can carry out.
         */
        $rows = $this->rows('mt-propeller', 'MtPropeller/service-bulletins.html');

        $this->assertCount(42, $rows);

        $index = array_filter(
            $rows,
            static fn (DirectiveRow $r): bool => str_contains(strtolower($r->number), 'list'),
        );

        $this->assertSame([], $index);
    }

    #[Test]
    public function mt_propeller_keeps_the_file_size_out_of_the_number(): void
    {
        /*
         * The number cell carries it ("1R12 (.pdf, 1550k)"), and the size
         * changes with every revision -- so the number would change with it and
         * the import would file a second directive instead of updating the
         * first.
         */
        $rows = $this->rows('mt-propeller', 'MtPropeller/service-bulletins.html');

        $this->assertSame('SB 1R12', $rows[0]->number);
        $this->assertSame('2026-06-10', $rows[0]->issuedAt);

        $withSize = array_filter(
            $rows,
            static fn (DirectiveRow $r): bool => str_contains($r->number, '.pdf'),
        );

        $this->assertSame([], $withSize);
    }

    #[Test]
    public function a_powerplant_source_is_never_filed_as_an_airframe(): void
    {
        /*
         * ─────────────────────────────────────────────────────────────────────
         * THE REGRESSION THIS FILE EXISTS FOR.
         *
         * subject_kind was once written as "engine_model" and "component_model",
         * neither of which the enum knows -- and an unknown value fell back to
         * aircraft_model without a word. Five engine sources and one propeller
         * source were filed as AIRFRAMES: the import ran, the counts were right,
         * the specs looked correct, and a Rotax bulletin sat in the fleet as
         * though it belonged to a wing.
         *
         * The loader now refuses an unknown value. This asserts the result.
         * ─────────────────────────────────────────────────────────────────────
         */
        foreach (['rotax', 'limbach', 'solo', 'austro', 'continental'] as $name) {
            $this->assertSame(
                SubjectKind::Engine,
                $this->spec($name)->subjectKind,
                "{$name} muss ein Triebwerk sein, kein Flugzeugmuster.",
            );
        }

        $this->assertSame(SubjectKind::Propeller, $this->spec('mt-propeller')->subjectKind);
    }

    /** @return list<DirectiveRow> */
    private function rows(string $spec, string $fixture): array
    {
        return (new ConfiguredSource(
            $this->spec($spec),
            new SavedPage(__DIR__.'/../../Fixtures/'.$fixture),
        ))->fetch();
    }

    private function spec(string $name): SourceSpec
    {
        return SourceSpec::fromArray(
            Yaml::parseFile(resource_path("directive-sources/{$name}.yaml")),
            "{$name}.yaml",
        );
    }
}

/** One saved page, whatever is asked for. */
final class SavedPage implements HttpFetcher
{
    public function __construct(private string $path) {}

    public function get(string $url, array $headers = []): string
    {
        return (string) file_get_contents($this->path);
    }
}
