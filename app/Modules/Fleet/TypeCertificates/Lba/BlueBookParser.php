<?php

declare(strict_types=1);

namespace App\Modules\Fleet\TypeCertificates\Lba;

use App\Core\Documents\LayoutTable;
use App\Modules\Fleet\Models\AircraftType;
use App\Modules\Fleet\TypeCertificates\TypeCertificateCandidate;

/**
 * Reading the Blaues Buch.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * WHY THIS DOCUMENT IS WORTH A PDF PARSER: one download lists every aircraft
 * registrable in Germany with BOTH numbers side by side --
 *
 *   339/SP   ASK 21   Schleicher   ASK 21   6 (2/90)   EASA.A.221
 *
 * The German Kennblatt number AND the EASA reference, in one row. Searching
 * EASA's library per type gets the second only, one request at a time.
 *
 * WHICH VOLUMES IT CAN READ, established by trying all of them:
 *
 *   aircraft (gliders, powered sailplanes, aeroplanes)  -- yes, cleanly
 *   tow releases                                        -- partially, see below
 *   engines, propellers                                 -- NO
 *
 * The engine volume's text layer has no separators at all. Extraction yields
 * "Piston Engines4502/ENPorsche 678Dr. Ing. H.c. F. Porsche KG678/1" -- fields
 * concatenated without a space, and "Walter" broken into "W alter". Nothing can
 * recover columns from that, and the propeller volume behaves the same way.
 *
 * The coupling volume separates columns properly but WRAPS its designations:
 * "Sicherheitskupplung" on the row line, "Europa G 88" on the next, mixed with
 * the manufacturer using single spaces. An attempt to complete those from the
 * approved-models column worked for couplings and broke the aircraft volumes --
 * it turned "SF 34" into "SF 34 B", promoting a variant to the type. A rule that
 * damages the volumes that already work is the wrong rule, so it was removed
 * rather than special-cased.
 *
 * Consequence: component types are entered by hand, with the Kennblatt number
 * typed from the document. The brief, on this exact possibility: "wenn das nicht
 * geht dann machen wir es halt manuell." Three couplings named
 * "Sicherheitskupplung" would be worse than three typed correctly.
 *
 * WHAT IT DOES NOT TRY TO DO. The layout is genuinely messy: a row spans one to
 * four lines, hyphenated designations arrive split ("SZD" + "-48-1"), and a
 * multi-variant type carries several models and several revision numbers in
 * parallel columns. So the parser takes the four fields it can read reliably --
 * Kennblatt number, designation, manufacturer, EASA reference -- and leaves the
 * variant and revision columns alone. Reading those wrong would be worse than not
 * reading them: a revision number attached to the wrong variant is a fact nobody
 * can check without the document open.
 * ─────────────────────────────────────────────────────────────────────────────
 */
final class BlueBookParser
{
    /**
     * A Kennblatt number, in every notation the volumes use.
     *
     * ─────────────────────────────────────────────────────────────────────────
     * Four formats, checked against the real documents rather than assumed:
     *
     *   339/SP        gliders
     *   4502/EN       engines
     *   4509A/EN      engines -- a letter ON the number, for a reissue
     *   60.230/2      tow releases -- dotted, and no letters at all
     *   32.100/1/PR   propellers -- dotted, with a third segment
     *
     * The first version of this parser only knew "digits + /LETTERS", so it read
     * the aircraft volumes and would have silently found NOTHING in the component
     * ones. Silently, because a volume with no matching rows looks exactly like a
     * volume that parsed cleanly.
     *
     * The second version forbade a letter on the number segment and dropped six
     * engines -- 4509A/EN, 4519A/EN, 4524A/EN, 4561A/EN, 4563A/EN, 7007A/EN. 151
     * rows read out of 157, and not one complaint: a row that fails the pattern
     * is simply not a row. That is why the tests count against a number taken
     * from the document, never against "more than a hundred".
     * ─────────────────────────────────────────────────────────────────────────
     */
    private const KENNBLATT = '#^\d{1,5}[A-Za-z]?(?:\.\d{1,4})?(?:/[A-Za-z0-9]{1,4}){1,2}$#u';

