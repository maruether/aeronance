<?php

declare(strict_types=1);

namespace Tests\Feature\Warehouse;

use App\Core\Access\AccessSetup;
use App\Core\Modules\ModuleManager;
use App\Models\User;
use App\Modules\Warehouse\Actions\ReceiveStock;
use App\Modules\Warehouse\Enums\PartClassification;
use App\Modules\Warehouse\Models\PartType;
use App\Modules\Warehouse\Models\StockLot;
use App\Modules\Warehouse\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Certificate files.
 *
 * The security guardrails ask for a private disk outside the web root, delivery
 * only through an authenticated controller, a type whitelist and generated
 * names. Most of what is checked here is the delivery: a file that can be
 * fetched by guessing its address is not a protected document, whatever the
 * disk configuration says.
 */
final class DocumentStorageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('documents');

        app(AccessSetup::class)->run();
        app(ModuleManager::class)->enable('warehouse');
        app(ModuleManager::class)->forgetCache();
    }

    #[Test]
    public function the_documents_disk_is_private_and_outside_the_web_root(): void
    {
        $config = config('filesystems.disks.documents');

        $this->assertSame('private', $config['visibility']);
        $this->assertFalse($config['serve'] ?? false, 'The disk must not serve files by itself.');
        $this->assertStringNotContainsString('public', $config['root']);
    }

    #[Test]
    public function a_certificate_can_be_attached_to_a_lot(): void
    {
        $lot = $this->lotWithDocument();

        $this->assertTrue($lot->hasDocumentFile());
        $this->assertCount(1, $lot->documents());
    }

    #[Test]
    public function it_is_delivered_to_someone_who_may_see_stock(): void
    {
        $lot = $this->lotWithDocument();
        $media = $lot->documents()->first();

        $this->actingAs($this->userWith(Permissions::STOCK_VIEW))
            ->get(route('warehouse.document', ['lot' => $lot, 'media' => $media]))
            ->assertSuccessful();
    }

    #[Test]
    public function it_is_refused_without_the_permission(): void
    {
        $lot = $this->lotWithDocument();
        $media = $lot->documents()->first();

        $this->actingAs($this->userWith())
            ->get(route('warehouse.document', ['lot' => $lot, 'media' => $media]))
            ->assertForbidden();
    }

    #[Test]
    public function it_is_refused_without_logging_in(): void
    {
        $lot = $this->lotWithDocument();
        $media = $lot->documents()->first();

        $this->get(route('warehouse.document', ['lot' => $lot, 'media' => $media]))
            ->assertRedirect();
    }

    #[Test]
    public function a_file_belonging_to_another_lot_cannot_be_fetched_through_this_one(): void
    {
        // The classic broken-object-reference: a valid lot id plus somebody
        // else's file id. Checking the file alone would let it through.
        $mine = $this->lotWithDocument();
        $other = $this->lotWithDocument();

        $foreignMedia = $other->documents()->first();

        $this->actingAs($this->userWith(Permissions::STOCK_VIEW))
            ->get(route('warehouse.document', ['lot' => $mine, 'media' => $foreignMedia]))
            ->assertNotFound();
    }

    #[Test]
    public function nothing_is_delivered_while_the_module_is_switched_off(): void
    {
        $lot = $this->lotWithDocument();
        $media = $lot->documents()->first();

        app(ModuleManager::class)->disable('warehouse');
        app(ModuleManager::class)->forgetCache();

        $this->actingAs($this->userWith(Permissions::STOCK_VIEW))
            ->get(route('warehouse.document', ['lot' => $lot, 'media' => $media]))
            ->assertNotFound();
    }

    #[Test]
    public function the_stored_name_is_not_the_name_that_was_uploaded(): void
    {
        // A generated path means a guessed URL leads nowhere even if the
        // original file name is known.
        $lot = $this->lotWithDocument();
        $media = $lot->documents()->first();

        $this->assertNotSame($media->file_name, $media->getPath());
        $this->assertStringContainsString((string) $media->id, $media->getPath());
    }

    #[Test]
    public function the_response_tells_the_browser_exactly_what_to_do_with_it(): void
    {
        // This route serves attacker-supplied bytes from the application's own
        // origin. If a browser can be talked into treating them as HTML, any
        // script in them runs with the session of whoever opened the file --
        // stored XSS with the full run of the panel. These four headers are what
        // stands between those two sentences.
        $lot = $this->lotWithDocument();
        $media = $lot->documents()->first();

        $response = $this->actingAs($this->userWith(Permissions::STOCK_VIEW))
            ->get(route('warehouse.document', ['lot' => $lot, 'media' => $media]));

        $response->assertSuccessful()
            ->assertHeader('Content-Type', 'application/pdf')
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('Referrer-Policy', 'no-referrer');

        $this->assertStringContainsString(
            "default-src 'none'",
            $response->headers->get('Content-Security-Policy') ?? '',
        );
        $this->assertStringContainsString(
            'sandbox',
            $response->headers->get('Content-Security-Policy') ?? '',
        );
    }

    #[Test]
    public function the_content_type_comes_from_the_file_not_from_the_record(): void
    {
        // A column can be wrong -- through a bad migration, a restored backup, a
        // bug in something not yet written. The bytes are the file. So the
        // header is read off disk at delivery, and a record claiming otherwise
        // changes nothing.
        $lot = $this->lotWithDocument();
        $media = $lot->documents()->first();

        $media->forceFill(['mime_type' => 'text/html'])->save();

        $this->actingAs($this->userWith(Permissions::STOCK_VIEW))
            ->get(route('warehouse.document', ['lot' => $lot, 'media' => $media]))
            ->assertSuccessful()
            ->assertHeader('Content-Type', 'application/pdf');
    }

    #[Test]
    public function something_unrecognisable_on_disk_is_downloaded_never_rendered(): void
    {
        // Belt and braces for a file that got past intake somehow -- an older
        // upload, a restore, a hand-placed file. Unknown bytes are handed over
        // as a download with a neutral type, so nothing renders them.
        $lot = $this->lotWithDocument();
        $media = $lot->documents()->first();

        file_put_contents($media->getPath(), '<html><script>alert(1)</script></html>');

        $response = $this->actingAs($this->userWith(Permissions::STOCK_VIEW))
            ->get(route('warehouse.document', ['lot' => $lot, 'media' => $media]));

        $response->assertSuccessful()
            ->assertHeader('Content-Type', 'application/octet-stream');

        $this->assertStringContainsString(
            'attachment',
            $response->headers->get('Content-Disposition') ?? '',
        );
    }

    #[Test]
    public function an_odd_file_name_cannot_break_the_header(): void
    {
        // Quotes and semicolons in a Content-Disposition are how one header
        // becomes two. The name is cosmetic here -- the file is identified by
        // its id -- so anything doubtful is simply dropped.
        //
        // Worth recording what this test found: a name carrying CR/LF never
        // gets this far at all. The media library refuses to build a path from
        // it and throws "Corrupted path detected", which is a better place to
        // stop it than the header. Two layers, and the outer one already held.
        $lot = $this->lotWithDocument();
        $media = $lot->documents()->first();

        $media->forceFill(['file_name' => 'evil";a=b.pdf'])->save();

        $disposition = $this->actingAs($this->userWith(Permissions::STOCK_VIEW))
            ->get(route('warehouse.document', ['lot' => $lot, 'media' => $media]))
            ->headers->get('Content-Disposition') ?? '';

        $this->assertStringNotContainsString('";', $disposition);
        $this->assertStringEndsWith('.pdf"', $disposition);
        $this->assertStringStartsWith('inline;', $disposition);
    }

    private function lotWithDocument(): StockLot
    {
        $part = PartType::create([
            'name' => 'Teil '.uniqid(),
            'classification' => PartClassification::Component,
            'unit_of_measure' => 'St',
            'requires_form_one' => true,
        ]);

        app(ReceiveStock::class)->handle($part, 4, '2026-07-01', lotData: [
            'document_type' => StockLot::DOCUMENT_FORM_ONE,
            'document_reference' => 'F1-2026-'.uniqid(),
        ]);

        $lot = StockLot::where('part_type_id', $part->id)->sole();

        $lot->addMedia($this->aRealPdf())
            ->toMediaCollection(StockLot::DOCUMENTS, 'documents');

        return $lot->fresh();
    }

    /**
     * A file that is genuinely a PDF, not merely named like one.
     *
     * UploadedFile::fake() produces an empty file whose actual type is
     * application/x-empty, and the whitelist rejects it -- correctly, which is
     * how this came to light. Checking the extension alone is exactly the hole
     * a type whitelist is supposed to close, so the test has to bring real bytes.
     */
    private function aRealPdf(): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'sw').'.pdf';

        file_put_contents($path, implode("\n", [
            '%PDF-1.4',
            '1 0 obj<</Type/Catalog/Pages 2 0 R>>endobj',
            '2 0 obj<</Type/Pages/Kids[3 0 R]/Count 1>>endobj',
            '3 0 obj<</Type/Page/Parent 2 0 R/MediaBox[0 0 595 842]>>endobj',
            'trailer<</Root 1 0 R>>',
            '%%EOF',
        ]));

        return new UploadedFile($path, 'form1.pdf', 'application/pdf', null, true);
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
