<?php

declare(strict_types=1);

namespace App\Core\Documents;

/**
 * No scanning.
 *
 * The default, and honest about it: it reports "not scanned" rather than
 * "clean", so nothing downstream can mistake an absent scanner for a passed
 * check.
 */
final class NullScanner implements VirusScanner
{
    public function scan(string $path): ScanResult
    {
        return ScanResult::notScanned();
    }

    public function isEnabled(): bool
    {
        return false;
    }
}
