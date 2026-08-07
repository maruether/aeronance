<?php

declare(strict_types=1);

namespace App\Core\Documents\Exceptions;

use RuntimeException;

/**
 * An upload that will not be accepted, and why.
 *
 * The message is shown to the person uploading, so it says what is wrong with
 * the file rather than which check failed. "This is not a PDF" is useful; "magic
 * byte mismatch at offset 0" is not.
 */
final class DocumentRejected extends RuntimeException
{
    public static function notReadable(): self
    {
        return new self(__('documents.rejected.unreadable'));
    }

    public static function unknownType(): self
    {
        return new self(__('documents.rejected.unknown_type'));
    }

    public static function truncated(string $type): self
    {
        return new self(__('documents.rejected.truncated', ['type' => $type]));
    }

    public static function extensionMismatch(string $actual, string $claimed): self
    {
        return new self(__('documents.rejected.extension_mismatch', [
            'actual' => $actual,
            'claimed' => $claimed,
        ]));
    }

    public static function tooBig(int $megabytes): self
    {
        return new self(__('documents.rejected.too_big', ['limit' => $megabytes]));
    }

    public static function infected(string $signature): self
    {
        return new self(__('documents.rejected.infected', ['signature' => $signature]));
    }

    public static function scannerUnavailable(string $reason): self
    {
        return new self(__('documents.rejected.scanner_unavailable', ['reason' => $reason]));
    }
}
