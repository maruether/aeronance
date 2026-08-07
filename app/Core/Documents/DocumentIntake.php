<?php

declare(strict_types=1);

namespace App\Core\Documents;

use App\Core\Documents\Exceptions\DocumentRejected;
use Illuminate\Support\Facades\Log;

/**
 * The one door every certificate comes through.
 *
 * Size, then type, then malware -- in that order, and the order is the point.
 * Checking the size first means a scanner is never handed something enormous;
 * checking the type before scanning means an obviously wrong file is refused
 * without waking clamd at all.
 *
 * Everything that stores a document goes through here. A second door would mean
 * a second set of rules, and the second set is always the one that is out of
 * date.
 */
final readonly class DocumentIntake
{
    public function __construct(
        private ContentTypeVerifier $verifier,
        private VirusScanner $scanner,
        private int $maxSizeMegabytes,
    ) {}

    /**
     * @param  string|null  $claimedName  the name it arrived under
     *
     * @throws DocumentRejected
     */
    public function accept(string $path, ?string $claimedName = null): DocumentType
    {
        if (! is_file($path) || ! is_readable($path)) {
            throw DocumentRejected::notReadable();
        }

        $size = (int) filesize($path);

        if ($size > $this->maxSizeMegabytes * 1024 * 1024) {
            throw DocumentRejected::tooBig($this->maxSizeMegabytes);
        }

        $type = $this->verifier->verify($path, $claimedName);

        $result = $this->scanner->scan($path);

        if (! $result->clean) {
            // Worth a log line of its own: somebody uploaded malware to the
            // club's records system, and that is a thing to find out about
            // rather than a validation message that scrolls away.
            Log::warning('Infected document refused', [
                'signature' => $result->signature,
                'claimed_name' => $claimedName,
                'size' => $size,
            ]);

            throw DocumentRejected::infected((string) $result->signature);
        }

        return $type;
    }

    public function maxSizeMegabytes(): int
    {
        return $this->maxSizeMegabytes;
    }

    public function scanningIsEnabled(): bool
    {
        return $this->scanner->isEnabled();
    }
}
