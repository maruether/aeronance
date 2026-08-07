<?php

declare(strict_types=1);

namespace Tests\Feature\Directives;

use App\Core\Http\HttpFetcher;
use App\Modules\Directives\Enums\Bindingness;
use App\Modules\Directives\Sources\Configured\ConfiguredSource;
use App\Modules\Directives\Sources\Configured\SourceSpec;
use App\Modules\Directives\Sources\DirectiveRow;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Yaml\Yaml;
use Tests\TestCase;

/**
 * Schempp-Hirth -- a table behind a session login.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * The third way a manufacturer gates its list, and the pleasant surprise was how
 * little of it is new: once past the login it is an ORDINARY TABLE, on ONE page,
 * for every type at once. So mode three turned out to be the existing table mode
 * plus a session -- not a third parser.
 *
 * The fixture is the real table, trimmed to its first 40 rows and stripped of
 * navigation and the account area. The rows themselves are manufacturer data;
 * the account area is not, and a customer-gated page is exactly where one checks
 * before committing anything.
 * ─────────────────────────────────────────────────────────────────────────────
 */
final class SessionSourceTest extends TestCase
{
    private function spec(): SourceSpec
    {
        return SourceSpec::fromArray(
            Yaml::parseFile(resource_path('directive-sources/schempp-hirth.yaml')),
            'schempp-hirth.yaml',
        );
    }

    /** @return list<DirectiveRow> */
    private function rows(): array
    {
        return (new ConfiguredSource($this->spec(), new SchemppStubFetcher))
            ->parseTypePage($this->fixture(), '');
    }

    private function fixture(): string
    {
        return file_get_contents(base_path('tests/Fixtures/SchemppHirth/tm-liste.html'));
    }

    // ── The table behind the login ──────────────────────────────────────────

    #[Test]
    public function the_real_table_yields_rows(): void
    {
        $rows = $this->rows();

        $this->assertGreaterThan(20, count($rows));
        $this->assertContainsOnlyInstancesOf(DirectiveRow::class, $rows);
    }

    #[Test]
    public function a_row_carries_its_number_subject_and_date(): void
    {
        $first = $this->rows()[0];

        $this->assertNotSame('', $first->number);
        $this->assertNotSame('', $first->title);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}$/', (string) $first->issuedAt);
    }

    #[Test]
    public function the_separate_date_column_is_read(): void
    {
        /*
         * Schleicher puts number and date in one cell; Schempp-Hirth gives the
         * date its own. Reading the number cell for a date that is not there
         * simply yielded null before, so this is an addition -- and the Schleicher
         * spec is untouched by it, which its own tests still prove.
         */
        $withDate = array_filter($this->rows(), fn (DirectiveRow $r): bool => $r->issuedAt !== null);

        $this->assertGreaterThan(20, count($withDate));
    }

    #[Test]
    public function the_header_row_is_not_mistaken_for_a_note(): void
    {
        foreach ($this->rows() as $row) {
            $this->assertStringNotContainsStringIgnoringCase('TM Nummer', $row->number);
            $this->assertStringNotContainsStringIgnoringCase('Betreff', $row->title);
        }
    }

    #[Test]
    public function the_domain_rules_are_unchanged(): void
    {
        foreach ($this->rows() as $row) {
            // Never lifted out of prose, never inferred -- as everywhere else.
            $this->assertNull($row->complyBefore, $row->number);
            $this->assertFalse($row->isRecurring, $row->number);
        }

        $spec = $this->spec();
        $this->assertSame(Bindingness::Mandatory, $spec->bindingnessFor(null, 'unbekannte Wendung'));
        $this->assertSame(Bindingness::Optional, $spec->bindingnessFor(null, 'wahlweise'));
    }

    // ── The spec's shape ────────────────────────────────────────────────────

    #[Test]
    public function it_is_a_single_page_source_with_no_index(): void
    {
        // 458 notes for every type on one page -- asking per type would be the
        // same page fetched over and over.
        $spec = $this->spec();

        $this->assertTrue($spec->isSinglePage());
        $this->assertSame('', $spec->linkPattern, 'No index means no link pattern.');
    }

    #[Test]
    public function it_declares_a_login_and_names_its_credentials_elsewhere(): void
    {
        $spec = $this->spec();

        $this->assertTrue($spec->needsLogin());
        $this->assertSame('schempp', $spec->authProfile);

        $yaml = file_get_contents(resource_path('directive-sources/schempp-hirth.yaml'));
        $this->assertStringNotContainsString('password:', $yaml);
    }

    #[Test]
    public function a_success_pattern_guards_against_a_silent_failed_login(): void
    {
        /*
         * Without it a wrong password gives the login page back with HTTP 200 and
         * no rows -- an import that looks exactly like a manufacturer who
         * published nothing.
         */
        $this->assertNotNull($this->spec()->loginSuccessPattern);
        $this->assertSame(1, preg_match((string) $this->spec()->loginSuccessPattern, 'Logout'));
    }

    #[Test]
    public function no_spec_ever_switches_verification_off(): void
    {
        /*
         * Schempp-Hirth's server omits the RapidSSL intermediate, and a strict
         * client cannot verify it. There are two ways to make that go away, and
         * they look almost identical in a config file: COMPLETE the chain, or
         * turn verification OFF. They are opposites, and only the first is
         * allowed here.
         *
         * The chain is completed automatically now (CertificateChainResolver),
         * so the spec names no bundle at all -- the manual file is gone. What
         * this test guards is the line nobody may ever cross: no spec carries a
         * tls.verify=false or tls.insecure, in ANY file, however tempting it is
         * when a manufacturer's certificate is the thing standing in the way.
         */
        $this->assertNull(
            $this->spec()->caBundle,
            'Schempp needs no manual bundle any more -- the chain is completed automatically.',
        );

        foreach (glob(resource_path('directive-sources/*.yaml')) as $file) {
            $tls = Yaml::parseFile($file)['tls'] ?? [];

            $this->assertArrayNotHasKey('verify', $tls, basename($file));
            $this->assertArrayNotHasKey('insecure', $tls, basename($file));
        }
    }

    #[Test]
    public function every_shipped_spec_still_declares_a_known_mode(): void
    {
        foreach (glob(resource_path('directive-sources/*.yaml')) as $file) {
            $spec = SourceSpec::fromArray(Yaml::parseFile($file), basename($file));

            // Extended when the list mode arrived, again when the overview
            // sheet became the regular way, and again for the portal mode
            // Diamond needed -- which is what this test is for: a new mode must
            // not slip in unnoticed.
            $this->assertContains(
                $spec->type,
                [
                    SourceSpec::TYPE_TABLE,
                    SourceSpec::TYPE_AURA,
                    SourceSpec::TYPE_JSON,
                    SourceSpec::TYPE_LIST,
                    SourceSpec::TYPE_OVERVIEW,
                ],
                basename($file),
            );
        }
    }
}

final class SchemppStubFetcher implements HttpFetcher
{
    public function get(string $url, array $headers = []): string
    {
        return file_get_contents(base_path('tests/Fixtures/SchemppHirth/tm-liste.html'));
    }
}
