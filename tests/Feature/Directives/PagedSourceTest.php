<?php

declare(strict_types=1);

namespace Tests\Feature\Directives;

use App\Core\Http\HttpFetcher;
use App\Core\Http\HttpNotFound;
use App\Modules\Directives\Enums\Bindingness;
use App\Modules\Directives\Sources\Configured\ConfiguredSource;
use App\Modules\Directives\Sources\Configured\SourceSpec;
use App\Modules\Directives\Sources\DirectiveRow;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Symfony\Component\Yaml\Yaml;
use Tests\TestCase;

/**
 * The fifth mode: a list served ten at a time that does not hold still.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * Every other manufacturer here hands over its list in one piece. DG serves 1894
 * documents through a WordPress feed capped at ten per page -- and the pages
 * REORDER between requests. Measured against the live site over 15 pages: 12 of
 * 165 entries came back twice, and every duplicate on one page is a document
 * that slid off another before it was read.
 *
 * Walking the pages once therefore loses documents while looking exactly like a
 * complete run. That is the module's defining failure, so the tests below are
 * mostly about the ways it does NOT happen: the settling loop, the 404 that ends
 * a list, the 404 that must not, and the reconciliation against DG's own
 * inventory.
 * ─────────────────────────────────────────────────────────────────────────────
 */
final class PagedSourceTest extends TestCase
{
    #[Test]
    public function it_walks_every_page(): void
    {
        $rows = $this->source(new DgFeedFetcher)->fetch(['model' => 'DG-300']);

        // Six saved pages of ten. Not all sixty are directives -- the library
        // holds manuals too -- so the count is what the fixtures actually carry.
        $this->assertGreaterThan(20, count($rows));

        $numbers = array_map(fn (DirectiveRow $r): string => $r->number, $rows);
        $this->assertContains('TM 359-01', $numbers);
    }

    #[Test]
    public function a_manual_whose_title_contains_a_tm_number_is_not_a_directive(): void
    {
        /*
         * "MM DG-1000T Rev25 TM1000-52rev2 affected pages + Diagram 13" is a
         * manual supplement. Roughly two thirds of DG's library is like this --
         * manuals, flight manuals, working instructions -- and an unanchored
         * number pattern turns every one of them into a directive nobody can
         * comply with.
         */
        $fetcher = new StubFeedFetcher([
            $this->feed([
                '<title>MM DG-1000T Rev25 TM1000-52rev2 affected pages</title>'
                    .'<link>https://example.test/wpfd_file/mm-dg-1000t/</link>',
                '<title>TM 301-01</title><link>https://example.test/wpfd_file/tm-301-01/</link>',
            ]),
        ]);

        $rows = $this->source($fetcher)->fetch(['model' => 'DG-300']);

        $this->assertCount(1, $rows);
        $this->assertSame('TM 301-01', $rows[0]->number);
    }

    #[Test]
    public function both_of_the_manufacturers_spellings_are_read(): void
    {
        // DG writes 196 slugs as "tm-301-01" and 421 as "tm301-19". A pattern
        // requiring the separator would have dropped the larger half in silence
        // -- the Lindner mistake, where nineteen real directives went missing
        // because the number pattern only knew one of two schemes.
        $fetcher = new StubFeedFetcher([
            $this->feed([
                '<title>TM 301-01</title><link>https://example.test/wpfd_file/tm-301-01/</link>',
                '<title>TM301-19</title><link>https://example.test/wpfd_file/tm301-19/</link>',
                '<title>TN 359-03</title><link>https://example.test/wpfd_file/tn-359-03/</link>',
            ]),
        ]);

        $this->assertSame(
            ['TM 301-01', 'TM301-19', 'TN 359-03'],
            array_map(fn (DirectiveRow $r): string => $r->number, $this->source($fetcher)->fetch(['model' => 'DG-300'])),
        );
    }

    #[Test]
    public function a_shifting_list_is_walked_again_until_it_settles(): void
    {
        /*
         * The real behaviour, reproduced: the same page returns different items
         * on a second request. One pass sees A and C; the second pass finds B,
         * which had been pushed onto a page already read.
         */
        $a = '<title>TM 1-01</title><link>https://example.test/wpfd_file/a/</link>';
        $b = '<title>TM 1-02</title><link>https://example.test/wpfd_file/b/</link>';
        $c = '<title>TM 1-03</title><link>https://example.test/wpfd_file/c/</link>';

        $fetcher = new StubFeedFetcher([
            // First walk: B is nowhere -- it sat on page two behind C and got
            // pushed off before the driver arrived.
            [$this->feed([$a]), $this->feed([$c])],

            // Second walk: the list has reordered and B is now visible.
            [$this->feed([$a]), $this->feed([$b])],

            // Third: nothing new, so the loop stops.
            [$this->feed([$a]), $this->feed([$c])],
        ]);

        $numbers = array_map(
            fn (DirectiveRow $r): string => $r->number,
            $this->source($fetcher)->fetch(['model' => 'DG-300']),
        );

        sort($numbers);
        $this->assertSame(['TM 1-01', 'TM 1-02', 'TM 1-03'], $numbers);
    }

