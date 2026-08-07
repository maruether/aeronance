<?php

declare(strict_types=1);

namespace App\Core\Documents;

/**
 * A table that only exists as text at fixed positions.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * PDFs of this kind carry no table structure at all -- no cells, no separators,
 * only glyphs at x-positions and a person's eye to make columns of them.
 * pdftotext -layout keeps those positions, and everything below is the
 * consequence: a cell is a run of text starting at a known column, and a cell
 * that wrapped continues on the next line at the SAME column.
 *
 * That last part is the whole reason this is worth a class. Reading only the
 * row line loses everything after the first wrap -- a manufacturer called
 * "Deutsche Forschungsanstalt für Segelflug e.V." arrives as "Deutsche".
 * Appending the whole continuation line is worse, because the next row's second
 * variant lives there too, and the manufacturer collects a model number.
 *
 * Extracted from the Blaues Buch parser when the LTA/TM module needed the same
 * thing for the manufacturers' overview sheets. Two callers with the same
 * problem is the point at which a private method becomes a tool.
 * ─────────────────────────────────────────────────────────────────────────────
 */
final class LayoutTable
{
    /**
     * A line's cells, each with the column it starts in.
     *
     * Cells are separated by two or more spaces; a single space belongs to a
     * cell ("Habicht E", "Dr. Ing. H.c. F. Porsche KG"). Written as a match
     * rather than a split because the STARTING POSITION is needed, and a split
     * throws it away.
     *
     * The offset is counted in characters, not bytes -- one "für" in a row would
     * otherwise shift every column after it and stop matching its own
     * continuation lines.
     *
     * @return list<array{text: string, column: int}>
     */
    public static function segments(string $line): array
    {
        $line = str_replace(["\t", "\u{00A0}"], ' ', $line);

        if (preg_match_all('/\S+(?: \S+)*/u', $line, $matches, PREG_OFFSET_CAPTURE) === false) {
            return [];
        }

        return array_map(
            static fn (array $m): array => [
                'text' => $m[0],
                'column' => mb_strlen(substr($line, 0, $m[1])),
            ],
            $matches[0],
        );
    }

    /**
     * Appends a continuation line's cells to the row they belong to.
     *
     * Matched on the starting column, with one character of tolerance: a
     * proportional font can place a wrapped line a hair either side of its own
     * heading. Anything that lines up with no cell of the row is dropped rather
     * than guessed at -- it belongs to a column this row left empty, and there
     * is nothing to attach it to.
     *
     * @param  list<array{text: string, column: int}>  $row
     * @param  list<array{text: string, column: int}>  $continuation
     * @return list<array{text: string, column: int}>
     */
    public static function stitch(array $row, array $continuation, int $tolerance = 1): array
    {
        foreach ($continuation as $segment) {
            foreach ($row as $index => $cell) {
                if (abs($cell['column'] - $segment['column']) <= $tolerance) {
                    $row[$index]['text'] .= ' '.$segment['text'];

                    continue 2;
                }
            }
        }

        return $row;
    }

    /**
     * The cell sitting in a given column, if the row has one.
     *
     * NEAREST rather than exact, because the positions jitter by a character or
     * two between lines -- 18 on one row and 19 on the next for the same column.
     * Columns are fifteen or more characters apart, so nearest is unambiguous
     * and an exact match with a small tolerance is not: it silently returns
     * nothing when a row happens to sit two characters off.
     *
     * @param  list<array{text: string, column: int}>  $row
     */
    public static function at(array $row, int $column, int $tolerance = 6): ?string
    {
        $best = null;
        $distance = PHP_INT_MAX;

        foreach ($row as $cell) {
            $d = abs($cell['column'] - $column);

            if ($d < $distance && $d <= $tolerance) {
                $distance = $d;
                $best = $cell['text'];
            }
        }

        return $best;
    }

