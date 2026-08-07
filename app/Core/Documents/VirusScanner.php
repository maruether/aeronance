<?php

declare(strict_types=1);

namespace App\Core\Documents;

/**
 * Something that can say whether a file is malicious.
 *
 * An interface rather than a class because the answer differs by installation:
 * a club LXC has no clamd, a Docker deployment has one in the next container,
 * and a Part-145 shop may one day want something else entirely.
 */
interface VirusScanner
{
    /**
     * @return ScanResult never throws for an infected file -- that is a result,
     *                    not an error. It throws only when it cannot answer.
     */
    public function scan(string $path): ScanResult;

    public function isEnabled(): bool;
}
