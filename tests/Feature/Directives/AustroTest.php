<?php

declare(strict_types=1);

namespace Tests\Feature\Directives;

use App\Core\Http\GuzzleFetcher;
use App\Modules\Directives\Enums\Bindingness;
use App\Modules\Directives\Enums\SubjectKind;
use App\Modules\Directives\Sources\Configured\ConfiguredSource;
use App\Modules\Directives\Sources\Configured\SourceSpec;
use App\Modules\Directives\Sources\DirectiveRow;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Yaml\Yaml;
use Tests\TestCase;

/**
 * Austro Engine, in Diamond's portal -- one level deeper.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * THE FAILURE THIS FILE GUARDS: read with Diamond's assumption, this source
 * returned NOTHING AT ALL, and nothing at all is indistinguishable from a
 * manufacturer who has published nothing.
 *
 * Diamond's tree is  library("TechPubs DA40") -> folder("Service Bulletins")
 * -> files, so the library names the model. Austro puts everything in ONE
 * library and splits by engine underneath:
 *
 *   Engine Documentation
 *     ├── AE300 - AE330 ── Service Bulletins - Mandatory / - Recommended, Optional
 *     └── AE50R ───────── Service Bulletins - Mandatory / - Recommended, Optional
 *
 * The model folders match no document-folder pattern, so no folder was ever
 * wanted. aura.model_folder_pattern walks that level and takes the model from
 * the folder rather than the library.
 * ─────────────────────────────────────────────────────────────────────────────
 */
final class AustroTest extends TestCase
{
    private const PAGE = 'https://partners.diamondaircraft.com/s/technical-publications';

    private const ENDPOINT = 'https://partners.diamondaircraft.com/s/sfsites/aura*';

    #[Test]
    public function it_finds_the_bulletins_below_the_model_folders(): void
    {
        $this->fakePortal();

        $rows = $this->fetch();

        // Two engines, two bulletin folders each.
        $this->assertCount(4, $rows);
        $this->assertSame(SubjectKind::Engine, $rows[0]->subjectKind);
    }

    #[Test]
    public function the_model_comes_from_the_folder_not_the_library(): void
    {
        /*
         * The library is called "Engine Documentation" -- a drawer, not a type.
         * Taking the model from there would file every Austro bulletin against
         * an aircraft called "Engine Documentation".
         */
        $this->fakePortal();

        $models = array_values(array_unique(array_map(
            static fn (DirectiveRow $r): ?string => $r->subjectModel,
            $this->fetch(),
        )));

        sort($models);

        $this->assertSame(['AE300 - AE330', 'AE50R'], $models);
    }

    #[Test]
    public function two_engines_may_have_folders_of_the_same_name(): void
    {
        /*
         * "Service Bulletins - Mandatory" exists under BOTH engines. Collected
         * into a map keyed by folder name, only the last would survive -- eight
         * bulletins gone with nothing to show for it. Hence two lists sharing an
         * index rather than a map.
         */
        $this->fakePortal();

        $rows = $this->fetch();

        $ae300 = array_filter($rows, static fn (DirectiveRow $r): bool => $r->subjectModel === 'AE300 - AE330');
        $ae50r = array_filter($rows, static fn (DirectiveRow $r): bool => $r->subjectModel === 'AE50R');

        $this->assertCount(2, $ae300);
        $this->assertCount(2, $ae50r);
    }

    #[Test]
    public function the_title_comes_from_the_field_austro_actually_fills(): void
    {
        /*
         * diamond.yaml reads the title from `Description`. Across the Austro
         * documents that field is null throughout -- copied over, every row
         * would have arrived without a title.
         */
        $this->fakePortal();

        foreach ($this->fetch() as $row) {
            $this->assertNotSame('', $row->title);
            $this->assertStringNotContainsString('MSB-', $row->title);
        }
    }

    #[Test]
    public function the_bindingness_is_read_from_the_number(): void
    {
        $this->fakePortal();

        $rows = $this->fetch();

        $recommended = array_filter(
            $rows,
            static fn (DirectiveRow $r): bool => $r->bindingness === Bindingness::Recommended,
        );

        $this->assertCount(2, $recommended);
    }