    #[Test]
    public function a_list_that_never_settles_is_refused(): void
    {
        // Every pass produces something new. At that point nobody can say what
        // "the list" is, and a fifth pass would not make it truer -- an import
        // from it would be incomplete without showing it.
        $fetcher = new AlwaysNewFetcher;

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/nicht zur Ruhe/');

        $this->source($fetcher)->fetch(['model' => 'DG-300']);
    }

    #[Test]
    public function a_404_on_the_first_page_is_not_an_empty_catalogue(): void
    {
        /*
         * The distinction the whole HttpNotFound type exists for. WordPress
         * answers a page past the end with 404, so that is how a list ends --
         * but a 404 on page ONE means the URL or the type slug is wrong, and
         * reading it as "the list ended" would report an empty catalogue for a
         * manufacturer publishing hundreds.
         */
        $this->expectException(HttpNotFound::class);

        $this->source(new NotFoundFetcher)->fetch(['model' => 'DG-300']);
    }

    #[Test]
    public function an_unknown_model_is_refused_by_name(): void
    {
        // A wrong category slug does not fail -- the feed answers with an empty
        // list, and that reads as "DG hat dazu nichts veröffentlicht". Checking
        // it against DG's own taxonomy turns silence into a refusal.
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/kennt kein Muster "DG-999"/');

        $this->source(new DgFeedFetcher)->fetch(['model' => 'DG-999']);
    }

    #[Test]
    public function a_whole_catalogue_run_is_counted_against_the_inventory(): void
    {
        /*
         * The only check that can say "fourteen missing" instead of "looks
         * fine". The feed carries the titles; the sitemap carries the complete
         * set. Neither alone can tell whether a paged run was complete.
         */
        $fetcher = new StubFeedFetcher(
            passes: [
                $this->feed(['<title>TM 1-01</title><link>https://example.test/wpfd_file/a/</link>']),
            ],
            inventory: '<urlset><url><loc>https://example.test/wpfd_file/a/</loc></url>'
                .'<url><loc>https://example.test/wpfd_file/b/</loc></url></urlset>',
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/1 von 2 Dokumenten fehlen/');

        // No model: the inventory lists everything DG has and cannot say which
        // entries belong to one type, so it is only meaningful for a full run.
        $this->source($fetcher)->fetch();
    }

    #[Test]
    public function nothing_claims_a_bindingness_the_source_never_stated(): void
    {
        /*
         * DG's feed has no urgency column anywhere. bindingnessFor() reads an
         * empty column as mandatory, which is right where a column EXISTS --
         * Schleicher leaving one blank means "no exception applies". Asking it
         * here would turn silence into a claim and mark all 1894 documents
         * mandatory on no evidence.
         *
         * Vorgabe: "es gibt teilweise optionale TM's die sollen auch rein." They
         * do -- and they must not arrive mislabelled on the way in.
         */
        $fetcher = new StubFeedFetcher([
            $this->feed(['<title>TM 301-01</title><link>https://example.test/wpfd_file/a/</link>']),
        ]);

        $row = $this->source($fetcher)->fetch(['model' => 'DG-300'])[0];

        // Null on the row, resolved from the kind when it is stored: a TM is
        // optional until an authority adopts it.
        $this->assertNull($row->bindingness);
        $this->assertSame(Bindingness::Optional, $row->toAttributes()['bindingness']);
    }

    private function source(HttpFetcher $fetcher): ConfiguredSource
    {
        return new ConfiguredSource($this->spec(), $fetcher);
    }

    private function spec(): SourceSpec
    {
        $raw = Yaml::parseFile(resource_path('directive-sources/dg.yaml'));

        /*
         * DG reads its DIRECTIVES from the overview sheet now -- see
         * OverviewSheetTest. The paged feed described in the same file is the
         * document library: 1894 files, one per language, which is where the PDF
         * behind a line of the sheet lives.
         *
         * The mechanism therefore still has to work, and is still worth testing
         * against DG's real feed rather than an invented one, because the
         * failure it guards against is DG's own: a list that reorders between
         * requests. So the mode is asked for explicitly here.
         */
        $raw['type'] = 'list';

        // No sleeping in tests, and a low pass limit so the "never settles" case
        // does not take four passes to say so.
        $raw['page']['pagination']['delay_ms'] = 0;
        $raw['page']['pagination']['max_pages'] = 12;

        return SourceSpec::fromArray($raw, 'dg.yaml');
    }

    /** @param list<string> $items */
    private function feed(array $items): string
    {
        return '<rss><channel><title>DG Aviation</title>'
            .implode('', array_map(static fn (string $i): string => '<item>'.$i.'</item>', $items))
            .'</channel></rss>';
    }
}

/**
 * DG's own pages, saved.
 *
 * Six feed pages and the category taxonomy. A real fixture rather than a
 * synthetic one, because the spec's patterns are written against DG's exact
 * markup and a hand-made feed would agree with them by construction.
 */
final class DgFeedFetcher implements HttpFetcher
{
    public function get(string $url, array $headers = []): string
    {
        if (str_contains($url, 'taxonomies')) {
            return (string) file_get_contents(__DIR__.'/../../Fixtures/Dg/kategorien.xml');
        }

        preg_match('/[?&]paged=(\d+)/', $url, $m);
        $page = (int) ($m[1] ?? 1);

        $file = __DIR__."/../../Fixtures/Dg/feed-dg-300-{$page}.xml";

        // Past the last saved page, exactly as the site behaves.
        if (! is_file($file)) {
            throw new HttpNotFound(sprintf('Could not fetch %s: HTTP 404.', $url));
        }

        return (string) file_get_contents($file);
    }
}

/**
 * Scripted passes over a list.
 *
 * Structured as passes rather than as one sequence of responses, because that
 * is the shape of the problem: the driver walks the whole list, then walks it
 * again, and the point is that the SECOND walk can see something the first one
 * missed. A flat sequence cannot express that -- it just runs out.
 */
final class StubFeedFetcher implements HttpFetcher
{
    private int $pass = 0;

