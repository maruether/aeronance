<?php

declare(strict_types=1);

namespace Tests\Feature\Directives;

use App\Core\Http\HttpFetcher;
use App\Modules\Directives\Enums\Bindingness;
use App\Modules\Directives\Enums\DirectiveKind;
use App\Modules\Directives\Sources\Configured\ConfiguredSource;
use App\Modules\Directives\Sources\Configured\SourceSpec;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Symfony\Component\Yaml\Yaml;
use Tests\TestCase;

/**
 * The portal mode -- asking a Salesforce site the way its own page asks.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * Diamond is the one manufacturer with no overview document. Vorgabe: "Die bieten
 * leider keine gescheite übersicht an." Their portal serves the same content as
 * structured data, and better than a PDF: every row carries an issue date.
 *
 * THE TRAP THIS FILE EXISTS FOR. Called with a wrong or missing parameter, the
 * API answers SUCCESS with an EMPTY LIST -- never an error. Believed, that reads
 * as "the manufacturer has published nothing", which is the single outcome this
 * whole module is built to prevent. Two tests below hold that line, and they are
 * the reason the rest is worth having.
 *
 * The real spec is used rather than a fixture: what has to keep working is
 * diamond.yaml, not a copy of it that can drift.
 * ─────────────────────────────────────────────────────────────────────────────
 */
final class AuraSourceTest extends TestCase
{
    private const PAGE = 'https://partners.diamondaircraft.com/s/technical-publications';

    private const ENDPOINT = 'https://partners.diamondaircraft.com/s/sfsites/aura*';

    #[Test]
    public function it_reads_bulletins_out_of_the_portal(): void
    {
        $this->fakePortal();

        $rows = $this->source()->fetch(['model' => 'DA40-180']);

        $this->assertCount(2, $rows);

        $this->assertSame('MSB40-02/1', $rows[0]->number);
        $this->assertSame('ATA-Code 32; Ersatz der Bremsleitung', $rows[0]->title);
        $this->assertSame('2002-03-25', $rows[0]->issuedAt);
        $this->assertSame('DA40-180', $rows[0]->subjectModel);
        $this->assertSame(DirectiveKind::Sb, $rows[0]->kind);

        // The file id becomes a download link, so somebody can open the document
        // the line refers to without hunting for it in the portal.
        $this->assertStringContainsString('069AAAA', (string) $rows[0]->referenceUrl);
    }

    #[Test]
    public function it_suggests_a_bindingness_only_where_the_maker_states_one(): void
    {
        /*
         * The manufacturer writes the classification into the number: 295 MSB,
         * 333 OSB, 85 RSB and 25 spelling the word out, measured over all 944.
         * The 206 of the DA20 series carry nothing.
         *
         * Unmarked stays NULL, and null is not "optional" -- the model defaults
         * an unset bindingness to mandatory. So the suggestion never relaxes
         * anything the manufacturer did not mark, and the strict side is the
         * one you land on by doing nothing.
         */
        $this->fakePortal(files: [
            $this->file('MSB40-02/1', 'Bremsleitung'),
            $this->file('OSB40-01/2', 'Nacht-VFR'),
            $this->file('DA20-10-01', 'Ohne Marker'),
        ]);

        $rows = $this->source()->fetch(['model' => 'DA40-180']);

        $this->assertSame(Bindingness::Mandatory, $rows[0]->bindingness);
        $this->assertSame(Bindingness::Optional, $rows[1]->bindingness);
        $this->assertNull($rows[2]->bindingness);
    }

    #[Test]
    public function the_marker_is_anchored_not_searched_for(): void
    {
        /*
         * The direction that can hurt is RELAXING: an optional notice read as
         * binding is noise, a binding one read as optional may be waived. A
         * loose search for "osb" anywhere in a number would do exactly that to
         * any number that happens to contain those letters.
         */
        $this->fakePortal(files: [
            $this->file('MSB40-OSB-7', 'Buchstaben mitten in der Nummer'),
        ]);

        $rows = $this->source()->fetch(['model' => 'DA40-180']);

        $this->assertSame(Bindingness::Mandatory, $rows[0]->bindingness);
    }

    #[Test]
    public function a_recommendation_is_its_own_category(): void
    {
        /*
         * 85 documents Diamond marks RSB. Vorgabe: "Empfohlen bedeutet Optional,
         * aber der hersteller empfielt es. Das ist eine eigene Kategorie."
         *
         * So both halves are asserted: it may be declined like an optional line,
         * AND it is not optional -- folding it in would throw away the
         * manufacturer's own advice, which is the part a club needs when it
         * decides what to do with its winter.
         */
        $this->fakePortal(files: [$this->file('RSB40-08', 'Empfohlen')]);

        $rows = $this->source()->fetch(['model' => 'DA40-180']);

        $this->assertSame(Bindingness::Recommended, $rows[0]->bindingness);
        $this->assertTrue($rows[0]->bindingness->permitsRefusal());
        $this->assertNotSame(Bindingness::Optional, $rows[0]->bindingness);
    }

