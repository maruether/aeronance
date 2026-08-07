<?php

declare(strict_types=1);

namespace Tests\Feature\Core;

use App\Core\Documents\ContentTypeVerifier;
use App\Core\Documents\DocumentIntake;
use App\Core\Documents\DocumentType;
use App\Core\Documents\Exceptions\DocumentRejected;
use App\Core\Documents\NullScanner;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * What a certificate upload is allowed to be.
 *
 * the objection, which is the right one: checking a name proves nothing to
 * anybody who does not want it proved. In fairness the name was never the
 * criterion here -- Filament and the media library both ask finfo, which reads
 * content -- but finfo is a guesser with a large table and a bias towards
 * answering. "It guessed pdf" is a weaker claim than "it starts with %PDF- and
 * ends with %%EOF and arrived called .pdf".
 */
final class DocumentIntakeTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dir = sys_get_temp_dir().'/aeronance-intake-'.bin2hex(random_bytes(6));
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir.'/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->dir);

        parent::tearDown();
    }

    #[Test]
    public function a_real_pdf_is_accepted(): void
    {
        $path = $this->write('form1.pdf', "%PDF-1.7\n1 0 obj\n<<>>\nendobj\ntrailer\n%%EOF\n");

        $this->assertSame(DocumentType::Pdf, $this->intake()->accept($path, 'form1.pdf'));
    }

    #[Test]
    public function a_real_jpeg_and_a_real_png_are_accepted(): void
    {
        $jpeg = $this->write('scan.jpg', "\xFF\xD8\xFF\xE0".str_repeat("\x00", 32)."\xFF\xD9");
        $png = $this->write('scan.png', "\x89PNG\r\n\x1A\n".str_repeat("\x00", 32).'IEND'."\xAE\x42\x60\x82");

        $this->assertSame(DocumentType::Jpeg, $this->intake()->accept($jpeg, 'scan.jpg'));
        $this->assertSame(DocumentType::Png, $this->intake()->accept($png, 'scan.png'));
    }

    #[Test]
    public function html_wearing_a_pdf_name_is_refused(): void
    {
        // The whole point. Renaming costs nothing, so it must buy nothing.
        $path = $this->write('form1.pdf', '<html><script>alert(document.cookie)</script></html>');

        $this->expectException(DocumentRejected::class);
        $this->expectExceptionMessageMatches('/keine PDF-, JPEG- oder PNG-Datei/');

        $this->intake()->accept($path, 'form1.pdf');
    }

    #[Test]
    public function a_genuine_png_called_pdf_is_refused_too(): void
    {
        // Both types are allowed, so the file itself is harmless -- and it is
        // still refused. A type that disagrees with its name is not a mistake
        // anybody makes twice; it is what a polyglot looks like from outside.
        $path = $this->write('form1.pdf', "\x89PNG\r\n\x1A\n".str_repeat("\x00", 16).'IEND');

        $this->expectException(DocumentRejected::class);
        $this->expectExceptionMessageMatches('/Inhalt ist ein PNG/');

        $this->intake()->accept($path, 'form1.pdf');
    }

    #[Test]
    public function a_file_that_only_starts_like_a_pdf_is_refused(): void
    {
        // The cheapest disguise there is: borrow the first five bytes. It gets
        // past a signature check and stops at the structural one.
        $path = $this->write('form1.pdf', "%PDF-1.7\n<html><script>alert(1)</script></html>");

        $this->expectException(DocumentRejected::class);
        $this->expectExceptionMessageMatches('/endet aber nicht wie eines/');

        $this->intake()->accept($path, 'form1.pdf');
    }

    #[Test]
    public function a_truncated_upload_is_refused(): void
    {
        // Same check, entirely innocent cause -- and worth catching, because a
        // half-transferred Form 1 in the records is worse than none.
        $path = $this->write('form1.pdf', "%PDF-1.7\n1 0 obj\n<<>>\nendobj\n");

        $this->expectException(DocumentRejected::class);
        $this->expectExceptionMessageMatches('/Upload abgebrochen/');

        $this->intake()->accept($path, 'form1.pdf');
    }

    #[Test]
    public function an_empty_file_has_no_type(): void
    {
        // It would otherwise sail through every "does it start with" check,
        // because everything starts with nothing.
        $path = $this->write('form1.pdf', '');

        $this->expectException(DocumentRejected::class);
        $this->intake()->accept($path, 'form1.pdf');
    }

    #[Test]
    public function a_file_without_an_extension_is_refused(): void
    {
        $path = $this->write('formular', "%PDF-1.7\n%%EOF\n");

        $this->expectException(DocumentRejected::class);
        $this->intake()->accept($path, 'formular');
    }

    #[Test]
    public function an_oversized_file_is_refused_before_anything_reads_it(): void
    {
        $path = $this->write('form1.pdf', "%PDF-1.7\n".str_repeat('x', 2048)."\n%%EOF\n");

        $this->expectException(DocumentRejected::class);
        $this->expectExceptionMessageMatches('/größer als 0 MB|groesser/u');

        // A one-megabyte limit expressed as zero: the smallest limit the config
        // can express, so the check itself is what is under test.
        (new DocumentIntake(new ContentTypeVerifier, new NullScanner, 0))
            ->accept($path, 'form1.pdf');
    }

    #[Test]
    public function the_extension_check_is_skipped_when_there_is_no_name(): void
    {
        // Files already on disk -- a restore, a migration -- have no client name
        // to disagree with, and refusing them for that would be nonsense.
        $path = $this->write('form1.pdf', "%PDF-1.7\n%%EOF\n");

        $this->assertSame(DocumentType::Pdf, $this->intake()->accept($path));
    }

    #[Test]
    public function jpeg_and_jpg_are_both_the_same_thing(): void
    {
        $path = $this->write('scan.jpeg', "\xFF\xD8\xFF\xE0\x00\x10JFIF\xFF\xD9");

        $this->assertSame(DocumentType::Jpeg, $this->intake()->accept($path, 'scan.jpeg'));
        $this->assertSame(DocumentType::Jpeg, $this->intake()->accept($path, 'scan.JPG'));
    }

    #[Test]
    public function svg_is_not_on_the_list_and_will_not_be(): void
    {
        // A document format that executes script. No scan of a Form 1 was ever
        // an SVG, and the cost of allowing it is a stored-XSS primitive.
        $path = $this->write('logo.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>');

        $this->assertNull(DocumentType::fromExtension('svg'));

        $this->expectException(DocumentRejected::class);
        $this->intake()->accept($path, 'logo.svg');
    }

    #[Test]
    public function the_limit_comes_from_configuration(): void
    {
        // It used to be written down in two places that disagreed: 20 MB in the
        // form, 10 MB in the media library, and a file in between was accepted
        // by the form and then blew up mid-booking.
        $this->assertSame(
            config('aeronance.documents.max_size_mb') * 1024 * 1024,
            config('media-library.max_file_size'),
        );

        $this->assertSame(
            config('aeronance.documents.max_size_mb'),
            app(DocumentIntake::class)->maxSizeMegabytes(),
        );
    }

    #[Test]
    public function the_media_library_no_longer_defaults_to_a_public_disk(): void
    {
        // The package default is 'public', which sits behind public/storage and
        // is fetchable by URL. It does not bite for lot certificates because the
        // collection sets its own disk -- it would bite for the next collection
        // that forgets to.
        $this->assertSame('documents', config('media-library.disk_name'));
        $this->assertFalse(config('filesystems.disks.documents.serve'));
        $this->assertSame('private', config('filesystems.disks.documents.visibility'));
        $this->assertStringNotContainsString(
            public_path(),
            config('filesystems.disks.documents.root'),
        );
    }

    private function intake(): DocumentIntake
    {
        return new DocumentIntake(new ContentTypeVerifier, new NullScanner, 20);
    }

    private function write(string $name, string $content): string
    {
        $path = $this->dir.'/'.$name;
        file_put_contents($path, $content);

        return $path;
    }
}