    /** @param list<list<string>>|list<string> $passes pages, per pass */
    public function __construct(private array $passes, private ?string $inventory = null)
    {
        // One pass given as a flat list of pages is the common case; repeat it
        // so the settling loop sees a list that does not change.
        if ($this->passes !== [] && ! is_array($this->passes[0])) {
            $this->passes = [$this->passes, $this->passes];
        }
    }

    public function get(string $url, array $headers = []): string
    {
        if (str_contains($url, 'taxonomies')) {
            return '<urlset><url><loc>https://www.dg-aviation.de/wp-file-download/dg-300/</loc></url></urlset>';
        }

        if (str_contains($url, 'sitemap-posts')) {
            return $this->inventory ?? '<urlset></urlset>';
        }

        preg_match('/[?&]paged=(\d+)/', $url, $m);
        $page = (int) ($m[1] ?? 1);

        if ($page === 1) {
            $this->pass++;
        }

        // Once the script is exhausted the list simply stops changing, which is
        // what makes the settling loop terminate.
        $pages = $this->passes[$this->pass - 1] ?? end($this->passes);

        if (! isset($pages[$page - 1])) {
            throw new HttpNotFound(sprintf('Could not fetch %s: HTTP 404.', $url));
        }

        return $pages[$page - 1];
    }
}

/** A list that produces something new on every single pass. */
final class AlwaysNewFetcher implements HttpFetcher
{
    private int $call = 0;

    public function get(string $url, array $headers = []): string
    {
        if (str_contains($url, 'taxonomies')) {
            return '<urlset><url><loc>https://www.dg-aviation.de/wp-file-download/dg-300/</loc></url></urlset>';
        }

        $this->call++;

        return '<rss><channel><item><title>TM 1-'.$this->call.'</title>'
            .'<link>https://example.test/wpfd_file/'.$this->call.'/</link></item></channel></rss>';
    }
}

/** Everything is 404 -- including the very first page. */
final class NotFoundFetcher implements HttpFetcher
{
    public function get(string $url, array $headers = []): string
    {
        if (str_contains($url, 'taxonomies')) {
            return '<urlset><url><loc>https://www.dg-aviation.de/wp-file-download/dg-300/</loc></url></urlset>';
        }

        throw new HttpNotFound(sprintf('Could not fetch %s: HTTP 404.', $url));
    }
}
