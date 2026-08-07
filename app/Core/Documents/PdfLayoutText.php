<?php

declare(strict_types=1);

namespace App\Core\Documents;

use RuntimeException;

/**
 * A PDF's text with its columns still standing.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * WHY AN EXTERNAL BINARY, having deliberately chosen a pure-PHP parser before.
 *
 * The Blaues Buch aligns its columns by SPACE PADDING -- there are no tabs and
 * no table structure in the file, only glyphs at x-positions. A pure-PHP parser
 * reads the text objects in content order and concatenates them, which turns
 *
 *   4505/EN     Walter Mikron III     Walter Motorlet
 *
 * into "4505/ENW alter Mikron IIIW alter Motorlet". The columns are not damaged
 * -- they are gone, and nothing downstream can recover them. Measured: 0 rows
 * from the engine volume, 0 from the propellers, against 151 and 130 here.
 *
 * There is no pure-PHP equivalent of pdftotext's -layout. The choice was
 * therefore not between two libraries but between poppler-utils and entering
 * component Kennblätter by hand. Vorgabe: "wenn du binaries brauchst bau sie ein.
 * der Docker und lxc sollen ein paket sein, bei direkt installation muss halt
 * alles da sein."
 *
 * So poppler-utils is a prerequisite in all three delivery channels, documented
 * in docs/INFRASTRUKTUR.md. The Docker image and the LXC script install it; the
 * webserver pack lists it.
 *
 * IT IS CHECKED AT THE SEAM, not assumed. A missing binary must say so -- an
 * empty result would look exactly like a volume with nothing in it, which is
 * the failure this whole area keeps guarding against.
 * IT LIVES IN THE CORE, not with the Blaues Buch that first needed it. Reading a
 * PDF's columns is infrastructure, and the second caller arrived promptly: the
 * LTA/TM module reads the manufacturers' overview sheets the same way. A module
 * owning a tool another module needs is exactly the boundary CLAUDE.md warns
 * about -- and the core was already reaching into Fleet for it.
 * ─────────────────────────────────────────────────────────────────────────────
 */
final class PdfLayoutText
{
    public const BINARY = 'pdftotext';

    /**
     * Whether the system can read PDFs at all.
     *
     * Used by the setup check rather than by the parsing path -- there the
     * failure is an exception, because a caller that has already downloaded a
     * volume needs an answer, not a boolean.
     */
    public static function isAvailable(): bool
    {
        return self::locate() !== null;
    }

    /**
     * @throws RuntimeException if poppler-utils is missing or the file is unreadable
     */
    /**
     * @param  string|null  $mode  a pdftotext layout flag other than -layout
     */
    /**
     * The same, for a PDF that arrived over the network and was never a file.
     *
     * The NfL hands its bulletins out as bytes behind a one-shot token, so there
     * is nothing to point pdftotext at. Written to a temporary file and removed
     * again rather than piped, because pdftotext seeks within the document and
     * cannot read one from a stream.
     */
    public function fromString(string $pdf, ?string $mode = null): string
    {
        $path = tempnam(sys_get_temp_dir(), 'aeronance-pdf-');

        if ($path === false) {
            throw new RuntimeException('Es liess sich keine temporäre Datei anlegen.');
        }

        try {
            file_put_contents($path, $pdf);

            return $this->fromFile($path, $mode);
        } finally {
            @unlink($path);
        }
    }

    public function fromFile(string $path, ?string $mode = null): string
    {
        $binary = self::locate();

        if ($binary === null) {
            throw new RuntimeException(
                'pdftotext wurde nicht gefunden. Aeronance liest die Kennblatt-Listen '
                .'des LBA damit; ohne poppler-utils bleibt nur die Handeingabe. '
                .'Debian/Ubuntu: apt install poppler-utils.'
            );
        }

        /*
         * proc_open with an ARGUMENT ARRAY -- no shell is involved, so the path
         * cannot be read as an option or as a second command however it is
         * spelled. The path is ours (a tempnam) today, and this stays correct if
         * that ever stops being true.
         *
         * "-layout" is the entire point: it places the text according to the
         * glyphs' x-positions instead of content order, which is what keeps the
         * columns in the file readable as columns.
         */
        $process = proc_open(
            /*
             * -layout is the default and the right one for nearly every sheet.
             *
             * ─────────────────────────────────────────────────────────────────
             * A sheet whose columns nearly touch is the exception. Piper's index
             * sets the number one space from the subject, and -layout renders
             * that as ONE run of text ("2 Special Tubing in Fuselage") -- no
             * amount of column measuring recovers a boundary that is not in the
             * output. "-fixed 2" places characters on a fixed grid instead and
             * keeps them apart.
             *
             * It is not the default because the fixed grid distorts the sheets
             * that -layout reads correctly today: it pads every line to the page
             * width and splits a proportional word where the grid falls.
             * ─────────────────────────────────────────────────────────────────
             */
            [$binary, ...($mode !== null ? explode(' ', $mode) : ['-layout']), '-enc', 'UTF-8', '-nopgbrk', $path, '-'],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );

        if (! is_resource($process)) {
            throw new RuntimeException('pdftotext liess sich nicht starten.');
        }

        $text = (string) stream_get_contents($pipes[1]);
        $error = (string) stream_get_contents($pipes[2]);

        foreach ($pipes as $pipe) {
            fclose($pipe);
        }

        $status = proc_close($process);

        if ($status !== 0) {
            throw new RuntimeException(sprintf(
                'pdftotext brach mit Code %d ab: %s',
                $status,
                trim($error) !== '' ? trim($error) : 'keine Meldung',
            ));
        }

        return $text;
    }

    /**
     * The binary, or null.
     *
     * Looked up through the shell's own resolution rather than a hard-coded
     * path, because the three delivery channels put it in different places and a
     * guessed path would fail on two of them.
     */
    private static function locate(): ?string
    {
        static $cached = false;
        static $path = null;

        if ($cached) {
            return $path;
        }

        $cached = true;

        foreach (explode(PATH_SEPARATOR, (string) getenv('PATH')) as $dir) {
            $candidate = rtrim($dir, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.self::BINARY;

            if (is_file($candidate) && is_executable($candidate)) {
                return $path = $candidate;
            }
        }

        return $path = null;
    }
}
