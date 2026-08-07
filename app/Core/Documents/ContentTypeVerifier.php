<?php

declare(strict_types=1);

namespace App\Core\Documents;

use App\Core\Documents\Exceptions\DocumentRejected;

/**
 * Decides what a file actually is, by reading it.
 *
 * the point, and it is the right one to insist on: checking a name proves
 * nothing. Somebody who wants to get a file past this is not going to be stopped
 * by having to rename it. (In fairness to what was already here, the name was
 * never the criterion -- both Filament and the media library ask finfo, which
 * reads content. But finfo is a guesser with a large table and a bias towards
 * saying something, and "it guessed pdf" is a weaker claim than "it starts with
 * %PDF- and ends with %%EOF".)
 *
 * So this reads the bytes itself and asks three questions:
 *
 *  1. Do the opening bytes match one of exactly three signatures?
 *  2. Does the file also END like that type -- is it structurally whole?
 *  3. Does the extension it arrived under agree with what it turned out to be?
 *
 * The third is not pedantry. A genuine JPEG named .pdf is not a mistake anyone
 * makes twice; it is what a polyglot looks like from outside, and refusing the
 * disagreement costs a rename and closes a whole family of tricks.
 *
 * What this deliberately does NOT do is search PDFs for /JavaScript or
 * /OpenAction. Those checks reject real documents produced by real software, and
 * the thing they are reaching for is handled properly elsewhere: the delivery
 * route sends a fixed Content-Type with nosniff and a sandbox policy, so a file
 * pretending to be something else cannot act on it. Guessing at intent is a poor
 * substitute for not giving the browser a choice.
 */
final class ContentTypeVerifier
{
    /** Enough for any signature, and for a look at what follows. */
    private const HEAD_BYTES = 1024;

    /** The tail searched for an end marker. */
    private const TAIL_BYTES = 4096;

    /**
     * @param  string|null  $claimedName  the file name it arrived under, if any
     *
     * @throws DocumentRejected
     */
    public function verify(string $path, ?string $claimedName = null): DocumentType
    {
        if (! is_file($path) || ! is_readable($path)) {
            throw DocumentRejected::notReadable();
        }

        $handle = @fopen($path, 'rb');

        if ($handle === false) {
            throw DocumentRejected::notReadable();
        }

        try {
            $head = (string) fread($handle, self::HEAD_BYTES);
            $size = filesize($path);
            $tail = $this->readTail($handle, (int) $size);
        } finally {
            fclose($handle);
        }

        // An empty file has no type, and would otherwise sail through every
        // "does it start with" check that uses a zero-length prefix.
        if ($head === '') {
            throw DocumentRejected::unknownType();
        }

        $type = DocumentType::fromContent($head);

        if ($type === null) {
            throw DocumentRejected::unknownType();
        }

        if (! $this->isStructurallyWhole($type, $tail)) {
            throw DocumentRejected::truncated($type->label());
        }

        if ($claimedName !== null) {
            $this->assertExtensionAgrees($type, $claimedName);
        }

        return $type;
    }

    /**
     * Whether the file ends the way its type should.
     *
     * Catches the half-transferred upload as well as the file that borrowed a
     * header. Deliberately searches the tail rather than demanding the marker at
     * the very last byte: real PDFs carry trailing newlines and real JPEGs carry
     * padding, and rejecting those would be rejecting the ordinary case.
     */
    private function isStructurallyWhole(DocumentType $type, string $tail): bool
    {
        return match ($type) {
            DocumentType::Pdf => str_contains($tail, '%%EOF'),
            DocumentType::Jpeg => str_contains($tail, "\xFF\xD9"),

            // PNG ends with an IEND chunk followed by its CRC.
            DocumentType::Png => str_contains($tail, 'IEND'),
        };
    }

    private function assertExtensionAgrees(DocumentType $type, string $claimedName): void
    {
        $extension = pathinfo($claimedName, PATHINFO_EXTENSION);

        if ($extension === '') {
            throw DocumentRejected::extensionMismatch($type->label(), '—');
        }

        $claimed = DocumentType::fromExtension($extension);

        if ($claimed !== $type) {
            throw DocumentRejected::extensionMismatch($type->label(), strtolower($extension));
        }
    }

    /**
     * @param  resource  $handle
     */
    private function readTail($handle, int $size): string
    {
        if ($size <= 0) {
            return '';
        }

        $offset = max(0, $size - self::TAIL_BYTES);
        fseek($handle, $offset);

        return (string) fread($handle, self::TAIL_BYTES);
    }
}
