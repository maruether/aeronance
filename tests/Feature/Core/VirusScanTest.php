<?php

declare(strict_types=1);

namespace Tests\Feature\Core;

use App\Core\Documents\ClamAvScanner;
use App\Core\Documents\ContentTypeVerifier;
use App\Core\Documents\DocumentIntake;
use App\Core\Documents\Exceptions\DocumentRejected;
use App\Core\Documents\NullScanner;
use App\Core\Documents\VirusScanner;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

/**
 * The virus scanner, tested against a socket rather than a mock.
 *
 * A mocked scanner would prove that the code calls a method. What is actually
 * worth knowing is whether the INSTREAM conversation is right -- the chunk
 * framing, the terminating zero length, the shape of the reply -- because that
 * is the part that will be wrong, and it will be wrong silently: a scanner whose
 * protocol is subtly broken reports everything clean.
 *
 * So these run a stand-in clamd in its own process and talk to it properly.
 */
final class VirusScanTest extends TestCase
{
    private const EICAR = 'X5O!P%@AP[4\\PZX54(P^)7CC)7}$EICAR-STANDARD-ANTIVIRUS-TEST-FILE!$H+H*';

    private string $dir;

    /** @var list<resource> */
    private array $processes = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->dir = sys_get_temp_dir().'/aeronance-clam-'.bin2hex(random_bytes(4));
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        foreach ($this->processes as $process) {
            @proc_terminate($process);
            @proc_close($process);
        }