    #[Test]
    public function the_classification_word_is_taken_out_of_the_number(): void
    {
        /*
         * "SB20-9/3 Mandatory" is one document, not a number -- and left in, the
         * word makes the number UNSTABLE: a reclassification would change the
         * string, and the import would create a second directive beside the
         * first instead of updating it, splitting its assessments in two.
         */
        $this->fakePortal(files: [
            $this->file('SB20-9/3 Mandatory', 'Einfuhr Frankreich'),
            $this->file('SB20-10/2 Recommended', 'Nachrüstung'),
        ]);

        $rows = $this->source()->fetch(['model' => 'DA40-180']);

        $this->assertSame('SB20-9/3', $rows[0]->number);
        $this->assertSame(Bindingness::Mandatory, $rows[0]->bindingness);

        $this->assertSame('SB20-10/2', $rows[1]->number);
        $this->assertSame(Bindingness::Recommended, $rows[1]->bindingness);
    }

    #[Test]
    public function an_empty_library_list_is_an_error_not_an_empty_result(): void
    {
        // The trap, exactly as the portal springs it: HTTP 200, state SUCCESS,
        // nothing inside. Anything other than an exception here would report a
        // manufacturer as having published nothing.
        $this->fakePortal(libraries: []);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/leere Liste|keine Einträge/');

        $this->source()->fetch(['model' => 'DA40-180']);
    }