    /** @return list<DirectiveRow> */
    private function fetch(): array
    {
        $spec = SourceSpec::fromArray(
            Yaml::parseFile(resource_path('directive-sources/austro.yaml')),
            'austro.yaml',
        );

        return (new ConfiguredSource($spec, new GuzzleFetcher))->fetch();
    }

    private function fakePortal(): void
    {
        $files = [
            'folder-ae300-m' => [[
                'Doc_No__c' => 'MSB-E4-017',
                'Title' => 'Check/Replacement of timing chain',
                'Date__c' => '2016-12-02',
                'ContentDocumentId' => '069AAAA',
            ]],
            'folder-ae300-r' => [[
                'Doc_No__c' => 'RSB-E4-038',
                'Title' => 'Flywheel Hub inspection for cracks',
                'Date__c' => '2022-03-24',
                'ContentDocumentId' => '069BBBB',
            ]],
            'folder-ae50r-m' => [[
                'Doc_No__c' => 'MSB-AE50R-007',
                'Title' => 'Approved Software and Hardware Versions',
                'Date__c' => '2023-04-05',
                'ContentDocumentId' => '069CCCC',
            ]],
            'folder-ae50r-r' => [[
                'Doc_No__c' => 'RSB-AE50R-005',
                'Title' => 'Carburetor cap retrofit',
                'Date__c' => '2012-02-01',
                'ContentDocumentId' => '069DDDD',
            ]],
        ];

        Http::fake([
            self::PAGE => Http::response($this->bootstrapHtml()),

            self::ENDPOINT => function (Request $request) use ($files) {
                $message = json_decode((string) ($request->data()['message'] ?? ''), true);
                $params = $message['actions'][0]['params'] ?? [];
                $method = $params['method'] ?? '';
                $parent = $params['params']['parentfolderId'] ?? $params['params']['parentId'] ?? null;

                $value = match ($method) {
                    'getPublicLibraries' => [
                        ['Name' => 'TechPubs DA40-180', 'RootContentFolderId' => '07H-da40'],
                        ['Name' => 'Engine Documentation', 'RootContentFolderId' => '07H-eng'],
                    ],

                    // The level Diamond does not have: engines, then documents.
                    'getContentByFolder' => match ($parent) {
                        '07H-eng' => [
                            ['Id' => 'folder-ae300', 'Title' => 'AE300 - AE330'],
                            ['Id' => 'folder-ae50r', 'Title' => 'AE50R'],
                        ],
                        'folder-ae300' => [
                            ['Id' => 'folder-ae300-m', 'Title' => 'Service Bulletins - Mandatory'],
                            ['Id' => 'folder-ae300-r', 'Title' => 'Service Bulletins - Recommended, Optional'],
                            ['Id' => 'folder-ae300-mm', 'Title' => 'Maintenance Manual E4 E4P'],
                        ],
                        'folder-ae50r' => [
                            ['Id' => 'folder-ae50r-m', 'Title' => 'Service Bulletins - Mandatory'],
                            ['Id' => 'folder-ae50r-r', 'Title' => 'Service Bulletins - Recommended, Optional'],
                        ],
                        default => [],
                    },

                    'getFilesByFolder' => $files[$parent] ?? [],
                    default => [],
                };

                return Http::response(['actions' => [[
                    'id' => '1;a',
                    'state' => 'SUCCESS',
                    'returnValue' => ['returnValue' => $value],
                ]]]);
            },
        ]);
    }

    private function bootstrapHtml(): string
    {
        return '<html><head><script>'
            .'window.$A={};/* %7B%22mode%22%3A%22PROD%22%2C%22fwuid%22%3A%22abcDEF123%22%2C'
            .'%22loaded%22%3A%7B%22APPLICATION%40markup%3A%2F%2Fsiteforce%3AcommunityApp%22%3A%22xyz789%22%7D%7D */'
            .'</script></head><body></body></html>';
    }
}