    /**
     * Where this document's columns REALLY are, measured in its body.
     *
     * ─────────────────────────────────────────────────────────────────────────
     * The header cannot be trusted for positions, only for meaning. pdftotext
     * shifts a column left when the cell beside it runs long, so a sheet whose
     * heading sits at 74 can carry its data at 65 -- and every lookup by header
     * position then reads an empty cell and reports a row with no subject.
     *
     * Measured instead: tally where text actually starts, merge positions a
     * character or two apart, and keep the ones that recur. A column is a place
     * where many lines begin text; a one-off indent is not.
     * ─────────────────────────────────────────────────────────────────────────
     *
     * @param  list<string>  $lines
     * @return list<int> ascending
     */
    public static function columnsIn(array $lines, int $minOccurrences = 5): array
    {
        $tally = [];

        foreach ($lines as $line) {
            foreach (self::segments($line) as $segment) {
                $tally[$segment['column']] = ($tally[$segment['column']] ?? 0) + 1;
            }
        }

        ksort($tally);

        $columns = [];
        $group = null;

        foreach ($tally as $column => $count) {
            if ($group !== null && $column - $group['last'] <= 2) {
                /*
                 * Same column, a character or two of drift. The LEFTMOST
                 * position wins, not the busiest.
                 *
                 * The busiest was tried first and truncated cells: LS4 puts most
                 * of its subjects at 74 and a few at 72, so a boundary at 74 cut
                 * "Musterzulassung" down to "sterzulassung". Cutting at the
                 * leftmost can only ever take a little whitespace with it, and
                 * that is trimmed anyway.
                 */
                $group['last'] = $column;
                $group['total'] += $count;

                continue;
            }

            if ($group !== null && $group['total'] >= $minOccurrences) {
                $columns[] = $group['column'];
            }

            $group = ['column' => $column, 'last' => $column, 'total' => $count];
        }

        if ($group !== null && $group['total'] >= $minOccurrences) {
            $columns[] = $group['column'];
        }

        return $columns;
    }

    /**
     * A line's cells, assigned to the columns they sit under.
     *
     * ─────────────────────────────────────────────────────────────────────────
     * Each run of text goes to the nearest column. The obvious alternative --
     * cutting the line at the boundaries -- was tried against four real sheets
     * and is worse: the true boundary sits somewhere in a gap that varies from
     * row to row, so a knife placed at the leftmost observed start drags the
     * previous cell's tail along ("ug auf Kundenwunsch"), and one placed at the
     * busiest start beheads the occasional row ("sterzulassung USA").
     * Assignment has no such edge, because it never has to decide where a cell
     * ENDS.
     *
     * Its one weakness is a cell overflowing into the next column with a single
     * space between them: the two read as one run and the column behind appears
     * empty. That case is repaired here rather than left, because it is
     * detectable -- a run that starts before a column and reaches past it is the
     * only thing that can produce it.
     * ─────────────────────────────────────────────────────────────────────────
     *
     * @param  list<int>  $columns  ascending
     * @return array<int, string> column => text
     */
    public static function assign(string $line, array $columns): array
    {
        $cells = [];

        /** @var array<int, int> column => where the text now sitting there began */
        $from = [];

        foreach (self::segments($line) as $segment) {
            $column = self::nearest($columns, $segment['column']);

            if ($column === null) {
                continue;
            }

            $start = $segment['column'];
            $end = $start + mb_strlen($segment['text']);

            /*
             * TWO CELLS CANNOT SHARE ONE COLUMN, and where they appear to, the
             * left one drifted.
             *
             * Nearest-column assignment assumes a cell begins at its column.
             * Scheibe centres the CONTENT inside the cell instead, so a short
             * entry floats towards the middle: on the Bergfalke III sheet the
             * "--" of LTA 82-216 sits at 25 with the number column at 15 and the
             * subject at 33, and nearest hands it to the subject -- where the
             * subject already is. The row then never starts, and its LTA sticks
             * to the row above as "--82-216". Nothing reports it.
             *
             * The tell is a measured column BETWEEN the two: that boundary is
             * exactly what makes them separate cells rather than one entry split
             * by a wide gap ("Alle   Werknummern", which must stay joined). So
             * the collision is only broken up when a boundary proves it real,
             * and then the earlier text moves to the region it started in.
             */
            if (isset($cells[$column]) && self::boundaryBetween($columns, $from[$column], $start)) {
                $home = self::startsIn($columns, $from[$column]);

                if ($home !== null && $home !== $column && ! isset($cells[$home])) {
                    $cells[$home] = $cells[$column];
                    $from[$home] = $from[$column];

                    unset($cells[$column], $from[$column]);
                }
            }

            // Does this run cover a later column that would otherwise be empty?
            $next = null;

            foreach ($columns as $candidate) {
                if ($candidate > $column && $candidate > $start + 1 && $candidate < $end) {
                    $next = $candidate;

                    break;
                }
            }

            if ($next !== null) {
                /*
                 * Cut at the SPACE nearest the boundary, not at the boundary.
                 *
                 * The column position is where the next cell was typeset, and a
                 * character or two of drift lands inside a word: cutting
                 * "Handbuchrevision 31.12.04" at the raw offset produced a title
                 * ending "Handbuchre" and an urgency reading "vision 31.12.04".
                 * A cell boundary is always a space, so snapping to one turns an
                 * approximate position into an exact split.
                 */
                $at = self::spaceNearest($segment['text'], $next - $start);

                if ($at !== null) {
                    $cells[$column] = trim(mb_substr($segment['text'], 0, $at));
                    $cells[$next] = trim(mb_substr($segment['text'], $at));
                    $from[$column] ??= $start;
                    $from[$next] = $next;

                    continue;
                }
            }

            $cells[$column] = isset($cells[$column])
                ? $cells[$column].' '.$segment['text']
                : $segment['text'];
            $from[$column] ??= $start;
        }

        return array_filter($cells, static fn (string $t): bool => $t !== '');
    }

