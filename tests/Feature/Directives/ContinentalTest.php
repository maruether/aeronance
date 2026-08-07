<?php

declare(strict_types=1);

namespace Tests\Feature\Directives;

use App\Core\Http\FormFetcher;
use App\Core\Http\HttpFetcher;
use App\Modules\Directives\Sources\Configured\ConfiguredSource;
use App\Modules\Directives\Sources\Configured\SourceSpec;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Symfony\Component\Yaml\Yaml;
use Tests\TestCase;

/**
 * Continental: rows delivered as HTML inside a JSON envelope.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * The visible <table id="bulletin-table"> on their page holds nothing but a
 * <thead>. Read as HTML it is empty -- which is indistinguishable from a
 * manufacturer who has published nothing. The rows arrive through an AJAX route
 * that answers {"data": "<tr>…</tr>", "load_more": true}, ten at a time.
 *
 * Two of the three tests here are about the ways that can go quietly wrong.
 * ─────────────────────────────────────────────────────────────────────────────
 */
final class ContinentalTest extends TestCase
{
    #[Test]
    public function it_reads_the_rows_out_of_the_json_envelope(): void
    {
        $rows = (new ConfiguredSource($this->spec(), new ContinentalPages(3)))->fetch();

        $this->assertCount(30, $rows);
        $this->assertSame('CSB26-04', $rows[1]->number);

        // ISO dates -- the cleanest format of any source here.
        $this->assertSame('2026-06-30', $rows[1]->issuedAt);
        $this->assertStringStartsWith('https://continental.aero/', (string) $rows[1]->referenceUrl);
    }

    #[Test]
    public function it_walks_the_pages_with_post_because_get_ignores_them(): void
    {
        /*
         * The same route answers a GET and IGNORES the page parameter: every
         * page comes back as page one. Walked with GET the import stops after
         * ten of 498 bulletins and looks complete.
         *
         * The double refuses a GET outright, so a driver that stopped posting
         * would fail here rather than quietly return a tenth of the list.
         */
        $pages = new ContinentalPages(3);

        $rows = (new ConfiguredSource($this->spec(), $pages))->fetch();

        $this->assertSame(0, $pages->gets, 'Die Seiten müssen per POST geholt werden.');
        $this->assertSame(4, $pages->posts, 'Drei Seiten mit Inhalt, dann die leere.');
        $this->assertCount(30, $rows);
    }

    #[Test]
    public function reaching_the_page_limit_is_an_error_and_not_a_result(): void
    {
        /*
         * ─────────────────────────────────────────────────────────────────────
         * A paged feed that stops at max_pages hands back a list that looks
         * complete and is not. Measured on the real source: with a limit of 40,
         * 400 of 498 bulletins came back with nothing to say that 98 were
         * missing. A short list of binding instructions is worse than no list,
         * because nobody goes looking for what they were not told was absent.
         * ─────────────────────────────────────────────────────────────────────
         */
        $spec = $this->specWithMaxPages(2);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/max_pages/');

        (new ConfiguredSource($spec, new ContinentalPages(3)))->fetch();
    }

    private function spec(): SourceSpec
    {
        return $this->specFrom();
    }

    private function specWithMaxPages(int $limit): SourceSpec
    {
        return $this->specFrom($limit);
    }

    /**
     * The real spec, with the politeness pause taken out.
     *
     * delay_ms exists so a full run does not hammer somebody else's server. In a
     * test it only sleeps -- and a suite that sleeps in one place pushes every
     * timeout after it, which is how this file once brought down an unrelated
     * test that waits on a helper process.
     */
    private function specFrom(?int $maxPages = null): SourceSpec
    {
        $raw = Yaml::parseFile(resource_path('directive-sources/continental.yaml'));
        $raw['page']['pagination']['delay_ms'] = 0;

        if ($maxPages !== null) {
            $raw['page']['pagination']['max_pages'] = $maxPages;
        }

        return SourceSpec::fromArray($raw, 'continental.yaml');
    }
}

/**
 * The saved pages, answered only to a POST.
 *
 * Counts both verbs so a test can assert HOW the list was walked, not just what
 * came back.
 */
final class ContinentalPages implements FormFetcher, HttpFetcher
{
    public int $gets = 0;

    public int $posts = 0;

    public function __construct(private int $withContent) {}

    public function get(string $url, array $headers = []): string
    {
        $this->gets++;

        // The real route answers, but always with page one. Returning the first
        // page here would let a GET-driver look like it worked.
        return $this->page(1);
    }

    public function post(string $url, array $form, array $headers = []): string
    {
        $this->posts++;

        return $this->page((int) ($form['paged'] ?? 1));
    }

    private function page(int $number): string
    {
        $path = __DIR__."/../../Fixtures/Continental/seite-{$number}.json";

        return is_file($path) && $number <= $this->withContent
            ? (string) file_get_contents($path)
            : (string) file_get_contents(__DIR__.'/../../Fixtures/Continental/seite-51.json');
    }
}
