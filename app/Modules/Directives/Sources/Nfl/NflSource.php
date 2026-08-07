<?php

declare(strict_types=1);

namespace App\Modules\Directives\Sources\Nfl;

use App\Core\Documents\PdfLayoutText;
use App\Modules\Directives\Enums\Bindingness;
use App\Modules\Directives\Enums\DirectiveKind;
use App\Modules\Directives\Enums\SubjectKind;
use App\Modules\Directives\Sources\DirectiveRow;
use App\Modules\Directives\Sources\DirectiveSource;
use App\Modules\Directives\Sources\SecondaryList;
use App\Modules\Directives\Sources\SinglePageSource;
use RuntimeException;

/**
 * The German gazette as a directive source -- and the second opinion.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * WHY THIS EXISTS BESIDE easa-ad.yaml. the requirement was for a second route "zum
 * einen zum abgleich, zum anderen weil er auch andere behörden umfassen sollte",
 * and the gazette delivers both. One bulletin carries directives from FOUR
 * authorities side by side:
 *
 *   D-2026-152    EASA AD 2026-0132        AIRBUS HELICOPTERS
 *   D-2024-167R1  UK CAA AD G-2026-0002    BAE SYSTEMS
 *   D-2026-046R2  FAA AD 2026-14-11        The Boeing Company
 *   D-2026-114R1  TC Emergency AD …        Pratt & Whitney Canada
 *
 * The national LTA number on the left is the one a German inspector asks by, and
 * it exists nowhere else -- not in EASA's tool, not at the manufacturer.
 *
 * A CLASS RATHER THAN A SPEC, deliberately. SourceSpec says it plainly: the
 * configured driver reads manufacturers who publish a table, and "somebody who
 * publishes a PDF, or a JavaScript-rendered list, still needs a class". This is
 * both at once -- a JavaScript grid whose rows point at PDFs -- and on top of
 * that a session that has to be carried from call to call.
 *
 * TWO DEPTHS. By default a window of the newest bulletins -- that is the order
 * the gazette itself uses, and a club wants what has come out since the last
 * run. On request the whole archive: roughly 664 of the 9838 entries are
 * directive bulletins, each a separate PDF, and it is the only way to fill a
 * fresh installation with the directives that were published before it existed.
 * Reached with `aeronance:refresh-directives --source=nfl --all-types`.
 * ─────────────────────────────────────────────────────────────────────────────
 */
final class NflSource implements DirectiveSource, SecondaryList, SinglePageSource
{
    /** NfL Teil II carries what concerns aircraft; Teil I is airspace and aerodromes. */
    private const PART = '2';

    /** How a directive bulletin is titled. The gazette shouts it. */
    private const BULLETIN = '/LUFTT[ÜU]CHTIGKEITSANWEISUNGEN/iu';

    /**
     * A row of the printed table.
     *
     * Four columns: the national number, the authority's own number, the holder
     * and the type certificate. The authority number is what makes the
     * cross-check possible, so a row without one is still kept -- it is then a
     * purely national directive, which is precisely the kind no other source in
     * this module has.
     */
    private const ROW = '/^\s*(D-\d{4}-\d+(?:R\d+)?)\s+(.*)$/u';

    public function __construct(
        private readonly NflClient $client = new NflClient,
        private readonly PdfLayoutText $pdf = new PdfLayoutText,
        private readonly int $bulletins = 6,
    ) {}

    public function name(): string
    {
        return 'nfl';
    }

    public function label(): string
    {
        return 'Nachrichten für Luftfahrer (LTA)';
    }

    public function isAutomatic(): bool
    {
        return true;
    }

    /**
     * @param  array{model?: string, url?: string, all?: bool}  $options
     * @return list<DirectiveRow>
     */
    public function fetch(array $options = []): array
    {
        /*
         * THE WINDOW, OR THE WHOLE ARCHIVE.
         *
         * Ordinarily: enough entries to find the wanted number of bulletins in.
         * Roughly one in fifteen is a directive bulletin, so the list is walked
         * with room to spare rather than guessed at exactly, newest first --
         * which is what a club wants weekly.
         *
         * With all: everything the gazette has. Measured on the live service,
         * that is 664 bulletins out of 9838 entries, each a separate PDF, and it
         * takes the better part of an hour. It exists because the weekly window
         * cannot fill an EMPTY installation: a club setting the module up needs
         * the directives that were published before it existed, and those are
         * exactly the ones still in force. Once. Then the window suffices.
         */
        $everything = ($options['all'] ?? false) === true;
        $entries = $this->client->entries($everything ? PHP_INT_MAX : $this->bulletins * 20);

        $wanted = [];

        foreach ($entries as $entry) {
            if ($entry['part'] === self::PART && preg_match(self::BULLETIN, $entry['title']) === 1) {
                $wanted[] = $entry;
            }

            if (! $everything && count($wanted) >= $this->bulletins) {
                break;
            }
        }

        if ($wanted === []) {
            throw new RuntimeException(
                'In den NfL steht keine einzige Bekanntmachung von '
                .'Lufttüchtigkeitsanweisungen. Das gibt es nicht -- entweder hat die DFS '
                .'die Liste umgebaut, oder die Sitzung liefert nicht, was sie soll.',
            );
        }

        $rows = [];

        foreach ($wanted as $entry) {
            foreach ($this->rowsIn($this->client->document($entry['id']), $entry) as $row) {
                $rows[] = $row;
            }
        }

        return $rows;
    }