        foreach (glob($this->dir.'/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->dir);

        parent::tearDown();
    }

    #[Test]
    public function a_clean_file_comes_back_clean(): void
    {
        $socket = $this->startFakeClamd('clean');

        $result = $this->scanner($socket)->scan($this->write('form1.pdf', "%PDF-1.7\n%%EOF\n"));

        $this->assertTrue($result->scanned);
        $this->assertTrue($result->clean);
    }

    #[Test]
    public function the_eicar_test_file_is_reported_with_its_signature(): void
    {
        // The string every scanner in the world is required to recognise, so
        // this is the one case that can be tested without real malware.
        $socket = $this->startFakeClamd('infected');

        $result = $this->scanner($socket)->scan($this->write('nasty.pdf', self::EICAR));

        $this->assertTrue($result->scanned);
        $this->assertFalse($result->clean);
        $this->assertSame('Eicar-Test-Signature', $result->signature);
    }

    #[Test]
    public function an_infected_upload_is_refused_and_never_stored(): void
    {
        $socket = $this->startFakeClamd('infected');

        $path = $this->write('form1.pdf', "%PDF-1.7\n".self::EICAR."\n%%EOF\n");

        $intake = new DocumentIntake(new ContentTypeVerifier, $this->scanner($socket), 20);

        $this->expectException(DocumentRejected::class);
        $this->expectExceptionMessageMatches('/Virenprüfung hat angeschlagen/u');

        $intake->accept($path, 'form1.pdf');
    }

    #[Test]
    public function a_scanner_that_cannot_be_reached_blocks_the_upload(): void
    {
        // The important default. A check that switches itself off when its
        // daemon dies is the state one is least likely to notice -- everything
        // keeps working, and nothing is being examined.
        $scanner = $this->scanner($this->dir.'/nobody-is-listening.sock');

        $this->expectException(DocumentRejected::class);
        $this->expectExceptionMessageMatches('/nicht erreichbar/');

        $scanner->scan($this->write('form1.pdf', "%PDF-1.7\n%%EOF\n"));
    }

    #[Test]
    public function it_can_be_told_to_carry_on_instead(): void
    {
        // For the club that would rather book the delivery than stop at the
        // shelf. Reported as "not scanned", never as clean.
        $scanner = $this->scanner($this->dir.'/nobody-is-listening.sock', failClosed: false);

        $result = $scanner->scan($this->write('form1.pdf', "%PDF-1.7\n%%EOF\n"));

        $this->assertFalse($result->scanned);
    }

    #[Test]
    public function an_answer_it_cannot_read_counts_as_no_answer(): void
    {
        $socket = $this->startFakeClamd('garbage');

        $this->expectException(DocumentRejected::class);

        $this->scanner($socket)->scan($this->write('form1.pdf', "%PDF-1.7\n%%EOF\n"));
    }

    #[Test]
    public function nothing_is_scanned_unless_it_is_switched_on(): void
    {
        // And the absence says so: notScanned, not clean. Nothing downstream can
        // mistake an unconfigured scanner for a passed check.
        $this->assertInstanceOf(NullScanner::class, app(VirusScanner::class));
        $this->assertFalse(app(VirusScanner::class)->isEnabled());

        $result = app(VirusScanner::class)->scan($this->write('form1.pdf', "%PDF-1.7\n%%EOF\n"));

        $this->assertFalse($result->scanned);
    }

    #[Test]
    public function switching_it_on_is_one_configuration_value(): void
    {
        config()->set('aeronance.documents.scanner', 'clamav');
        app()->forgetInstance(VirusScanner::class);

        $this->assertInstanceOf(ClamAvScanner::class, app(VirusScanner::class));
        $this->assertTrue(app(VirusScanner::class)->isEnabled());
    }

    /**
     * ─────────────────────────────────────────────────────────────────────────
     * DIE VERBINDUNGSPRUEFUNG, und warum es sie gibt.
     *
     * „Eingeschaltet" sieht von aussen genauso aus wie „eingeschaltet und
     * erreichbar". Der Unterschied faellt sonst erst beim ersten Upload auf --
     * und mit fail_closed heisst das: Der Upload steht, und niemand weiss
     * warum. Der haeufigste Fall im Docker-Kanal ist ein Socket, der nie in den
     * Container gereicht wurde.
     * ─────────────────────────────────────────────────────────────────────────
     */
    #[Test]
    public function the_connection_can_be_tested_before_anything_is_uploaded(): void
    {
        $socket = $this->startFakeClamd('clean');

        // Die Fassung UND der Signaturstand -- ein clamd mit monatealten
        // Signaturen antwortet auf ein blosses PING genauso freundlich.
        $this->assertStringContainsString('ClamAV', $this->scanner($socket)->ping());
    }

    #[Test]
    public function a_test_against_nobody_says_where_it_knocked(): void
    {
        /*
         * Die Adresse gehoert in die Meldung: Daran erkennt man den falschen
         * Pfad oder den fehlenden Socket-Durchreicher, und ohne sie bleibt nur
         * Raten.
         */
        $scanner = $this->scanner($this->dir.'/gibt-es-nicht.sock');

        $this->assertStringContainsString('gibt-es-nicht.sock', $scanner->endpoint());

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/not reachable/');

        $scanner->ping();
    }

    /**
     * Ein stehengebliebener Servereintrag darf den Socket nicht überstimmen.
     *
     * Genau das tat er: Der Scanner nimmt TCP, sobald ein Host gesetzt ist. Wer
     * einmal einen Server eingetragen und später auf den Socket umgestellt
     * hatte, fragte weiter den Server -- die Einstellung sagte das eine,
     * gefragt wurde das andere.
     */
    #[Test]
    public function the_chosen_transport_decides_and_not_a_leftover_host(): void
    {
        config()->set('aeronance.documents.scanner', 'clamav');
        config()->set('aeronance.documents.clamav.transport', 'socket');
        config()->set('aeronance.documents.clamav.socket', '/var/run/clamav/clamd.ctl');
        config()->set('aeronance.documents.clamav.host', 'ein-alter-eintrag');
        app()->forgetInstance(VirusScanner::class);

        $scanner = app(VirusScanner::class);
        $this->assertInstanceOf(ClamAvScanner::class, $scanner);
        $this->assertSame('unix:///var/run/clamav/clamd.ctl', $scanner->endpoint());

        // Und andersherum gilt der Server.
        config()->set('aeronance.documents.clamav.transport', 'tcp');
        app()->forgetInstance(VirusScanner::class);

        $this->assertSame('tcp://ein-alter-eintrag:3310', app(VirusScanner::class)->endpoint());
    }

    private function scanner(string $socket, bool $failClosed = true): ClamAvScanner
    {
        return new ClamAvScanner(
            socket: $socket,
            host: null,
            port: 3310,
            timeout: 5,
            failClosed: $failClosed,
        );
    }

    /**
     * Starts the stand-in and waits until it is actually listening.
     */
    private function startFakeClamd(string $mode): string
    {
        $socket = $this->dir.'/clamd.sock';

        $process = proc_open(
            [PHP_BINARY, base_path('tests/Fixtures/fake-clamd.php'), $socket, $mode],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );

        if (! is_resource($process)) {
            $this->markTestSkipped('Could not start the stand-in clamd.');
        }

        $this->processes[] = $process;

        // Wait for its "ready" line rather than sleeping and hoping.
        stream_set_blocking($pipes[1], true);
        $ready = fgets($pipes[1]);

        if ($ready === false || ! str_contains($ready, 'ready')) {
            $this->markTestSkipped('The stand-in clamd did not come up: '.stream_get_contents($pipes[2]));
        }

        return $socket;
    }

    private function write(string $name, string $content): string
    {
        $path = $this->dir.'/'.$name;
        file_put_contents($path, $content);

        return $path;
    }
}