    /**
     * @return list<TypeCertificateCandidate>
     */
    public function parse(string $text): array
    {
        return array_map(
            fn (array $block): TypeCertificateCandidate => $block['candidate'],
            $this->blocks($text),
        );
    }

    /**
     * Each row with the raw text it spans.
     *
     * ─────────────────────────────────────────────────────────────────────────
     * POSITIONAL, not keyed by Kennblatt number -- and that is not a detail.
     *
     * In the component volumes one certificate covers several products:
     * 60.230/1 is Bugkupplung E 72, E 75 AND E 85, three rows sharing a number.
     * The first version keyed blocks by that number, so two of the three would
     * have overwritten each other and the third would have carried whichever EASA
     * reference happened to be last.
     * ─────────────────────────────────────────────────────────────────────────
     *
     * @return list<array{candidate: TypeCertificateCandidate, text: string}>
     */
    public function blocks(string $text): array
    {
        $lines = preg_split('/\R/', $text) ?: [];

        $blocks = [];
        $current = null;
        $columnsOf = [];

        foreach ($lines as $line) {
            $segments = LayoutTable::segments($line);
            $columns = array_map(fn (array $s): string => $s['text'], $segments);
            $number = $this->kennblattIn($columns);

            if ($number !== null) {
                if ($current !== null) {
                    $blocks[] = $this->finish($current, $columnsOf);
                }

                $current = ['number' => $number, 'text' => $line];
                $columnsOf = $segments;

                continue;
            }

            if ($current === null) {
                continue;
            }

            $current['text'] .= "\n".$line;

            /*
             * A wrapped cell, put back together by WHERE it sits.
             *
             * ─────────────────────────────────────────────────────────────────
             * The volumes wrap long cells onto the next line, and the wrapped
             * part is indented to its own column:
             *
             *   53/SP   Habicht E   Deutsche
             *                       Forschungsanstalt für
             *                       Segelflug e.V.
             *
             * Taking only the row line loses everything after "Deutsche".
             * Appending the whole continuation line is worse -- 103/SP carries a
             * SECOND variant on its continuation, so the manufacturer would
             * collect a model number.
             *
             * Matching on the starting column solves both, and it is the reason
             * -layout is worth an external binary: it is the one extraction that
             * still knows where a piece of text sat. Nothing else here could be
             * written at all without that.
             * ─────────────────────────────────────────────────────────────────
             */
            $columnsOf = LayoutTable::stitch($columnsOf, $segments);
        }

        if ($current !== null) {
            $blocks[] = $this->finish($current, $columnsOf);
        }

        return array_values(array_filter(
            $blocks,
            fn (?array $block): bool => $block !== null,
        ));
    }

    /**
     * A finished block, or null if its row was not one.
     *
     * @param  array{number: string, text: string}  $current
     * @param  list<array{text: string, column: int}>  $columnsOf
     * @return array{candidate: TypeCertificateCandidate, text: string}|null
     */
    private function finish(array $current, array $columnsOf): ?array
    {
        $candidate = $this->toCandidate(
            array_map(fn (array $s): string => $s['text'], $columnsOf),
            $current['number'],
        );

        return $candidate === null
            ? null
            : ['candidate' => $candidate, 'text' => $current['text']];
    }

    /**
     * @param  list<string>  $columns
     */
    private function toCandidate(array $columns, string $number): ?TypeCertificateCandidate
    {
        $designation = isset($columns[1]) ? $this->clean($columns[1]) : '';

        if ($designation === '') {
            return null;
        }

        // The header repeats itself on every page of the PDF.
        if (preg_match('/^(Kennblatt|TCDS|Gerät|Type of|Approval)/ui', $designation) === 1) {
            return null;
        }

        return new TypeCertificateCandidate(
            designation: $designation,
            certificate: $number,
            authority: AircraftType::AUTHORITY_LBA,
            manufacturer: isset($columns[2]) ? ($this->clean($columns[2]) ?: null) : null,

            // No document URL: the Blaues Buch is a list, not a set of data
            // sheets. It points at the EASA reference, and that is where the
            // sheet lives -- see easaReference() and the source's note.
            dataSheetUrl: null,
            pageUrl: null,
        );
    }