    /**
     * The directives printed in one bulletin.
     *
     * @param  array{id: string, number: string, part: string, issued: string, title: string}  $entry
     * @return list<DirectiveRow>
     */
    private function rowsIn(string $pdf, array $entry): array
    {
        $text = $this->pdf->fromString($pdf);
        $rows = [];
        $seen = [];
        $lines = preg_split('/\R/', $text) ?: [];

        foreach ($lines as $index => $line) {
            if (preg_match(self::ROW, $line, $found) !== 1) {
                continue;
            }

            /*
             * FIRST OCCURRENCE WINS, and that is not tidiness.
             *
             * A bulletin prints the summary table up front and then REPRINTS
             * every directive in full behind it. The number appears again in
             * those pages, where the columns beside it are the running head and
             * the page number -- taking the later hit gave D-2024-199R1 the
             * title "275/2026". The table comes first, so the first hit is the
             * table's.
             */
            if (isset($seen[$found[1]])) {
                continue;
            }

            $seen[$found[1]] = true;

            /*
             * Three cells to the right of the number: the authority's own
             * number, the approval holder, and the type certificates.
             */
            $parts = preg_split('/\s{2,}/', trim($found[2])) ?: [];
            $authority = trim($parts[0] ?? '');
            $holder = trim($parts[1] ?? '');
            $types = trim(implode(' ', array_slice($parts, 2)));

            /*
             * The holder's name wraps often enough to matter ("ROLLS-ROYCE" and
             * "DEUTSCHLAND Ltd & Co KG" on the next line): a row cut in half
             * reads as a different manufacturer. The continuation is appended to
             * the HOLDER and nowhere else -- appending it to the whole rest is
             * what once produced "ROLLS-ROYCE EASA.E.036 DEUTSCHLAND Ltd".
             *
             * Only a line that carries no number of its own qualifies, and only
             * where it is indented into the table rather than starting at the
             * margin.
             */
            $next = $this->continuation($lines, $index);

            if ($next !== null) {
                $continued = preg_split('/\s{2,}/', trim($next)) ?: [];
                $holder = trim($holder.' '.trim($continued[0] ?? ''));

                if (isset($continued[1])) {
                    $types = trim($types.' '.trim($continued[1]));
                }
            }

            $subject = $holder;

            $rows[] = new DirectiveRow(
                number: $found[1],
                title: $subject !== '' ? $subject : $found[1],
                kind: DirectiveKind::Lta,
                subjectKind: SubjectKind::AircraftModel,

                /*
                 * Always binding, and not a default reached for want of better:
                 * the gazette publishes these under § 14 LuftBO, which is what
                 * makes them binding in Germany in the first place.
                 */
                bindingness: Bindingness::Mandatory,
                issuer: 'LBA / NfL',
                summary: trim(sprintf(
                    'NfL %s vom %s%s',
                    $entry['number'],
                    $entry['issued'],
                    $types !== '' ? "\nGeräte-Nummern: ".$types : '',
                )),
                issuedAt: $this->date($entry['issued']),

                /*
                 * THE KENNBLATT, and it is what makes these rows land on an
                 * aircraft at all.
                 *
                 * Vorgabe: "die kennblattnummer ist im kfz typ im flottenmodul
                 * hinterlegt." The gazette names the holder and the type
                 * certificate ("EASA.A.189"), never the model -- so the model
                 * cannot be read here, but it can be LOOKED UP: aircraft_types
                 * carries the same number in type_certificate, in the same
                 * notation the authority writes.
                 */
                subjectDesignation: $types !== '' ? $types : null,
                externalReference: $authority !== '' ? $authority : null,
            );
        }

        return $rows;
    }

    /**
     * The line that finishes a wrapped row, past the furniture between them.
     *
     * ─────────────────────────────────────────────────────────────────────────
     * The gazette prints its own date and page number BETWEEN the rows -- "28
     * JUL 2026" on one line, "275/2026" on another -- so the continuation of a
     * holder's name is not reliably the next line. Taking it blindly gave
     * D-2024-199R1 the title "275/2026": a page number where a manufacturer
     * belongs, and nothing about it would have looked wrong in a list.
     *
     * Three lines of lookahead, furniture skipped by shape, and the search stops
     * at the next numbered row so a continuation can never be borrowed from the
     * entry below.
     *
     * @param  list<string>  $lines
     */
    private function continuation(array $lines, int $index): ?string
    {
        for ($i = $index + 1; $i <= $index + 3; $i++) {
            $line = $lines[$i] ?? null;

            if ($line === null || preg_match(self::ROW, $line) === 1) {
                return null;
            }

            if (trim($line) === ''
                || preg_match('/^\s*\d{1,3}\s*\/\s*\d{4}\s*$/', $line) === 1
                || preg_match('/^\s*\d{1,2}\s+[A-ZÄÖÜ]{3}\s+\d{4}\s*$/u', $line) === 1) {
                continue;
            }

            return preg_match('/^\s{20,}\S/', $line) === 1 ? $line : null;
        }

        return null;
    }

    private function date(string $german): ?string
    {
        return preg_match('/(\d{2})\.(\d{2})\.(\d{4})/', $german, $m) === 1
            ? sprintf('%s-%s-%s', $m[3], $m[2], $m[1])
            : null;
    }
}
