<?php

declare(strict_types=1);

namespace App\Core\Documents;

use App\Core\Documents\Exceptions\DocumentRejected;
use RuntimeException;

/**
 * Hands a file to clamd and reads the verdict.
 *
 * Speaks the daemon's INSTREAM protocol over a socket rather than shelling out
 * to clamscan, for three reasons that all matter here:
 *
 *  - clamscan loads the whole signature database on every call, which takes
 *    seconds and a great deal of memory. clamd already has it loaded.
 *  - There is no command line, so there is no command line to get wrong. A file
 *    path never becomes part of a shell string.
 *  - A socket works the same whether clamd is on this host (Unix socket) or in
 *    the next container (TCP), which is exactly the difference between the LXC
 *    and the Docker channel.
 *
 * The protocol is small: send "zINSTREAM\0", then the file as length-prefixed
 * chunks, then a zero length to end it. The reply is one line.
 */
final class ClamAvScanner implements VirusScanner
{
    /** clamd's default StreamMaxLength is 25M; stay under it per chunk. */
    private const CHUNK = 8192;

    public function __construct(
        private readonly ?string $socket,
        private readonly ?string $host,
        private readonly int $port,
        private readonly int $timeout,
        private readonly bool $failClosed,
    ) {}

    public function isEnabled(): bool
    {
        return true;
    }

    /**
     * Anfragen, ob da wirklich einer ist -- und wer.
     *
     * ─────────────────────────────────────────────────────────────────────────
     * Der Knopf „Verbindung pruefen" in den Einstellungen haengt hier dran, aus
     * demselben Grund wie der Testversand beim Mailzugang: Eine Einstellung,
     * deren Wirkung man erst beim naechsten Upload bemerkt, wird falsch
     * eingetragen und bleibt es. Der haeufigste Fall im Docker-Kanal ist ein
     * Socket, der nicht in den Container gereicht wurde -- von aussen sieht das
     * aus wie „eingeschaltet".
     *
     * VERSION statt PING, weil die Antwort mehr sagt als „da": Sie nennt die
     * Fassung UND den Signaturstand. Ein clamd mit monatealten Signaturen
     * antwortet auf PING genauso freundlich wie einer mit frischen.
     * ─────────────────────────────────────────────────────────────────────────
     *
     * @throws RuntimeException wenn niemand antwortet
     */
    public function ping(): string
    {
        $socket = $this->connect();

        try {
            $this->write($socket, "zVERSION\0");

            $antwort = '';

            while (! feof($socket)) {
                $teil = fread($socket, 256);

                if ($teil === false || $teil === '') {
                    break;
                }

                $antwort .= $teil;
            }
        } finally {
            fclose($socket);
        }

        $antwort = trim(str_replace("\0", '', $antwort));

        if ($antwort === '') {
            throw new RuntimeException('clamd answered, but said nothing.');
        }

        return $antwort;
    }

    /** Wohin gefragt wird -- fuer die Rueckmeldung an den Betreiber. */
    public function endpoint(): string
    {
        return $this->address();
    }

    public function scan(string $path): ScanResult
    {
        try {
            $response = $this->stream($path);
        } catch (RuntimeException $e) {
            // A scanner that was switched on and cannot answer is not a pass.
            // Failing open here would mean the check quietly disables itself the
            // moment the daemon dies -- the state one is least likely to notice.
            if ($this->failClosed) {
                throw DocumentRejected::scannerUnavailable($e->getMessage());
            }

            return ScanResult::notScanned();
        }

        if (str_contains($response, 'FOUND')) {
            return ScanResult::infected($this->signatureFrom($response));
        }

        if (str_contains($response, 'OK')) {
            return ScanResult::clean();
        }

        if ($this->failClosed) {
            throw DocumentRejected::scannerUnavailable($response);
        }

        return ScanResult::notScanned();
    }

    private function stream(string $path): string
    {
        $handle = @fopen($path, 'rb');

        if ($handle === false) {
            throw new RuntimeException('the file could not be read');
        }

        $socket = $this->connect();

        try {
            $this->write($socket, "zINSTREAM\0");

            while (! feof($handle)) {
                $chunk = fread($handle, self::CHUNK);

                if ($chunk === false || $chunk === '') {
                    break;
                }

                // Four bytes of length, big endian, then the data.
                $this->write($socket, pack('N', strlen($chunk)).$chunk);
            }

            // A zero length ends the stream.
            $this->write($socket, pack('N', 0));

            $response = '';

            while (! feof($socket)) {
                $part = fread($socket, 1024);

                if ($part === false || $part === '') {
                    break;
                }

                $response .= $part;
            }

            return trim(str_replace("\0", '', $response));
        } finally {
            fclose($handle);
            fclose($socket);
        }
    }

    /**
     * @return resource
     */
    private function connect()
    {
        $address = $this->address();

        $socket = @stream_socket_client($address, $code, $message, $this->timeout);

        if ($socket === false) {
            throw new RuntimeException(sprintf(
                'clamd is not reachable at %s (%s)',
                $address,
                $message !== '' ? $message : 'no reason given',
            ));
        }

        stream_set_timeout($socket, $this->timeout);

        return $socket;
    }

    /**
     * Socket oder TCP -- und der Vorrang ist eine Falle, die benannt gehoert.
     *
     * Ein gesetzter Host gewinnt. Wer einmal einen Server eingetragen und
     * spaeter auf den Socket umgestellt hat, laesst sonst einen Wert stehen,
     * der still weiterwirkt. Deshalb entscheidet in den Einstellungen seit
     * 0.1.9 ein eigenes Feld „Anschluss", und der Provider gibt bei „Socket"
     * gar keinen Host mehr her.
     */
    private function address(): string
    {
        return $this->host !== null && $this->host !== ''
            ? sprintf('tcp://%s:%d', $this->host, $this->port)
            : sprintf('unix://%s', (string) $this->socket);
    }

    /**
     * @param  resource  $socket
     */
    private function write($socket, string $data): void
    {
        $written = @fwrite($socket, $data);

        if ($written === false || $written < strlen($data)) {
            throw new RuntimeException('the connection to clamd broke while sending');
        }
    }

    /**
     * "stream: Eicar-Signature FOUND" -> "Eicar-Signature"
     */
    private function signatureFrom(string $response): string
    {
        if (preg_match('/:\s*(.+?)\s+FOUND/', $response, $matches) === 1) {
            return $matches[1];
        }

        return 'unknown';
    }
}