    /**
     * The Kennblatt number in a row's first column, if it holds one.
     *
     * Checked on the SPLIT column rather than with a regex over the raw line: the
     * extractor breaks "339/SP" into "339" + "/SP" with a tab, and the four
     * notations differ enough that matching the joined column is the only version
     * that stays readable.
     *
     * @param  list<string>  $columns
     */
    private function kennblattIn(array $columns): ?string
    {
        if ($columns === []) {
            return null;
        }

        $first = $this->clean(str_replace(' ', '', $columns[0]));

        return preg_match(self::KENNBLATT, $first) === 1 ? $first : null;
    }

    public function easaReference(string $blockText): ?string
    {
        /*
         * The document writes several legitimate forms -- EASA.A.221,
         * EASA.SAS.A.028, EASA.IM.A.120 -- plus the occasional EASA A.038 where a
         * dot was typed as a space. On top of that the text extractor splits words
         * with tabs, so "EASA.SAS.A.024" can arrive as "EASA S" + "AS.A.024".
         *
         * So: drop the extractor's tabs, then turn remaining spaces into the dots
         * they should have been. Nothing more. The first version flattened
         * everything and re-inserted a dot per letter, which turned
         * EASA.SAS.A.024 into EASASAS.A.024 -- inventing structure where the
         * document already had it right.
         */
        if (preg_match('/EASA[A-Za-z0-9.\t\x{00A0} ]{0,20}\d{2,4}/u', $blockText, $m) !== 1) {
            return null;
        }

        $value = str_replace(["\t", "\u{00A0}"], ['', ' '], $m[0]);
        $value = preg_replace('/\s+/u', '.', trim($value)) ?? $value;
        $value = preg_replace('/\.{2,}/', '.', $value) ?? $value;

        return rtrim($value, '.');
    }

    /**
     * One line into its table columns.
     *
     * ─────────────────────────────────────────────────────────────────────────
     * TWO SEPARATOR STYLES, and the style is detected per line rather than
     * guessed once.
     *
     * The aircraft volumes come out of the extractor with columns separated by
     * tab-space-tab and words WITHIN a column split by a bare tab -- so columns
     * break on the former and a column's pieces are joined with nothing, which is
     * what puts "SZD" and "-48-1" back together.
     *
     * The component volumes have no tabs at all: they separate columns with runs
     * of spaces. That is why the first version found 156 rows in the glider volume
     * and ZERO in the coupling one -- silently, because a volume with no matching
     * rows looks exactly like one that parsed cleanly.
     *
     * Handling both with one regex was tried and rejected: the aircraft volumes
     * contain double spaces INSIDE a designation ("SZD-48-1  „Jantar Standard 2"),
     * so splitting those on runs of spaces would break names apart. Detecting the
     * style per line keeps each volume read the way it is actually written.
     * ─────────────────────────────────────────────────────────────────────────
     *
     * @return list<string>
     */
    private function columns(string $line): array
    {
        /*
         * The style is decided by the RESULT, not by whether a tab appears
         * anywhere. That distinction cost a debugging round: the coupling volume
         * separates columns with spaces but leaves trailing tabs at the end of
         * each line, so "contains a tab" sent those lines down the tab path,
         * which returned the whole line as one column and dropped the row.
         */
        $tabbed = preg_split('/\t[ \x{00A0}]+\t/u', $line) ?: [];

        if (count($tabbed) > 1) {
            return array_values(array_map(
                fn (string $part): string => trim(str_replace("\t", '', $part)),
                $tabbed,
            ));
        }

        $spaced = preg_split('/[ \x{00A0}]{2,}/u', trim(str_replace("\t", '  ', $line))) ?: [];

        return array_values(array_filter(
            array_map('trim', $spaced),
            fn (string $part): bool => $part !== '',
        ));
    }

    private function normaliseKennblatt(string $column): ?string
    {
        $flat = $this->clean(str_replace(' ', '', $column));

        return preg_match('#^(\d{1,5}/[A-Z]{1,4})#u', $flat, $m) === 1 ? $m[1] : null;
    }

    private function clean(string $value): string
    {
        $value = str_replace(["\u{00A0}", "\t"], ' ', $value);

        return trim(preg_replace('/\s+/u', ' ', $value) ?? $value);
    }
}