    #[Test]
    public function a_failed_action_is_reported_rather_than_swallowed(): void
    {
        Http::fake([
            self::PAGE => Http::response($this->bootstrapHtml()),
            self::ENDPOINT => Http::response([
                'actions' => [[
                    'id' => '1;a',
                    'state' => 'ERROR',
                    'error' => [['message' => 'No such column Doc_No__c']],
                ]],
            ]),
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/No such column/');

        $this->source()->fetch(['model' => 'DA40-180']);
    }

    #[Test]
    public function a_rebuilt_portal_is_named_rather_than_guessed(): void
    {
        // fwuid and app version are read from the page on every run, because a
        // pinned one works until the day Salesforce redeploys and it silently
        // does not. If the page stops naming it, say so -- do not carry on with
        // a request that will come back empty.
        Http::fake([
            self::PAGE => Http::response('<html><body>Wartungsarbeiten</body></html>'),
            self::ENDPOINT => Http::response([]),
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/fwuid/');

        $this->source()->fetch(['model' => 'DA40-180']);
    }

    #[Test]
    public function it_asks_only_for_the_requested_model(): void
    {
        /*
         * Twenty type libraries times folders times files is a lot of requests to
         * somebody else's server, and a club flies two types. Without this the
         * import is both slow and rude.
         */
        $this->fakePortal();

        $this->source()->fetch(['model' => 'DA40-180']);

        // Bootstrap page, libraries, folders, files -- four. The HK36 library is
        // in the answer and is never asked about.
        Http::assertSentCount(4);
    }

    #[Test]
    public function it_skips_libraries_that_are_not_a_type(): void
    {
        // "Engine Documentation" and "DAI Damage Reports" sit beside the type
        // libraries. Reading them as models would invent two aircraft.
        $this->fakePortal();

        $rows = $this->source()->fetch();

        foreach ($rows as $row) {
            $this->assertNotSame('Engine Documentation', $row->subjectModel);
        }

        $this->assertSame(['DA40-180', 'HK36 Super Dimona'], array_values(array_unique(
            array_map(static fn ($r) => $r->subjectModel, $rows)
        )));
    }

    #[Test]
    public function it_takes_service_bulletins_and_leaves_the_rest(): void
    {
        /*
         * Beside the bulletins lie Service Informations, flight and maintenance
         * manuals, and spare parts catalogues. The brief on the same question at
         * Lindner: "eine ti ist keine tm, bleibt draußen" -- a Service
         * Information is the same class of document.
         */
        $this->fakePortal();

        $rows = $this->source()->fetch(['model' => 'DA40-180']);

        foreach ($rows as $row) {
            $this->assertStringNotContainsString('SI40', $row->number);
        }
    }

    #[Test]
    public function a_file_without_a_number_stops_the_import(): void
    {
        /*
         * Not a hypothetical. Across all 945 Service Bulletins in the portal
         * exactly ONE has an empty number field -- and its description IS the
         * number, "SB20-054-1-M" on the DV20 Katana. Diamond filled the wrong
         * field once.
         *
         * Dropping it quietly loses a Service Bulletin. Importing the rest and
         * mentioning it is worse than it sounds: the club then holds a list that
         * LOOKS complete. So the whole fetch refuses and names the document --
         * the same answer the overview mode gives to a line it cannot read.
         */
        $this->fakePortal(files: [
            ['Doc_No__c' => '', 'Description' => 'SB20-054-1-M', 'Date__c' => '2020-01-01'],
            ['Doc_No__c' => 'MSB40-09', 'Description' => 'Mit Nummer', 'Date__c' => '2020-01-02'],
        ]);

        $this->expectException(RuntimeException::class);

        // The message has to carry the document, or it is not actionable.
        $this->expectExceptionMessageMatches('/SB20-054-1-M/');

        $this->source()->fetch(['model' => 'DA40-180']);
    }

    #[Test]
    public function the_number_is_never_taken_from_the_description(): void
    {
        // The tempting fix for the row above, and the reason it was not taken:
        // the description is a number in that one row and a sentence in the
        // other 944. Vorgabe: "wir raten nicht. Niemals."
        $this->fakePortal();

        $rows = $this->source()->fetch(['model' => 'DA40-180']);

        $this->assertSame('MSB40-02/1', $rows[0]->number);
        $this->assertNotSame($rows[0]->title, $rows[0]->number);
    }

    // ── The portal ──────────────────────────────────────────────────────────

    /** @return array<string, mixed> */
    private function file(string $number, string $description): array
    {
        return [
            'Doc_No__c' => $number,
            'Description' => $description,
            'Date__c' => '2020-01-01',
            'ContentDocumentId' => '069TEST',
        ];
    }

    private function source(): ConfiguredSource
    {
        $spec = SourceSpec::fromArray(
            Yaml::parseFile(base_path('resources/directive-sources/diamond.yaml')),
            'diamond.yaml',
        );

        return new ConfiguredSource($spec, app(HttpFetcher::class));
    }

    /**
     * A stand-in portal that answers by method name, the way the real one does.
     *
     * @param  list<array<string, mixed>>|null  $libraries
     * @param  list<array<string, mixed>>|null  $files
     */
    private function fakePortal(?array $libraries = null, ?array $files = null): void
    {
        $libraries ??= [
            ['Name' => 'TechPubs DA40-180', 'RootContentFolderId' => '0584-da40'],
            ['Name' => 'TechPubs HK36 Super Dimona', 'RootContentFolderId' => '0584-hk36'],
            ['Name' => 'Engine Documentation', 'RootContentFolderId' => '0584-eng'],
        ];

        $files ??= [
            [
                'Doc_No__c' => 'MSB40-02/1',
                'Description' => 'ATA-Code 32; Ersatz der Bremsleitung',
                'Date__c' => '2002-03-25',
                'ContentDocumentId' => '069AAAA',
            ],
            [
                'Doc_No__c' => 'OSB40-03/1',
                'Description' => 'Nachrüstung für Nacht-VFR',
                'Date__c' => '2001-09-24',
                'ContentDocumentId' => '069BBBB',
            ],
        ];

        Http::fake([
            self::PAGE => Http::response($this->bootstrapHtml()),

            self::ENDPOINT => function (Request $request) use ($libraries, $files) {
                $message = json_decode((string) ($request->data()['message'] ?? ''), true);
                $method = $message['actions'][0]['params']['method'] ?? '';

                return Http::response(['actions' => [[
                    'id' => '1;a',
                    'state' => 'SUCCESS',

                    // Nested twice, as the real payload is. Reading the outer
                    // one as the list yields "not an array", which renders as
                    // "0 rows" -- an hour was lost to that once.
                    'returnValue' => ['returnValue' => match ($method) {
                        'getPublicLibraries' => $libraries,
                        'getContentByFolder' => [
                            ['Id' => 'folder-sb', 'Title' => 'Service Bulletins'],
                            ['Id' => 'folder-si', 'Title' => 'Service Informations'],
                            ['Id' => 'folder-amm', 'Title' => 'Maintenance Manuals'],
                        ],
                        'getFilesByFolder' => $files,
                        default => [],
                    }],
                ]]]);
            },
        ]);
    }

    /**
     * The bootstrap the page carries: fwuid and the loaded app version, both
     * URL-encoded inside an inline script, both changing on every redeploy.
     */
    private function bootstrapHtml(): string
    {
        return '<html><head><script>'
            .'window.$A={};/* %7B%22mode%22%3A%22PROD%22%2C%22fwuid%22%3A%22abcDEF123%22%2C'
            .'%22loaded%22%3A%7B%22APPLICATION%40markup%3A%2F%2Fsiteforce%3AcommunityApp%22%3A%22xyz789%22%7D%7D */'
            .'</script></head><body></body></html>';
    }
}
