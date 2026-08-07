<?php

declare(strict_types=1);

namespace App\Core\Documents;

/**
 * The file types a certificate may be.
 *
 * Three, and no more. Every addition has to earn its place, because an upload
 * field that accepts one more thing eventually accepts the wrong thing. SVG is
 * absent on purpose -- it is a document format that executes script, and no
 * scan of a Form 1 was ever an SVG.
 *
 * Each case carries its own magic bytes rather than a MIME string, because the
 * MIME type is what somebody CLAIMS and the magic bytes are what the file IS.
 */
enum DocumentType: string
{
    case Pdf = 'application/pdf';
    case Jpeg = 'image/jpeg';
    case Png = 'image/png';

    /**
     * The bytes a file of this type must begin with.
     */
    public function signature(): string
    {
        return match ($this) {
            self::Pdf => '%PDF-',
            self::Jpeg => "\xFF\xD8\xFF",
            self::Png => "\x89PNG\r\n\x1A\n",
        };
    }

    /** @return list<string> */
    public function extensions(): array
    {
        return match ($this) {
            self::Pdf => ['pdf'],
            self::Jpeg => ['jpg', 'jpeg'],
            self::Png => ['png'],
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Pdf => 'PDF',
            self::Jpeg => 'JPEG',
            self::Png => 'PNG',
        };
    }

    /**
     * Recognises a type from the start of a file, or nothing.
     */
    public static function fromContent(string $head): ?self
    {
        foreach (self::cases() as $type) {
            if (str_starts_with($head, $type->signature())) {
                return $type;
            }
        }

        return null;
    }

    public static function fromExtension(string $extension): ?self
    {
        $extension = strtolower(ltrim($extension, '.'));

        foreach (self::cases() as $type) {
            if (in_array($extension, $type->extensions(), strict: true)) {
                return $type;
            }
        }

        return null;
    }

    /** @return list<string> every accepted MIME type, for form fields */
    public static function mimeTypes(): array
    {
        return array_map(fn (self $t): string => $t->value, self::cases());
    }

    /** @return list<string> every accepted extension, with a leading dot */
    public static function fileExtensions(): array
    {
        return array_merge(...array_map(
            fn (self $t): array => array_map(fn (string $e): string => '.'.$e, $t->extensions()),
            self::cases(),
        ));
    }
}