    /**
     * Whether a measured column sits between where two runs begin.
     *
     * @param  list<int>  $columns
     */
    private static function boundaryBetween(array $columns, int $left, int $right): bool
    {
        foreach ($columns as $column) {
            if ($column > $left && $column <= $right) {
                return true;
            }
        }

        return false;
    }

    /**
     * The column whose region a position falls in -- the last one at or before it.
     *
     * @param  list<int>  $columns
     */
    private static function startsIn(array $columns, int $position): ?int
    {
        $found = null;

        foreach ($columns as $column) {
            if ($column <= $position) {
                $found = $column;
            }
        }

        return $found;
    }

    /**
     * The space closest to a position, or null if none is close enough.
     *
     * Bounded on purpose: a split more than a few characters from where the
     * column sits is not a cell boundary that drifted, it is a guess.
     */
    private static function spaceNearest(string $text, int $position, int $window = 12): ?int
    {
        $length = mb_strlen($text);
        $best = null;
        $distance = PHP_INT_MAX;

        for ($i = max(0, $position - $window); $i <= min($length, $position + $window); $i++) {
            if ($i > 0 && $i < $length && mb_substr($text, $i - 1, 1) !== ' ') {
                continue;
            }

            $d = abs($i - $position);

            if ($d < $distance) {
                $distance = $d;
                $best = $i;
            }
        }

        return ($best === null || $best === 0 || $best === $length) ? null : $best;
    }

    /**
     * A line cut at the column boundaries.
     *
     * ─────────────────────────────────────────────────────────────────────────
     * The alternative -- matching whole runs of text to the nearest column --
     * works until a cell overflows into the next one with a SINGLE space between
     * them. Then the two read as one run, and the column behind it appears
     * empty: DG's 413/04 row has "W.Nr. 10-1 bis W.Nr. 10-47 Ballastkasten in
     * der Seitenflosse" written as one stretch, and the subject swallowed the
     * title.
     *
     * Cutting at the boundaries instead uses the one thing the layout does
     * guarantee: a cell begins where its column begins. It splits that row
     * exactly right, and needs no rule about how many spaces mean what.
     *
     * @param  list<int>  $columns  ascending, as returned by columnsIn()
     * @return array<int, string> column => text
     */
    public static function cells(string $line, array $columns): array
    {
        $line = str_replace(["\t", "\u{00A0}"], ' ', $line);
        $length = mb_strlen($line);
        $cells = [];

        foreach ($columns as $index => $start) {
            if ($start >= $length) {
                break;
            }

            $end = $columns[$index + 1] ?? $length;

            $text = trim(mb_substr($line, $start, max(0, $end - $start)));

            if ($text !== '') {
                $cells[$start] = $text;
            }
        }

        return $cells;
    }

    /**
     * The measured column closest to where a heading sits.
     *
     * @param  list<int>  $columns
     */
    public static function nearest(array $columns, int $to): ?int
    {
        $best = null;
        $distance = PHP_INT_MAX;

        foreach ($columns as $column) {
            $d = abs($column - $to);

            if ($d < $distance) {
                $distance = $d;
                $best = $column;
            }
        }

        return $best;
    }

    /**
     * Where each heading sits, so a document describes its own columns.
     *
     * ─────────────────────────────────────────────────────────────────────────
     * The alternative is a column index per document, and DG alone would need
     * twenty-one of them: its overview sheets carry an "LBA-LTA-No." for one
     * type, an "EASA AD No." for another, and neither for the general notes --
     * which shifts every column after it.
     *
     * Reading the header instead means the sheet says where its own columns are.
     * A spec then names a heading rather than counting spaces, and a
     * manufacturer who inserts a column breaks nothing.
     *
     * Headings are matched loosely on purpose: "TM-Nr. / TN no." and
     * "TM-Nr. / TB no." are the same column, and the sheets differ in spacing
     * and punctuation between types.
     *
     * @param  list<string>  $labels  lowercase fragments, first match wins
     * @return array<string, int> label => column
     */
    public static function headerColumns(string $line, array $labels): array
    {
        $found = [];

        foreach (self::segments($line) as $segment) {
            $text = mb_strtolower($segment['text']);

            foreach ($labels as $label) {
                if (isset($found[$label]) || ! str_contains($text, mb_strtolower($label))) {
                    continue;
                }

                $found[$label] = $segment['column'];
            }
        }

        return $found;
    }
}
