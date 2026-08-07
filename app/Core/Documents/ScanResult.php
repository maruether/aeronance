<?php

declare(strict_types=1);

namespace App\Core\Documents;

/**
 * What a scanner found, or that it did not look.
 */
final readonly class ScanResult
{
    private function __construct(
        public bool $scanned,
        public bool $clean,
        public ?string $signature = null,
    ) {}

    public static function clean(): self
    {
        return new self(scanned: true, clean: true);
    }

    public static function infected(string $signature): self
    {
        return new self(scanned: true, clean: false, signature: $signature);
    }

    /** No scanner configured. Not a verdict, an absence of one. */
    public static function notScanned(): self
    {
        return new self(scanned: false, clean: true);
    }
}
