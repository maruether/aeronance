<?php

declare(strict_types=1);

namespace App\Modules\Directives\Sources\Configured;

use App\Core\Documents\LayoutTable;
use RuntimeException;

/**
 * The manufacturer's own overview sheet, as a table.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * THIS SHEET IS THE BINDING DOCUMENT, and that is why it is the source rather
 * than a convenience.
 *
 * Vorgabe: "die übersicht ist das bindende dokument, das haben alle hersteller.
 * die anderen files sind die details dazu." It is the page an inspector signs --
 * it even carries a "Durchgeführt, Datum, Unterschrift" column down the right
 * for exactly that. The set of directives for a type IS what this sheet lists.
 * A single document that does not appear here is not a directive; a line that
 * appears here is one, whether or not we can find the document behind it.
 *
 * That inverts what completeness means. Walking a document library and hoping
 * every page was seen is guesswork about a moving list; reading ONE sheet to the
 * end is a checkable claim. Which makes skipped() below not a nicety but the
 * completeness check itself: anything the number pattern refused is either page
 * furniture or a directive that would otherwise have vanished silently.
 *
 * TWO THINGS ARE READ FROM THE DOCUMENT RATHER THAN CONFIGURED, because DG
 * alone publishes four different layouts:
 *
 *  - The COLUMNS come from the header. One type's sheet has an "LBA-LTA-No.",
 *    another an "EASA AD No.", the general notes have neither -- and every
 *    column after it shifts. Twenty-one column maps would be twenty-one things
 *    to maintain; a header lookup is one.
 *
 *  - The NUMBER COLUMN comes from the CONTENT, because on the LS sheets the
 *    first two headings run together into a single "EASA AD No./ TM-Nr. / TB
 *    no." and no header lookup can separate them. Finding the column where
 *    numbers actually live is self-verifying: we are looking for numbers, and
 *    we find where they are.
 *
 * WHAT IS CONFIGURED is the manufacturer's vocabulary, never the mechanism:
 * which words head the columns, what a directive number looks like, whether the
 * sheet repeats every row in a second language, and where its table ends. DG
 * heads a column "Dringlichkeit" and Schleicher heads the same column "Termin";
 * that is a difference in language, not in reading a table.
 * ─────────────────────────────────────────────────────────────────────────────
 */
final class OverviewSheet
{
    /**
     * The columns this reader can use, in the order it resolves them.
     *
     * The ORDER is load-bearing. A heading is looked up as a fragment, and the
     * sheets repeat words: Schleicher writes "Ausgabedatum" twice in one head,
     * once under the LTA number and once under the TM number. Resolving the
     * authority column first means the second occurrence is the only one left
     * for the date -- see columns(), where a column already spoken for is
     * skipped rather than handed out twice.
     */
    private const FIELDS = ['authority', 'number', 'date', 'subject', 'title', 'compliance'];

    /**
     * How often a line must repeat verbatim to count as page furniture.
     *
     * Three, because a two-page sheet has to be caught as well -- and because a
     * real row repeating three times over is rare enough that leaving it out of
     * the column TALLY changes nothing (the rows around it sit in the same
     * places).
     */
    private const FURNITURE_REPEATS = 3;

    /** A date in the sheets' own notation -- 09.03.84 and 28.01.1981 both occur. */
    private const DATE = '/\b(\d{1,2})\.(\d{1,2})\.(\d{2}|\d{4})\b/';

    /**
     * The same date written out -- "13 Jan. 2025", "26. Feb. 2025", "12. Mai 2025".
     *
     * Worth parsing rather than ignoring: DG switched notation around 2015 and
     * the newest directives on every sheet are written this way. The overview is
     * the only place an issue date exists at all -- the document library carries
     * the bulk-import timestamp and nothing else -- so dropping these would
     * leave the most recent, most relevant rows undated.
     */
    private const DATE_WRITTEN = '/\b(\d{1,2})\.?[\s-]*([A-Za-zäÄ]{3,9})\.?,?[\s-]*(\d{4})\b/u';

    /** And the other way round -- "Sept.26,2019", "February 15. 2018". */
    private const DATE_WRITTEN_REVERSED = '/\b([A-Za-zäÄ]{3,9})\.?[\s-]*(\d{1,2})[.,]?[\s-]*,?\s*(\d{4})\b/u';

    /** German and English month names, by their first three letters. */
    private const MONTHS = [
        'jan' => 1, 'feb' => 2, 'mar' => 3, 'mär' => 3, 'maer' => 3, 'apr' => 4,
        'mai' => 5, 'may' => 5, 'jun' => 6, 'jul' => 7, 'aug' => 8, 'sep' => 9,
        'okt' => 10, 'oct' => 10, 'nov' => 11, 'dez' => 12, 'dec' => 12,
    ];

    /**
     * How the sheets write "this row has no number of its own".
     *
     * A row can still be a directive without one: LS4 carries EASA AD 2022-0230
     * with a dash where the TM number would be, because the AD is the whole
     * document. Such a row is identified by its authority number instead.
     */
    private const PLACEHOLDER = '/^[-–—\/]{1,3}$/u';

    /** @var list<int> the columns measured in the sheet currently being read */
    private array $measured = [];

    /**
     * The same, minus the lines that carry a heading.
     *
     * ─────────────────────────────────────────────────────────────────────────
     * ONLY A CENTRED HEADING NEEDS THIS, and it is why it is a second list
     * rather than a filter on the first.
     *
     * A centred heading is snapped to "the column it sits over" -- and $measured
     * contains columns established by the HEADINGS THEMSELVES. On Grob's G 109
     * sheet under poppler 25.03, "Title" printed at 57 on four pages was
     * measured as a column at 56, and the heading then snapped to itself instead
     * of to the titles at 24. Every title came back one column too far right:
     * "810 kg auf 825 kg zur Erhöhung der" where the entry reads "Änderung des
     * Fluggewichtes von 810 kg …". Under 26.04 the same sheet was fine, so it
     * showed up as a CI-only failure.
     *
     * Dropping heading lines from $measured itself was tried and is wrong:
     * Streifeneder's compliance column is established by its "Intervall"
     * heading and by nothing else -- nine of 51 rows and 68 unrecognised
     * entries. The headings are evidence there and noise here, so both
     * measurements exist and each is asked where it belongs.
     * ─────────────────────────────────────────────────────────────────────────
     *
     * @var list<int>
     */
    private array $measuredFromData = [];

    /** @var array<int, true> line numbers belonging to a repeated page head */
    private array $head = [];

    /**
     * How far below its anchor a table head may reach.
     *
     * A backstop, not the rule -- the real bound is the first line of data (see
     * headIn). Eight covers the widest head measured across the fourteen
     * overview sheets, on both Poppler versions this project has met.
     */
    private const HEAD_DEPTH = 8;

    /** @var list<string> number-column entries the pattern refused */
    private array $skipped = [];

    /** @var list<int> the lines carrying the table's own heading, one per page */
    private array $headAnchors = [];

    /**
     * @param  string  $numberPattern  what a directive number looks like on this manufacturer's sheets
     * @param  array<string, list<string>>  $headings  field => lowercase heading fragments
     * @param  bool  $bilingual  whether every row is repeated in a second language below itself
     * @param  string|null  $endsAt  a line pattern below which the sheet is no longer the table
     * @param  string|null  $ignore  entries this manufacturer writes in the number column that are not numbers
     * @param  bool  $blankSeparates  whether a blank line ends a row rather than sitting inside one
     * @param  bool  $numbersCentred  whether the sheet centres a number in its row's height
     */
    public function __construct(
        private readonly string $numberPattern,
        private readonly array $headings,
        private readonly bool $bilingual = false,
        private readonly ?string $endsAt = null,
        private readonly ?string $ignore = null,
        private readonly bool $blankSeparates = false,
        private readonly bool $numbersCentred = false,

        /**
         * Whether the headings are centred over their columns.
         *
         * ─────────────────────────────────────────────────────────────────────
         * Normally a heading sits at the start of its column, give or take the
         * drift pdftotext introduces, and the nearest measured start is the
         * right answer. Grob centres instead: "Title" is printed at 57 over a
         * column whose every entry begins at 24, and the nearest start to 57 is
         * a different column entirely -- so the titles came back as fragments of
         * their neighbours.
         *
         * With this set, a heading belongs to the column it SITS OVER: the last
         * measured column at or before it. Declared rather than detected,
         * because the two layouts are indistinguishable on a narrow column --
         * where the centre and the start are the same place -- and guessing
         * wrong is silent.
         * ─────────────────────────────────────────────────────────────────────
         */
        private readonly bool $headingsCentred = false,

        /**
         * Markup-free noise to take out of a title.
         *
         * ─────────────────────────────────────────────────────────────────────
         * A sheet that marks "does not apply" with a dash puts that dash in a
         * column of its own, and where the columns drift between pages -- as
         * Aquila's do -- it lands in the neighbouring cell. The title then reads
         * "Wegfall Vortex Generator /--- ---": the right text with furniture
         * stuck to it.
         *
         * Only ever REMOVES, and only what the spec names. Nothing is added and
         * no wording is rewritten -- a title that loses its dashes is still the
         * manufacturer's own sentence.
         * ─────────────────────────────────────────────────────────────────────
         */
        private readonly ?string $titleStrip = null,

        /**
         * Whether the columns are measured per PAGE rather than once per sheet.
         *
         * ─────────────────────────────────────────────────────────────────────
         * pdftotext lays a page out on its own: where a cell runs long, the
         * columns beside it shift, and they shift back on the next page. Over a
         * short sheet that averages out and one measurement holds. Over a long
         * one it does not.
         *
         * Piper's index is the case. 55 pages, a table head repeated on each,
         * and each page set to its own widths -- measured as one document it
         * yielded 97 of some 2000 entries with the number, subject and date of
         * different rows mixed together ("V-Band", "Stabilator").
         *
         * Off by default, because for the eight sheets already read the single
         * measurement is not merely adequate but better: a page with four rows
         * on it has too little evidence to measure from, and it borrows the
         * document's answer instead.
         * ─────────────────────────────────────────────────────────────────────
         */
        private readonly bool $columnsPerPage = false,

        /**
         * How this sheet orders a slash-separated date, where it uses one.
         *
         * ─────────────────────────────────────────────────────────────────────
         * DECLARED, NEVER DETECTED. "2/15/46" is unambiguous, but "1/7/26" is a
         * valid date read either way and most of Piper's are. The order cannot
         * be recovered from the document, only from knowing whose document it
         * is -- and C.E.A.P.R. is the cautionary case, where the same wording
         * carries both conventions and the field is therefore left empty.
         * ─────────────────────────────────────────────────────────────────────
         */
        private readonly ?string $dateOrder = null,

        /**
         * A mark put in front of every title from a sheet that truncates them.
         *
         * ─────────────────────────────────────────────────────────────────────
         * Vorgabe: "benannt unvollständig ist ok, muss aber deutlich markiert
         * sein." A caveat in the spec file is read by whoever maintains the
         * spec; the person ticking off the list never sees it. So the mark
         * travels WITH the row.
         *
         * Grob's G 115 sheet is the case: every title begins mid-sentence
         * because the column boundary falls inside it. An ellipsis in front says
         * that at a glance and in any language -- "… revision of flight manual"
         * reads as a fragment, "revision of flight manual" reads as a title.
         * ─────────────────────────────────────────────────────────────────────
         */
        private readonly ?string $titlePrefix = null,

        /**
         * Whether the rows TILE around their centred numbers.
         *
         * ─────────────────────────────────────────────────────────────────────
         * A STRONGER STATEMENT THAN numbersCentred, and an exact one.
         *
         * "Centred" alone says where a number sits inside its row. It does not
         * say where the row ENDS, and nearestAnchor has to guess -- it puts the
         * boundary between two numbers halfway between them, which is right only
         * where both rows are equally tall. SZD's Junior sheet has a three-line
         * row against a seven-line one, and the taller row's first line is two
         * lines from the wrong number and three from its own. It went to the
         * wrong row, and with it that row's serial range.
         *
         * This says the second half as well: the rows are CONTIGUOUS -- no gaps,
         * no blank rules -- so a row that is symmetric about its number is fully
         * determined by where it starts:
         *
         *      end   = 2 * centre - start
         *      start = the previous row's end + 1
         *
         * and the first row of a page starts under the table head. Nothing is
         * estimated; each boundary follows from the one before it. Checked line
         * by line against the Junior sheet, all fourteen rows, including the
         * seven-line and thirteen-line ones.
         *
         * SEPARATE FROM numbersCentred rather than replacing it, because the two
         * describe different sheets. Streifeneder centres its numbers but rules
         * its rows with blank lines and does not tile; measuring it this way
         * would be a claim its layout does not make.
         * ─────────────────────────────────────────────────────────────────────
         */
        private readonly bool $rowsTile = false,
    ) {}

    /**
     * What the number pattern threw away -- the completeness check.
     *
     * ─────────────────────────────────────────────────────────────────────────
     * The pattern has to enumerate the forms a manufacturer uses -- DG alone
     * writes 359/1, 413-01, 4001, DG-G-01, LS-S-01, "TM DG-SS-05" and
     * "Service Info 99/17" -- and anything it does not know would be skipped in
     * silence. That is precisely how nineteen Lindner directives once
     * disappeared.
     *
     * Since the overview IS the binding document, an entry landing here is not a
     * cosmetic problem: it is a line of the signed sheet that would be missing
     * from the club's list. So every entry the pattern refused is kept and
     * reported by name. A sheet that yields "0 verworfen" was read whole; one
     * that yields three names them, and somebody can decide whether they are
     * directives or page furniture.
     * ─────────────────────────────────────────────────────────────────────────
     *
     * @return list<string>
     */
    public function skipped(): array
    {
        return $this->skipped;
    }

    /**
     * Every row the sheet lists.
     *
     * @return list<array{number: string, issued_at: ?string, authority_number: ?string,
     *                    subject: ?string, title: string, summary: string, compliance: ?string}>
     *
     * @throws RuntimeException when the sheet has no readable header, or no rows at all
     */
    public function rows(string $text): array
    {
        $lines = preg_split('/\R/', $text) ?: [];

        if (! $this->columnsPerPage) {
            return $this->rowsIn($lines);
        }

        /*
         * One page at a time, each measured on its own.
         *
         * A page that yields nothing is not an error here -- a sheet's first
         * page is its cover and its last is often a signature block. The whole
         * document yielding nothing still is, and rowsIn() says so.
         */
        $rows = [];
        $read = 0;

        foreach ($this->pages($lines) as $page) {
            try {
                $rows = [...$rows, ...$this->rowsIn($page)];
                $read++;
            } catch (RuntimeException) {
                continue;
            }
        }

        if ($read === 0) {
            throw new RuntimeException(sprintf(
                'Keine einzige Seite der Übersicht war lesbar. Entweder passt das '
                .'Nummernmuster %s nicht mehr, oder die Datei ist keine Übersicht.',
                $this->numberPattern,
            ));
        }

        return $rows;
    }

    /**
     * The sheet cut into pages, at every repetition of its own table head.
     *
     * The cut runs two lines ABOVE the head, because a head is two or three
     * lines tall and its upper lines carry headings of their own -- Piper puts
     * "MODELS" over the line that says "NUMBER SUBJECT AFFECTED". Cutting on the
     * lower line alone would leave each page without the top of its own header.
     *
     * @param  list<string>  $lines
     * @return list<list<string>>
     */
    private function pages(array $lines): array
    {
        /*
         * A head line, not merely a line with the word on it.
         *
         * Piper's prose uses "number" nine times before the table starts ("the
         * index may list part or kit numbers"), and cutting there produced
         * chunks of prose with no table in them at all. Requiring the SUBJECT
         * heading beside it is what tells a header from a sentence: 227 matches
         * become 196, and those are the real ones.
         */
        $starts = [];

        foreach ($lines as $index => $line) {
            $lower = mb_strtolower($line);

            if ($this->mentions($lower, $this->headings['number'] ?? [])
                && $this->mentions($lower, $this->headings['title'] ?? [])) {
                $starts[] = max(0, $index - 2);
            }
        }

        if (count($starts) < 2) {
            return [$lines];
        }

        $pages = [];

        foreach ($starts as $i => $start) {
            $end = $starts[$i + 1] ?? count($lines);

            if ($end > $start) {
                $pages[] = array_slice($lines, $start, $end - $start);
            }
        }

        return $pages;
    }

    /**
     * Whether a line carries any of these heading fragments.
     *
     * @param  list<string>  $fragments
     */
    private function mentions(string $lowercased, array $fragments): bool
    {
        foreach ($fragments as $fragment) {
            if ($fragment !== '' && str_contains($lowercased, $fragment)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Every row in one stretch of the sheet.
     *
     *
     * @param  list<string>  $lines
     * @return list<array{number: string, issued_at: ?string, authority_number: ?string,
     *                    subject: ?string, title: string, summary: string, compliance: ?string}>
     *
     * @throws RuntimeException
     */
    private function rowsIn(array $lines): array
    {
        /*
         * Positions come from the BODY, meanings from the header.
         *
         * Reading positions off the header looked right and was wrong: pdftotext
         * shifts a column left when the cell beside it runs long, so DG-1000's
         * "Gegenstand" heading sits at 74 while its data sits at 65. Every
         * lookup then found an empty cell, and nineteen rows arrived with the
         * number as their title and no urgency at all -- which would have made
         * every one of them binding.
         */
        $this->measured = LayoutTable::columnsIn($this->withoutPageFurniture($lines));

        $this->measuredFromData = $this->headingsCentred
            ? LayoutTable::columnsIn(array_values(array_filter(
                $this->withoutPageFurniture($lines),
                fn (string $line): bool => ! $this->carriesAHeading($line),
            )))
            : $this->measured;
        $columns = $this->columns($lines, $this->measured);
        $numberColumn = $this->numberColumn($lines, $columns);

        /*
         * The cuts are the NAMED columns, not every position where text recurs.
         *
         * Slicing on all measured positions was tried and was worse than not
         * slicing at all: a continuation line starting mid-cell counts as a
         * position too, and the knife then falls inside a title -- "Anerkannte
         * Reparaturverfahren" arrived as "Anerkann".
         *
         * The header names four to six columns; those are the table's columns,
         * and the measured positions only say where they really sit.
         */
        $boundaries = array_values(array_unique([...array_values($columns), $numberColumn]));
        sort($boundaries);

        $furniture = $this->repeatedEntries($lines, $boundaries, $numberColumn);
        $head = $this->head = $this->pageHeads($lines, $boundaries, $numberColumn);

        $rows = [];
        $blocks = [];
        $current = null;
        $ended = false;
        $lastBlank = -1;
        $floor = -1;
        $rowLine = -1;
        $orphans = [];
        $this->skipped = [];

        foreach ($lines as $index => $line) {
            /*
             * The page head, which every sheet repeats and no row belongs to.
             *
             * It has to be cut out rather than merely ignored: a row's cell is
             * stitched from the lines below it, and a row at the foot of a page
             * would otherwise collect "DG Aviation GmbH" and the Kennblatt line
             * as its authority number. See pageHeads().
             */
            if (isset($head[$index])) {
                if ($current !== null) {
                    $blocks[] = $current;
                    $current = null;
                }

                $floor = $index;

                continue;
            }

            if (trim($line) === '') {
                /*
                 * A blank line means opposite things on the two kinds of sheet,
                 * so the spec says which. Schleicher rules its rows apart with
                 * blank lines; DG sets its tables solid and uses a blank INSIDE
                 * a row, between the German half and the English one. Guessing
                 * wrong either splits every row in two or joins every pair.
                 */
                if ($this->blankSeparates && $current !== null) {
                    $blocks[] = $current;
                    $current = null;
                }

                $lastBlank = $index;

                continue;
            }

            /*
             * Where the sheet stops being the table.
             *
             * The DG sheets end with a note listing the general TMs that also
             * apply, and LS4 adds a table of third-party STCs. Neither is a
             * directive of this type -- the general notes are a binding sheet of
             * their own -- but both put text in the number column, and reporting
             * twenty fragments of "Anerkannte Reparaturverfahren" as possibly
             * lost directives would bury the ones that really are.
             *
             * Below the marker nothing becomes a row. Anything down there that
             * still LOOKS like a directive number is reported, because the one
             * thing this reader may never do is drop something quietly.
             */
            if (! $ended && $this->endsAt !== null && preg_match($this->endsAt, $line) === 1) {
                $ended = true;

                if ($current !== null) {
                    $blocks[] = $current;
                    $current = null;
                }
            }

            $cells = LayoutTable::assign($line, $boundaries);

            if ($cells === []) {
                continue;
            }

            $cell = $cells[$numberColumn] ?? null;

            if ($ended) {
                if ($cell !== null && $this->isNumber($cell)) {
                    $this->skipped[] = $cell;
                }

                continue;
            }

            $translation = $cell !== null && $this->isTranslationOf($cell, $current);

            $starts = $cell !== null
                && ! $translation
                && ! $this->finishesTheOpenNumber($current)
                && ($this->isNumber($cell) || $this->startsWithoutNumber($cell, $columns));

            if ($starts) {
                if ($current !== null) {
                    $blocks[] = $current;
                }

                $start = $this->blockStart(
                    $lines,
                    $boundaries,
                    $columns,
                    $numberColumn,
                    $index,
                    max($lastBlank, $floor, $rowLine),
                );

                /*
                 * A line belongs to ONE row. Where this block reaches back above
                 * its own number, it takes those lines off the row before it --
                 * which had collected them as continuations, having no way to
                 * know a new row was about to start above itself.
                 *
                 * Only where the reach-back is a CLAIM: a bare number line with
                 * its text above it, at most four lines. Where the sheet rules
                 * its rows apart with blank lines the start is merely the last
                 * blank, which can lie a whole row further up -- Schleicher sets
                 * two rows between one pair of blanks where a directive has no
                 * TM number of its own. Taking those lines away would empty the
                 * row above instead of completing this one.
                 */
                if (! $this->blankSeparates && $blocks !== []) {
                    $previous = array_key_last($blocks);
                    $blocks[$previous]['lines'] = array_values(array_filter(
                        $blocks[$previous]['lines'],
                        static fn (int $line): bool => $line < $start,
                    ));
                }

                $current = [
                    'row' => $index,
                    'lines' => range($start, $index),
                    'skips' => [],
                    'number' => $this->numberIn($cell),
                ];
                $rowLine = $index;

                continue;
            }

            /*
             * The rest of a number the sheet broke after a slash. It reads like
             * the start of a new directive and is the tail of the open one, so
             * it is absorbed rather than reported -- and the row's number is
             * grown with it, or every number line after this one would look like
             * another continuation.
             */
            if ($cell !== null && $this->finishesTheOpenNumber($current)) {
                $current['number'] = $this->join(trim($current['number']), trim($cell));
                $current['lines'][] = $index;

                continue;
            }

            // A translation of the open row's own number is not a lost entry --
            // it is the row itself, said twice.
            if ($cell !== null && ! $translation && ! $this->isFurniture($cell, $furniture)) {
                if ($current !== null) {
                    // Keyed by line, because a centred sheet may hand that line
                    // to a different row further down -- and a note about a line
                    // has to travel with it, or it is reported against a row
                    // that never had it.
                    $current['skips'][$index] = $cell;
                } else {
                    $this->skipped[] = $cell;
                }
            }

            if ($current !== null) {
                $current['lines'][] = $index;

                continue;
            }

            /*
             * A content line with no row open. On a top-aligned sheet there is
             * nothing to be done with it -- no row has started. On a sheet that
             * centres its numbers it is usually the FIRST row's own text, which
             * stands above its number: SZD's opening directive begins three
             * lines under the page heading and its number sits below that.
             * Kept aside so the pass below can hand it to the row it belongs to.
             */
            $orphans[] = $index;
        }

        if ($current !== null) {
            $blocks[] = $current;
        }

        if ($this->rowsTile) {
            $blocks = $this->toTiledRows($blocks, $head, $lines, $boundaries, $numberColumn, $orphans);
        } elseif ($this->numbersCentred) {
            $blocks = $this->toNearestNumber($blocks, $head, $lines, $boundaries, $numberColumn, $orphans);
        }

        foreach ($blocks as $block) {
            $row = $this->finish($lines, $block, $boundaries, $columns, $numberColumn);

            if ($row !== null) {
                $rows[] = $row;
            }
        }

        if ($rows === []) {
            throw new RuntimeException(sprintf(
                'Die Übersicht enthält keine einzige lesbare Zeile. Eine leere Übersicht '
                .'gibt es nicht -- entweder passt das Nummernmuster %s nicht mehr, oder '
                .'die Datei ist keine Übersicht.',
                $this->numberPattern,
            ));
        }

        return $rows;
    }

    /**
     * Whether this line stands, word for word, at the same place on every page.
     *
     * ─────────────────────────────────────────────────────────────────────────
     * That is what a page head IS, and the older test -- "this text occurs at
     * least twice anywhere in the document" -- was a weaker thing that happened
     * to agree most of the time.
     *
     * Where it disagreed it ate data. On Streifeneder's Club Libelle sheet the
     * first directive is titled "HR-Antriebsbeschlag", and three of the sheet's
     * thirty-five rows carry that same subject -- so the title of row 205-1 was
     * counted as furniture and the row came out holding only the second half of
     * its own subject. Nothing reported it: a swallowed line is not a skipped
     * entry, the row still exists and still looks plausible.
     *
     * A single-page sheet has no evidence either way, and says so by answering
     * no. Anything below the heading block there is a row until proven otherwise
     * -- which is the safe reading, since a lost line is invisible and a
     * heading read as a row shows up as a line nobody can make sense of.
     * ─────────────────────────────────────────────────────────────────────────
     *
     * @param  list<string>  $lines
     * @param  list<int>  $anchors
     */
    private function onEveryPage(array $lines, array $anchors, int $anchor, int $index): bool
    {
        if (count($anchors) < 2) {
            return false;
        }

        $normalise = static fn (string $line): string => trim((string) preg_replace('/\s+/', ' ', $line));
        $text = $normalise($lines[$index]);
        $offset = $index - $anchor;

        foreach ($anchors as $other) {
            if ($other === $anchor) {
                continue;
            }

            if (! isset($lines[$other + $offset]) || $normalise($lines[$other + $offset]) !== $text) {
                return false;
            }
        }

        return true;
    }

    /**
     * The lines a column may be measured from -- the table, not the stationery.
     *
     * ─────────────────────────────────────────────────────────────────────────
     * A COLUMN IS WHERE DATA SITS, and page furniture is not data. Measured over
     * every line, a six-page sheet turns each of its own headings into a
     * "column", because a heading printed six times clears the five-occurrence
     * bar as easily as a real one.
     *
     * Grob is where that went wrong. Its headings are centred over wide columns,
     * so "Title" sits at 57 while every title underneath starts at 24 -- and 57
     * was itself measured as a column, purely because the word appears once per
     * page. The heading then snapped to ITSELF instead of to its data, and every
     * row came back with a fragment of the neighbouring cell. Same for the
     * "Print Date" footer at 8, which stole the number column from position 0.
     *
     * REPEATED VERBATIM is the test, and only for measuring. docs/LTA-TM.md §15
     * records why that is not enough to DROP a row -- three Club Libelle rows
     * share a subject -- but a genuine row left out of the tally shifts nothing:
     * the other hundred rows at that position still carry it. A heading counted
     * IN, on the other hand, invents a column that no data ever uses.
     *
     * A repeated line that carries a directive number is kept regardless. That
     * is the one case where "occurs several times" and "is a real row" meet.
     * ─────────────────────────────────────────────────────────────────────────
     *
     * @param  list<string>  $lines
     * @return list<string>
     */
    private function withoutPageFurniture(array $lines): array
    {
        $seen = [];

        foreach ($lines as $line) {
            $key = trim($line);

            if ($key !== '') {
                $seen[$key] = ($seen[$key] ?? 0) + 1;
            }
        }

        $kept = [];

        foreach ($lines as $line) {
            $key = trim($line);

            if ($key !== '' && ($seen[$key] ?? 0) >= self::FURNITURE_REPEATS
                && preg_match($this->numberPattern, $key) !== 1) {
                continue;
            }

            $kept[] = $line;
        }

        // Never hand back nothing: a one-page sheet whose every line repeats
        // would otherwise be measured against an empty document.
        return $kept === [] ? $lines : $kept;
    }

    /**
     * Whether this line carries one of the headings the spec declares.
     *
     * The narrow licence for ignoring a character of drift -- see
     * withoutPageFurniture(). A row of data never says "SB No." or "Title".
     */
    private function carriesAHeading(string $line): bool
    {
        /*
         * GANZE ZELLEN, KEINE TEILZEICHENKETTEN -- und das ist der Unterschied
         * zwischen einer Regel und einem Flurschaden.
         *
         * Mit str_contains geprüft, traf diese Bedingung Streifeneders
         * Datenzeilen: dessen Spec erklärt "nr" als Nummernüberschrift, und "ab
         * Werknr. 6 serienmässig" enthält das. Neun von 51 Zeilen fielen aus der
         * Messung, und 68 Einträge kamen als unerkannt zurück.
         *
         * Eine Überschrift steht ALLEIN in ihrer Zelle. Verglichen wird deshalb
         * Zelle gegen Wort, nicht Wort in Zeile.
         */
        foreach (LayoutTable::segments($line) as $segment) {
            $cell = mb_strtolower(trim((string) ($segment['text'] ?? '')));

            if ($cell === '') {
                continue;
            }

            foreach ($this->headings as $fragments) {
                foreach ($fragments as $fragment) {
                    if ($fragment !== '' && $cell === mb_strtolower($fragment)) {
                        return true;
                    }
                }
            }
        }

        return false;
    }

    /**
     * The column a centred heading sits over -- the last one at or before it.
     *
     * @param  list<int>  $measured  ascending
     */
    private function columnUnder(array $measured, int $position): ?int
    {
        $under = null;

        foreach ($measured as $column) {
            if ($column > $position) {
                break;
            }

            $under = $column;
        }

        return $under;
    }

    /** Whether a measured column lies strictly between two positions. */
    private function columnBetween(int $a, int $b): bool
    {
        $low = min($a, $b);
        $high = max($a, $b);

        foreach ($this->measured as $column) {
            if ($column > $low && $column < $high) {
                return true;
            }
        }

        return false;
    }

    /**
     * Every line to the number it is nearest -- for sheets that centre it.
     *
     * ─────────────────────────────────────────────────────────────────────────
     * WHY A SECOND PASS. Reading a sheet top to bottom cannot get this right.
     * Streifeneder sets the number in the MIDDLE of its row's height, so a row's
     * first line of text appears BEFORE its number:
     *
     *     LTA 2012-105                 Überprüfung/Austausch der HR-
     *     EASA AD 2011-   201-40       Stoßstange (Werknr. 169 und
     *     213                          Std. Libelle 203 Werknr. 1 und 2)
     *
     * Line one belongs to 201-40. Read forwards it lands on the row above,
     * because at that moment 201-40 has not been seen yet -- and the damage is
     * not cosmetic: measured on the real sheet, "LTA 2012-105" was attached to
     * 201-39, a directive it has nothing to do with. An airworthiness directive
     * against the wrong TM is the worst thing this reader can produce.
     *
     * Once every number is known the question is easy: a line between two
     * numbers belongs to the NEARER one. Checked against all eleven Streifeneder
     * sheets, that places every line correctly.
     *
     * ONLY WHERE THE SPEC SAYS SO, and that is not caution, it is correctness:
     * on a sheet that sets its number at the TOP of the row -- Schleicher, DG,
     * the Blue Book -- the same rule would hand the lower half of every long row
     * to the row beneath it. The two layouts need opposite rules, so the spec
     * declares which document it is describing.
     *
     * A line never crosses a page head. The head is what separates the last row
     * of one page from the first of the next, and without that guard a row at
     * the foot of a page could be pulled onto the page after it.
     * ─────────────────────────────────────────────────────────────────────────
     *
     * @param  list<array{row: int, lines: list<int>, skips: list<string>, number: string}>  $blocks
     * @param  array<int, true>  $head
     * @param  list<string>  $lines
     * @param  list<int>  $boundaries
     * @param  list<int>  $orphans
     * @return list<array{row: int, lines: list<int>, skips: list<string>, number: string}>
     */
    private function toNearestNumber(
        array $blocks,
        array $head,
        array $lines,
        array $boundaries,
        int $numberColumn,
        array $orphans = [],
    ): array {
        if (count($blocks) < 2) {
            return $blocks;
        }

        $anchors = array_map(static fn (array $b): int => $b['row'], $blocks);
        $owner = [];

        foreach ($blocks as $i => $block) {
            foreach ($block['lines'] as $line) {
                $owner[$line] = $i;
            }
        }

        // Marked, not owned. An orphan that no number can reach -- the manual
        // table SZD prints above its bulletins, on the far side of a page head
        // -- must be dropped rather than fall into whichever block came first.
        foreach ($orphans as $line) {
            $owner[$line] ??= -1;
        }

        ksort($owner);

        foreach ($blocks as $i => $block) {
            $blocks[$i]['lines'] = [];
        }

        foreach ($this->cellRuns(array_keys($owner), $anchors, $lines, $boundaries) as $run) {
            /*
             * The MIDDLE of the run decides, not each line on its own. A cell can
             * be two lines tall -- Glasflügel's English sheet sets one deadline
             * as "until" over "31.05.1989" -- and judging line by line put the
             * word on one directive and its date on the next. Half a deadline on
             * each of two rows is worse than either row having none.
             */
            $first = reset($run);

            /*
             * A line in the NUMBER column continues the number ABOVE it, and
             * distance must not overrule that. Perkoz lists "BE-004/" over
             * "54-2/2026" over "Revision 1", and the last of those is one line
             * from the NEXT bulletin's number and three from its own. Judged by
             * distance it joins the wrong row, where it sits above that row's
             * number line and is dropped -- leaving two bulletins with the same
             * number and no way to tell the revision from the original.
             */
            $continues = ! in_array($first, $anchors, true)
                && isset(LayoutTable::assign($lines[$first] ?? '', $boundaries)[$numberColumn]);

            $middle = (int) round(($first + end($run)) / 2);
            $target = $this->nearestAnchor($middle, $anchors, $head, $continues ? $first : null);

            if ($target === null) {
                // No number on this side of a page head. A line that was already
                // part of a row keeps that row; an orphan is let go.
                $target = $owner[reset($run)];

                if ($target < 0) {
                    continue;
                }
            }

            foreach ($run as $line) {
                $blocks[$target]['lines'][] = $line;
            }
        }

        foreach ($blocks as $i => $block) {
            // Its own number line always stays with it, whatever the arithmetic
            // says -- a block that lost its anchor would be a row with no number.
            if (! in_array($blocks[$i]['row'], $block['lines'], true)) {
                $blocks[$i]['lines'][] = $blocks[$i]['row'];
            }

            sort($blocks[$i]['lines']);
        }

        // The unrecognised cells follow their lines. SZD's "Revision 1" sits in
        // the number column of a row that moves; left behind, it is reported as
        // an entry nobody read, when in fact it is part of the number two rows
        // further down.
        $moved = [];

        foreach ($blocks as $i => $block) {
            foreach ($block['skips'] as $line => $text) {
                $home = $i;

                foreach ($blocks as $j => $candidate) {
                    if (in_array($line, $candidate['lines'], true)) {
                        $home = $j;

                        break;
                    }
                }

                $moved[$home][$line] = $text;
            }
        }

        foreach ($blocks as $i => $block) {
            $blocks[$i]['skips'] = $moved[$i] ?? [];
        }

        return $blocks;
    }

    /**
     * The rows of a sheet that tiles around its centred numbers.
     *
     * ─────────────────────────────────────────────────────────────────────────
     * See the $rowsTile constructor note for why this is arithmetic and not a
     * nearest-neighbour guess. In short: the rows touch, and each is symmetric
     * about its number, so
     *
     *     end = 2 * centre - start,   next start = end + 1
     *
     * and only the first start of each page has to be found -- it is the first
     * content line under the table head.
     *
     * THE CENTRE IS THE NUMBER CELL'S, not the first line of it. SZD breaks a
     * number over two lines ("BE-048/" above "SZD-50-3/2000"); the cell's middle
     * lies between them, and taking the upper line would shift the whole row up
     * by half its height and cost the row below its first line.
     *
     * PAGES ARE TILED SEPARATELY. A row does not continue across a page head,
     * and the head is where the count starts again. A page carrying no number at
     * all -- SZD prints its MANUALS table on the first one -- keeps its lines
     * unassigned, which is what drops them.
     *
     * @param  list<array{row: int, number: string, lines: list<int>, skips: array<int, string>}>  $blocks
     * @param  array<int, true>  $head
     * @param  list<string>  $lines
     * @param  list<int>  $boundaries
     * @param  list<int>  $orphans
     * @return list<array{row: int, number: string, lines: list<int>, skips: array<int, string>}>
     */
    private function toTiledRows(
        array $blocks,
        array $head,
        array $lines,
        array $boundaries,
        int $numberColumn,
        array $orphans = [],
    ): array {
        if ($blocks === []) {
            return $blocks;
        }

        // Every line a row may claim: what the first pass gathered, plus the
        // lines that stood above the first number and had nowhere to go.
        $content = [];

        foreach ($blocks as $block) {
            foreach ($block['lines'] as $line) {
                $content[$line] = true;
            }
        }

        foreach ($orphans as $line) {
            $content[$line] = true;
        }

        ksort($content);
        $content = array_keys($content);

        $centres = [];

        foreach ($blocks as $i => $block) {
            $centres[$i] = $this->numberCentre($block, $lines, $boundaries, $numberColumn);
            $blocks[$i]['lines'] = [];
        }

        $breaks = $this->pageBreaks($head, $lines);

        $start = null;
        $page = null;

        foreach ($blocks as $i => $block) {
            $onPage = $this->pageOf($block['row'], $breaks);

            /*
             * A new page restarts the tiling at its first content line. Also the
             * safety net for the first row of all -- $start is null until here.
             */
            if ($page !== $onPage || $start === null) {
                $page = $onPage;
                $start = $this->firstContentOn($content, $breaks, $onPage) ?? $block['row'];
            }

            $centre = $centres[$i];

            // Symmetry, in whole lines. A number cell of even height puts the
            // centre between two lines; rounding up keeps the row from eating
            // into the one below.
            $end = (int) floor(2 * $centre) - $start;

            // Never shorter than its own number, and never past the next row's
            // number -- both would mean the sheet is not tiled the way it says,
            // and a row without its own number line is not a row.
            $end = max($end, (int) ceil($centre));

            if (isset($blocks[$i + 1]) && $this->pageOf($blocks[$i + 1]['row'], $breaks) === $onPage) {
                $end = min($end, $blocks[$i + 1]['row'] - 1);
            }

            foreach ($content as $line) {
                if ($line >= $start && $line <= $end && $this->pageOf($line, $breaks) === $onPage) {
                    $blocks[$i]['lines'][] = $line;
                }
            }

            if (! in_array($block['row'], $blocks[$i]['lines'], true)) {
                $blocks[$i]['lines'][] = $block['row'];
            }

            sort($blocks[$i]['lines']);

            $start = $end + 1;
        }

        return $this->skipsFollowTheirLines($blocks);
    }

    /**
     * The middle line of a row's number cell.
     *
     * Where the number stands on one line that line is the middle. Where the
     * sheet broke it over two, the middle is between them and the answer is a
     * half -- which the caller wants, because doubling it is exact again.
     *
     * @param  array{row: int, number: string, lines: list<int>, skips: array<int, string>}  $block
     * @param  list<string>  $lines
     * @param  list<int>  $boundaries
     */
    private function numberCentre(array $block, array $lines, array $boundaries, int $numberColumn): float
    {
        $inNumberColumn = [$block['row']];

        foreach ($block['lines'] as $line) {
            if ($line > $block['row'] && isset(LayoutTable::assign($lines[$line] ?? '', $boundaries)[$numberColumn])) {
                $inNumberColumn[] = $line;
            }
        }

        /*
         * Only a cell that CONTINUES the number counts -- the lines directly
         * under it. A later line in the number column belongs to something else
         * ("Revision 1" three lines down is its own entry), and averaging it in
         * would drag the centre away from the row.
         */
        $last = $block['row'];

        foreach ($inNumberColumn as $line) {
            if ($line === $last + 1 || $line === $last + 2) {
                $last = $line;
            }
        }

        return ($block['row'] + $last) / 2;
    }

    /**
     * Where one page's table ends and the next begins.
     *
     * A page head is SEVERAL lines -- the type, the sheet's title, the column
     * headings -- and counting them one by one made every line of a head look
     * like another page. The run is one break.
     *
     * @param  array<int, true>  $head
     * @param  list<string>  $lines
     * @return list<int> the last line before each page break
     */
    private function pageBreaks(array $head, array $lines): array
    {
        /*
         * ONLY THE TABLE'S OWN HEADING STARTS A PAGE, not every line that was
         * taken out of the table.
         *
         * $head holds page furniture too, and on the Jantar sheet that included
         * a line inside the table. Treated as a page break it restarted the
         * tiling three times in the middle of the sheet, and each restart threw
         * away the boundary that had been carried down from the top.
         */
        $breaks = [];

        foreach ($this->headAnchors as $anchor) {
            $end = $anchor;

            while (isset($head[$end + 1])) {
                $end++;
            }

            $breaks[] = $end;
        }

        /*
         * AND THE WHITE SPACE, because a heading is not guaranteed.
         *
         * SZD repeats the column heading on the Junior sheet and does NOT on
         * the Jantar one -- there page two simply carries on under the page
         * banner. With nothing to mark the break, the tiling carried a boundary
         * across the gap and every row on page two came out shifted, taking
         * "Page 1 from 2" into a title on the way.
         *
         * Three blank lines are a break BECAUSE the sheet tiles: rows that touch
         * cannot have a gap inside them, so a gap this size is between pages.
         * That is a property of the layout this spec declares, not a guess about
         * this manufacturer.
         *
         * @phpstan-ignore-next-line the run is closed below the loop
         */
        $blank = 0;

        foreach ($lines as $index => $line) {
            if (trim($line) === '') {
                $blank++;

                continue;
            }

            if ($blank >= 3) {
                $breaks[] = $index - 1;
            }

            $blank = 0;
        }

        $breaks = array_values(array_unique($breaks));
        sort($breaks);

        return $breaks;
    }

    /**
     * Which page a line sits on.
     *
     * @param  list<int>  $breaks
     */
    private function pageOf(int $line, array $breaks): int
    {
        $page = 0;

        foreach ($breaks as $break) {
            if ($break < $line) {
                $page++;
            }
        }

        return $page;
    }

    /**
     * The first line on a page that a row could start at.
     *
     * @param  list<int>  $content
     * @param  list<int>  $breaks
     */
    private function firstContentOn(array $content, array $breaks, int $page): ?int
    {
        foreach ($content as $line) {
            if ($this->pageOf($line, $breaks) === $page) {
                return $line;
            }
        }

        return null;
    }

    /**
     * Unrecognised cells travel with the line they stood on.
     *
     * Shared with toNearestNumber: wherever a line changes hands, a note about
     * that line reported against its old row is a complaint about an entry that
     * row never had.
     *
     * @param  list<array{row: int, number: string, lines: list<int>, skips: array<int, string>}>  $blocks
     * @return list<array{row: int, number: string, lines: list<int>, skips: array<int, string>}>
     */
    private function skipsFollowTheirLines(array $blocks): array
    {
        $moved = [];

        foreach ($blocks as $i => $block) {
            foreach ($block['skips'] as $line => $text) {
                $home = $i;

                foreach ($blocks as $j => $candidate) {
                    if (in_array($line, $candidate['lines'], true)) {
                        $home = $j;

                        break;
                    }
                }

                $moved[$home][$line] = $text;
            }
        }

        foreach ($blocks as $i => $block) {
            $blocks[$i]['skips'] = $moved[$i] ?? [];
        }

        return $blocks;
    }

    /**
     * Consecutive lines that fill the SAME columns, kept together as one cell.
     *
     * A number line is always a run of its own -- it anchors a row and cannot be
     * carried off by the lines around it.
     *
     * @param  list<int>  $indices
     * @param  list<int>  $anchors
     * @param  list<string>  $lines
     * @param  list<int>  $boundaries
     * @return list<list<int>>
     */
    private function cellRuns(array $indices, array $anchors, array $lines, array $boundaries): array
    {
        $signature = function (int $line) use ($lines, $boundaries): string {
            $columns = array_keys(LayoutTable::assign($lines[$line] ?? '', $boundaries));
            sort($columns);

            return implode(',', $columns);
        };

        $runs = [];
        $run = [];
        $previous = null;
        $mark = null;

        foreach ($indices as $line) {
            $isAnchor = in_array($line, $anchors, true);
            $current = $signature($line);

            $continues = $run !== []
                && ! $isAnchor
                && $previous === $line - 1
                && $mark === $current
                && ! in_array($previous, $anchors, true);

            if (! $continues) {
                if ($run !== []) {
                    $runs[] = $run;
                }

                $run = [];
                $mark = $current;
            }

            $run[] = $line;
            $previous = $line;
        }

        if ($run !== []) {
            $runs[] = $run;
        }

        return $runs;
    }

    /**
     * The block whose number line is nearest, looking only at the neighbours.
     *
     * A tie goes UPWARDS. An even gap means the two rows are equally far, and
     * the line above the midpoint has already been set as part of the earlier
     * row by the forward pass -- keeping it there changes nothing that was
     * right, where moving it would need a reason nobody has.
     *
     * @param  list<int>  $anchors
     * @param  array<int, true>  $head
     */
    private function nearestAnchor(int $line, array $anchors, array $head, ?int $notBelow = null): ?int
    {
        $best = null;
        $distance = PHP_INT_MAX;

        foreach ($anchors as $i => $anchor) {
            if ($notBelow !== null && $anchor > $notBelow) {
                continue;
            }

            if ($this->headBetween($line, $anchor, $head)) {
                continue;
            }

            $gap = abs($line - $anchor);

            if ($gap < $distance || ($gap === $distance && $anchor < $line)) {
                $best = $i;
                $distance = $gap;
            }
        }

        return $best;
    }

    /** @param array<int, true> $head */
    private function headBetween(int $a, int $b, array $head): bool
    {
        foreach (range(min($a, $b), max($a, $b)) as $line) {
            if (isset($head[$line])) {
                return true;
            }
        }

        return false;
    }

    /**
     * Where a row's block begins, which is not always at its own number.
     *
     * ─────────────────────────────────────────────────────────────────────────
     * pdftotext places a cell at the height it was typeset at, and these sheets
     * centre the number vertically in the row. Schleicher's row 10 carries
     * "84-2 10.10.83 alle ASK 21" on one line and "10" on the next; LS4's last
     * page sets its whole table that way. Reading a block from the number line
     * downwards hands that row's LTA number, effectivity and subject to the row
     * above it.
     *
     * Two shapes, two tells:
     *
     *  - Where blank lines separate the rows, the blank is the boundary and
     *    everything after it belongs to this row.
     *  - Where they do not, only a BARE number line -- one carrying neither a
     *    subject nor a subject line of its own -- can have its text above it,
     *    because any row line with text on it is its own first line. The reach
     *    is three lines and stops at anything that has a number of its own,
     *    which is the row above.
     * ─────────────────────────────────────────────────────────────────────────
     *
     * @param  list<string>  $lines
     * @param  list<int>  $boundaries
     * @param  array<string, int>  $columns
     */
    private function blockStart(
        array $lines,
        array $boundaries,
        array $columns,
        int $numberColumn,
        int $index,
        int $floor,
    ): int {
        if ($this->blankSeparates) {
            return $floor >= 0 && $floor < $index ? $floor + 1 : $index;
        }

        $row = LayoutTable::assign($lines[$index], $boundaries);

        foreach (['subject', 'title'] as $field) {
            if (isset($columns[$field]) && trim((string) ($row[$columns[$field]] ?? '')) !== '') {
                return $index;
            }
        }

        $start = $index;

        for ($i = $index - 1; $i >= max(0, $index - 4, $floor + 1); $i--) {
            if (isset($this->head[$i]) || isset(LayoutTable::assign($lines[$i], $boundaries)[$numberColumn])) {
                break;
            }

            $start = $i;
        }

        return $start;
    }

    /**
     * One block of lines as a row.
     *
     * @param  list<string>  $lines
     * @param  array{row: int, lines: list<int>, skips: list<string>}  $block
     * @param  list<int>  $boundaries
     * @param  array<string, int>  $columns
     * @return array{number: string, issued_at: ?string, authority_number: ?string,
     *               subject: ?string, title: string, summary: string, compliance: ?string}|null
     */
    private function finish(
        array $lines,
        array $block,
        array $boundaries,
        array $columns,
        int $numberColumn,
    ): ?array {
        $row = LayoutTable::assign($lines[$block['row']], $boundaries);
        $all = [];
        $cell = '';

        foreach ($block['lines'] as $i) {
            foreach (LayoutTable::assign($lines[$i], $boundaries) as $column => $text) {
                $all[$column] = isset($all[$column]) ? $this->join($all[$column], $text) : $text;

                /*
                 * The number is read from the number line DOWNWARDS, never from
                 * the whole block.
                 *
                 * A number can wrap onto the following line -- "Service Info"
                 * with "76/12" underneath -- so the row line alone is not
                 * enough. But a block can also begin ABOVE its number line (see
                 * the blank-line rule), and there sits the previous row's tail:
                 * reading the whole block gave TM1000/47 the number 1000/45,
                 * which is somebody else's directive.
                 */
                if ($column === $numberColumn && $i >= $block['row']) {
                    $cell = $cell === '' ? $text : $this->join($cell, $text);
                }
            }
        }
        $number = $this->numberIn($cell);
        $this->report($block['skips'], $cell);

        $authority = isset($columns['authority'])
            ? $this->cleanAuthority((string) ($all[$columns['authority']] ?? ''))
            : null;

        if ($number === '' && $authority === null) {
            /*
             * A row with neither a number nor an authority number cannot be
             * recorded -- there is nothing to call it. It is reported rather
             * than dropped: the placeholder that started it was a dash in the
             * number column, and that is somebody's line on the signed sheet.
             */
            $this->skipped[] = trim((string) ($row[$numberColumn] ?? '?'))
                .' ('.mb_substr(trim((string) ($all[$columns['title']] ?? '')), 0, 40).')';

            return null;
        }

        /*
         * The date column, where the sheet has one.
         *
         * DG prints the issue date UNDER the number, in the same cell; Schleicher
         * gives it a column of its own next to the LTA's date. Falling back to
         * the number cell is therefore not a guess but the other half of the same
         * rule: read the date where this manufacturer writes it.
         */
        $issued = $this->dateIn((string) ($all[$columns['date'] ?? $numberColumn] ?? ''));

        $own = $all;

        if ($this->bilingual) {
            $own = [];

            foreach ($this->ownLines($lines, $block, $boundaries, $columns, $numberColumn) as $i) {
                foreach (LayoutTable::assign($lines[$i], $boundaries) as $column => $text) {
                    $own[$column] = isset($own[$column]) ? $this->join($own[$column], $text) : $text;
                }
            }
        }

        $title = isset($columns['title']) ? trim((string) ($own[$columns['title']] ?? '')) : '';

        if ($title !== '' && $this->titleStrip !== null) {
            $title = trim((string) preg_replace($this->titleStrip, '', $title));
        }

        if ($title !== '' && $this->titlePrefix !== null
            && ! str_starts_with($title, $this->titlePrefix)) {
            $title = $this->titlePrefix.$title;
        }
        $summary = isset($columns['title']) ? trim((string) ($all[$columns['title']] ?? '')) : '';

        $compliance = isset($columns['compliance'])
            ? (trim((string) ($own[$columns['compliance']] ?? '')) ?: trim((string) ($row[$columns['compliance']] ?? '')))
            : '';

        return [
            'number' => $number,
            'issued_at' => $issued,
            'authority_number' => $authority,
            'subject' => isset($columns['subject'])
                ? (trim((string) ($all[$columns['subject']] ?? '')) ?: null)
                : null,
            'title' => $title !== '' ? $title : ($number !== '' ? $number : (string) $authority),
            'summary' => $summary,
            'compliance' => $compliance !== '' ? $compliance : null,
        ];
    }

    /**
     * The line carrying this row's own text, on a bilingual sheet.
     *
     * ─────────────────────────────────────────────────────────────────────────
     * A BILINGUAL sheet repeats every row underneath itself in English. Stitching
     * the whole column together would produce a title reading "Einbau eines
     * ELT-Notsenders Installation of an ELT" -- correct in both languages and
     * useful in neither, and an urgency reading "wahlweise on request". So there
     * the title and the urgency come from ONE line, and the stitched text lives
     * on as the summary, where having both languages is a feature. On a
     * single-language sheet the opposite is true: Schleicher wraps one German
     * sentence over three lines, and only the whole block reads.
     *
     * That line is usually the number's own. The exception is the row whose
     * number sits BELOW its text, where the row line carries nothing but the
     * number -- LS4's last page is set that way throughout. The look back is
     * deliberately narrow: only from a row line that has neither a subject nor a
     * title, which is exactly the shape that cannot be anything else, and it
     * stops at the first line that has a title of its own.
     * ─────────────────────────────────────────────────────────────────────────
     *
     * @param  list<string>  $lines
     * @param  array{row: int, lines: list<int>, skips: list<string>}  $block
     * @param  list<int>  $boundaries
     * @param  array<string, int>  $columns
     * @return list<int> ascending
     */
    private function ownLines(
        array $lines,
        array $block,
        array $boundaries,
        array $columns,
        int $numberColumn,
    ): array {
        $row = LayoutTable::assign($lines[$block['row']], $boundaries);

        /*
         * THE THIRD SHAPE: text above the number AND a translation below it.
         *
         * ─────────────────────────────────────────────────────────────────────
         * The two cases were each handled alone. Grob has both at once, and an
         * entry occupies three lines:
         *
         *     A            Änderung des Fluggewichtes von 810 kg auf
         *     B   817-1    Zuladung                     X   12-May-1981
         *     C            Increase of max. weight from 810 kg
         *
         * Wanted is A and B together, with C left out. Read as a bilingual sheet
         * alone, the row line carries a title, the early return fires, and the
         * title is the fragment "Zuladung" -- a real piece of the right entry
         * and useless for finding it again. Read with centred numbers alone, C
         * is stitched onto the NEXT entry, which is worse: a title belonging to
         * another directive.
         *
         * So where a sheet declares both, the search does not stop at the row
         * line -- it climbs, and the stop condition sits in the loop below.
         * ─────────────────────────────────────────────────────────────────────
         */
        $textStartsAbove = $this->numbersCentred && $this->bilingual;

        if (! $textStartsAbove) {
            foreach (['title', 'subject'] as $field) {
                if (isset($columns[$field]) && trim((string) ($row[$columns[$field]] ?? '')) !== '') {
                    return [$block['row']];
                }
            }
        }

        if (! isset($columns['title'])) {
            return [$block['row']];
        }

        $found = [];

        for ($i = $block['row'] - 1; $i >= max(0, $block['row'] - 4); $i--) {
            $above = LayoutTable::assign($lines[$i], $boundaries);

            if (isset($above[$numberColumn]) || isset($this->head[$i])) {
                break;
            }

            /*
             * THE STOP, for a sheet that both centres its numbers and repeats
             * itself in translation: a line sitting directly UNDER a number line
             * is that entry's translation, never this entry's text.
             *
             * Without it the climb walks straight out of its own row and takes
             * the previous directive's English with it -- which is exactly the
             * failure the third shape was meant to fix. Structural rather than
             * textual: no guess about what language a line is in, only where it
             * sits relative to a number.
             */
            if ($textStartsAbove && $this->sitsUnderANumber($lines, $i, $boundaries, $numberColumn)) {
                break;
            }

            if (trim((string) ($above[$columns['title']] ?? '')) !== '') {
                $found[] = $i;

                /*
                 * A line with a title AND an urgency beside it is a row's first
                 * line, so the search stops there rather than climbing into the
                 * row above. Without that, LS4's 4050 took "Winglets at the wing
                 * tips" -- the English of 4049, wrapped over the two lines
                 * directly above it -- for its own subject.
                 */
                if (isset($columns['compliance'])
                    && trim((string) ($above[$columns['compliance']] ?? '')) !== '') {
                    break;
                }

                continue;
            }

            /*
             * A gap ends the search, but only once something has been found. A
             * title that wraps stands on consecutive lines, so the first line
             * without one is the end of it -- and stopping there is what keeps
             * the row above's translation out of this row's title. Before
             * anything is found the search keeps going, because a number can sit
             * two or three lines below its own text with only the effectivity
             * beside it.
             */
            if ($found !== []) {
                break;
            }
        }

        return [...array_reverse($found), $block['row']];
    }

    /**
     * Whether this line is the translation belonging to the entry above it.
     *
     * ─────────────────────────────────────────────────────────────────────────
     * BLANK LINES ARE NOT A BOUNDARY HERE, and missing that cost five of Grob's
     * sixty-eight titles. Their sheet sets an entry as
     *
     *     817-1   Zuladung
     *             (blank)
     *             Increase of max. weight from 810 kg to 825 kg
     *
     * so the English does NOT sit directly under the number -- there is a blank
     * between them. Checking only the line immediately above let the climb walk
     * through the translation and into the previous directive, which is how
     * "Increase of max. weight..." ended up at the head of 817-2's title.
     *
     * So the question is asked of the nearest line that carries anything at all.
     * ─────────────────────────────────────────────────────────────────────────
     *
     * @param  list<string>  $lines
     * @param  list<int>  $boundaries
     */
    private function sitsUnderANumber(array $lines, int $line, array $boundaries, int $numberColumn): bool
    {
        for ($i = $line - 1; $i >= 0 && $i >= $line - 3; $i--) {
            if (trim($lines[$i]) === '') {
                continue;
            }

            $above = LayoutTable::assign($lines[$i], $boundaries);

            return trim((string) ($above[$numberColumn] ?? '')) !== '';
        }

        return false;
    }

    /**
     * Whether the row already open is still waiting for the rest of its number.
     *
     * ─────────────────────────────────────────────────────────────────────────
     * A number ending in a slash is not finished. SZD wraps 24 of its 143 there
     * -- "BE-001/" on one line and "SZD-54-2/2018" two lines below, both in the
     * number column -- and the second half reads exactly like the start of a new
     * directive. Taken as one, the sheet grows rows that do not exist and the
     * real ones keep a number the manufacturer never issued ("BE-001/").
     *
     * The tell is in the document, not in a guess: the sheet itself broke the
     * word, and it left the slash behind to say so.
     * ─────────────────────────────────────────────────────────────────────────
     *
     * @param  array{row: int, lines: list<int>, skips: list<string>, number: string}|null  $current
     */
    private function finishesTheOpenNumber(?array $current): bool
    {
        return $current !== null && str_ends_with(trim($current['number']), '/');
    }

    /**
     * Two pieces of one cell, joined the way the sheet broke them.
     *
     * A cell that wraps after a hyphen or a slash wrapped INSIDE a word: DG
     * splits "Service Info 104-19" over two lines after the dash, and its
     * authority numbers break as "AD 2022-" / "0230_1"; SZD breaks 24 of its 143
     * numbers after the slash, "BE-001/" over "SZD-54-2/2018". Joining those with
     * a space would invent a number that is nowhere in the document.
     */
    private function join(string $left, string $right): string
    {
        return str_ends_with($left, '-') || str_ends_with($left, '/') || str_starts_with($right, '/')
            ? $left.$right
            : $left.' '.$right;
    }

    /**
     * Where each column starts, according to the sheet's own header.
     *
     * ─────────────────────────────────────────────────────────────────────────
     * A table head is two or three lines tall and the headings are spread over
     * all of them: Schleicher puts "TM-Nr" and "Gegenstand" on one line, "LTA /
     * AD-Nr." and "Betroffene" on the line above, and both "Ausgabedatum"s on
     * the line below. So the head is ANCHORED on the line carrying the number
     * and the subject headings, and the remaining fields are looked for in the
     * lines around it.
     *
     * A column already spoken for is never handed out twice. That is what lets a
     * repeated heading resolve: Schleicher's second "Ausgabedatum" is the TM's
     * date precisely because the first one is the LTA's, and the LTA column was
     * claimed first.
     * ─────────────────────────────────────────────────────────────────────────
     *
     * @param  list<string>  $lines
     * @param  list<int>  $measured
     * @return array<string, int>
     *
     * @throws RuntimeException
     */
    private function columns(array $lines, array $measured): array
    {
        /*
         * A line that carries BOTH headings itself is the better head, and it is
         * looked for first.
         *
         * EXTRA is why. Their page header repeats "Doc. N°: EA-03704" on every
         * page -- which contains the number heading -- and the real head sits
         * two lines below it. Anchoring on the first line that merely mentions a
         * number, then borrowing the subject from the window around it, put the
         * number column at 167 where the numbers themselves sit at 0. The reader
         * noticed and refused, which was right; it simply had the wrong candidate.
         *
         * Grob still needs the borrowing pass -- its head is broken over three
         * lines and NO line carries both -- so that stays, second.
         */
        foreach ([true, false] as $strict) {
            $found = $this->headIn($lines, $measured, $strict);

            if ($found !== null) {
                return $found;
            }
        }

        throw new RuntimeException(sprintf(
            'Die Übersicht hat keine lesbare Kopfzeile -- gesucht wurde eine Zeile mit %s '
            .'und %s. Entweder hat der Hersteller das Formular geändert, oder die Datei '
            .'ist keine Übersicht.',
            implode('/', $this->headings['number'] ?? []),
            implode('/', $this->headings['title'] ?? []),
        ));
    }

    /**
     * The table head, either on one line or spread over the lines around it.
     *
     * @param  list<string>  $lines
     * @param  list<int>  $measured
     * @return array<string, int>|null
     */
    private function headIn(array $lines, array $measured, bool $strict): ?array
    {
        foreach ($lines as $index => $line) {
            $anchor = $this->headingsIn($line, $measured, []);

            /*
             * Anchored on the NUMBER; the subject may sit on another line of the
             * same head.
             *
             * Both are still required -- no subject makes every row nameless, no
             * number column makes every row a guess -- but demanding them on ONE
             * line was stricter than that guarantee needs, and it locked out a
             * real sheet: Grob breaks its own head across three lines ("SB No. /"
             * · "Issue" · "Title"), so no single line carried both and the reader
             * refused a sheet it can otherwise read completely.
             */
            if (! isset($anchor['number'])) {
                continue;
            }

            // The strict pass wants both on this line; the second may borrow.
            if ($strict && ! isset($anchor['title'])) {
                continue;
            }

            $found = $anchor;

            /*
             * ─────────────────────────────────────────────────────────────────
             * WIE WEIT GEBORGT WIRD -- und warum das keine feste Zahl mehr ist.
             *
             * Hier standen zwei Zeilen nach oben und zwei nach unten. Das reichte
             * für Grobs dreizeiligen Kopf, gemessen auf dem Entwicklungsrechner.
             *
             * IN DER CI REICHTE ES NICHT, und das war kein Zufall: pdftotext
             * setzt denselben Kopf je nach Poppler-Version über verschieden
             * viele Zeilen. Gemessen an ein und demselben G-109-Blatt --
             *
             *   poppler 26.04   "SB No. /" Zeile 13, "Title" Zeile 15   (2 weit)
             *   poppler 25.03   "SB No. /" Zeile 19, "Title" Zeile 25   (6 weit)
             *
             * -- weil die ältere Fassung mehr Leerraum erhält. Sechs Zeilen
             * liegen ausserhalb von zwei, also fand der Leser keine Kopfzeile
             * und wies das Blatt ab. Auf dem Entwicklungsrechner war davon
             * nichts zu sehen; die Pipeline war rot und die lokale Suite grün.
             *
             * Nach unten wird deshalb gesucht, BIS DIE TABELLE ANFÄNGT: die
             * erste Zeile, die eine Anweisungsnummer trägt, ist Daten und keine
             * Überschrift mehr. Das ist die Grenze, die das Dokument selbst
             * setzt -- unabhängig davon, wie viel Leerraum eine Poppler-Version
             * stehen lässt.
             *
             * Nach oben bleibt es bei zwei: über dem Nummernkopf steht bei
             * keinem der vierzehn Blätter mehr, und eine Datenzeile darüber
             * gäbe es nur, wenn die Tabelle vor ihrem eigenen Kopf begänne.
             * ─────────────────────────────────────────────────────────────────
             */
            for ($offset = -2; $offset < 0; $offset++) {
                if (! isset($lines[$index + $offset])) {
                    continue;
                }

                foreach ($this->headingsIn($lines[$index + $offset], $measured, $found) as $field => $column) {
                    $found[$field] ??= $column;
                }
            }

            for ($below = $index + 1; $below <= $index + self::HEAD_DEPTH; $below++) {
                if (! isset($lines[$below]) || $this->startsTheTable($lines[$below])) {
                    break;
                }

                foreach ($this->headingsIn($lines[$below], $measured, $found) as $field => $column) {
                    $found[$field] ??= $column;
                }
            }

            // The subject has to turn up somewhere in the head. A lone number
            // column is a candidate, not a table head -- keep looking.
            if (! isset($found['title'])) {
                continue;
            }

            return $found;
        }

        return null;
    }

    /**
     * Whether this line already belongs to the table rather than to its head.
     *
     * A line carrying a directive number is data. Used as the lower bound of the
     * heading block, so the reader stops borrowing exactly where the
     * manufacturer stops writing headings -- see headIn().
     */
    private function startsTheTable(string $line): bool
    {
        foreach (LayoutTable::segments($line) as $segment) {
            if ($this->isNumber((string) ($segment['text'] ?? ''))) {
                return true;
            }
        }

        return false;
    }

    /**
     * The headings on one line, as columns, skipping columns already taken.
     *
     * @param  list<int>  $measured
     * @param  array<string, int>  $taken
     * @return array<string, int>
     */
    private function headingsIn(string $line, array $measured, array $taken): array
    {
        $segments = LayoutTable::segments($line);
        $found = [];

        foreach (self::FIELDS as $field) {
            if (isset($taken[$field])) {
                continue;
            }

            $fallback = null;

            foreach ($this->headings[$field] ?? [] as $label) {
                foreach ($segments as $segment) {
                    if (! str_contains(mb_strtolower($segment['text']), mb_strtolower($label))) {
                        continue;
                    }

                    /*
                     * Snapped to a column that actually carries data -- either
                     * the nearest start, or, where the manufacturer centres its
                     * headings, the column this one sits over.
                     */
                    $column = $this->headingsCentred
                        ? $this->columnUnder(
                            $this->measuredFromData !== [] ? $this->measuredFromData : $measured,
                            $segment['column'],
                        )
                        : LayoutTable::nearest($measured, $segment['column']);

                    if ($column === null) {
                        continue;
                    }

                    /*
                     * A free column is preferred, a shared one accepted.
                     *
                     * Preferring free is what resolves a repeated heading:
                     * Schleicher writes "Ausgabedatum" under the LTA number and
                     * again under the TM number, and the second one is the TM's
                     * date precisely because the first is already the LTA's.
                     *
                     * Accepting shared is what keeps DG readable: on half its
                     * pages "EASA AD No./ TM-Nr. / TN no." is one run of text,
                     * so both headings can only ever point at the same column.
                     * Refusing that would leave the sheet without a head at all.
                     */
                    if (in_array($column, $taken, true) || in_array($column, $found, true)) {
                        $fallback ??= $column;

                        continue;
                    }

                    $found[$field] = $column;

                    continue 3;
                }
            }

            if ($fallback !== null) {
                $found[$field] = $fallback;
            }
        }

        return $found;
    }

    /**
     * The column the numbers are actually in.
     *
     * ─────────────────────────────────────────────────────────────────────────
     * Counted rather than read, and then CHECKED against the header. On most
     * sheets both answers agree and the check costs nothing; on the LS sheets
     * the header cannot answer at all, because "EASA AD No./ TM-Nr. / TB no."
     * is one run of text and the two columns underneath are 14 characters apart.
     *
     * Disagreement is not silently resolved in favour of the count: it means the
     * sheet is not shaped the way this reader believes, and a wrong column would
     * import fourteen dates as directive numbers.
     * ─────────────────────────────────────────────────────────────────────────
     *
     * @param  list<string>  $lines
     * @param  array<string, int>  $columns
     *
     * @throws RuntimeException
     */
    private function numberColumn(array $lines, array $columns): int
    {
        /** @var array<int, int> $tally */
        $tally = [];

        foreach ($lines as $line) {
            foreach (LayoutTable::segments($line) as $segment) {
                /*
                 * Only left of the subject column: a serial range or an urgency
                 * wording can look like a number, and neither is one.
                 *
                 * ─────────────────────────────────────────────────────────────
                 * BUT ONLY WHERE THE SUBJECT IS ACTUALLY TO THE RIGHT. The rule
                 * was written for sheets built like Schleicher's and DG's, where
                 * the effectivity follows the number -- and it quietly assumed
                 * every sheet is.
                 *
                 * Korff's Taifun list is the other way round: "Baureihe" and
                 * "Betroffene W/Nrn." come FIRST, at columns 0 and 11, and the
                 * TM number sits at 39. Every number was therefore to the right
                 * of the subject and skipped, and the reader reported that not
                 * one line matched the pattern -- for a sheet where all 28 do.
                 *
                 * So the guard applies in the direction it was meant for, and
                 * the layout decides which that is.
                 * ─────────────────────────────────────────────────────────────
                 */
                $subjectFollowsNumber = isset($columns['subject'])
                    && $columns['subject'] > ($columns['number'] ?? 0);

                if ($subjectFollowsNumber && $segment['column'] >= $columns['subject']) {
                    continue;
                }

                if ($this->isNumber($segment['text'])) {
                    $column = LayoutTable::nearest($this->measured, $segment['column']) ?? $segment['column'];
                    $tally[$column] = ($tally[$column] ?? 0) + 1;
                }
            }
        }

        if ($tally === []) {
            throw new RuntimeException(sprintf(
                'In der Übersicht steht keine einzige Zeile, deren Nummer zu %s passt. '
                .'Eine leere Übersicht gibt es nicht -- entweder stimmt das Muster nicht '
                .'mehr, oder die Datei ist eine andere.',
                $this->numberPattern,
            ));
        }

        arsort($tally);
        $counted = (int) array_key_first($tally);

        $declared = $columns['number'] ?? null;

        // The header's answer is only usable when it differs from the authority
        // column -- where the two headings ran together it points at both.
        $usable = $declared !== null
            && (! isset($columns['authority']) || $declared !== $columns['authority']);

        /*
         * The header and the body have to point at the SAME column -- but "same"
         * cannot be a character count.
         *
         * A heading sits wherever it was typeset inside its cell, and on four of
         * Streifeneder's eleven sheets "TM-Nr.:" is indented three to five
         * characters past the numbers beneath it. Measured against a tolerance of
         * two, those sheets were refused although the reader had them right.
         * Raising the number to eight would have bought them at the price of the
         * guard: eight characters is far enough to reach a neighbouring column on
         * a tighter sheet.
         *
         * So ask the structural question instead. Two positions mean different
         * columns only if a MEASURED column lies between them; if nothing does,
         * there is no other column the header could have meant. On all four
         * sheets nothing does -- and the case this guard exists for, a reader
         * taking the authority column for the number column, still has the
         * authority column sitting squarely in between.
         */
        if ($usable && abs($declared - $counted) > 2 && $this->columnBetween($declared, $counted)) {
            throw new RuntimeException(sprintf(
                'Die Kopfzeile stellt die Nummernspalte auf %d, gezählt wurde sie aber '
                .'auf %d, und dazwischen liegt eine weitere Spalte. Die Übersicht ist '
                .'nicht so gebaut, wie dieser Leser annimmt -- ein Import daraus stünde '
                .'voller falscher Nummern.',
                $declared,
                $counted,
            ));
        }

        return $counted;
    }

    /**
     * The lines every page repeats, and no row belongs to.
     *
     * ─────────────────────────────────────────────────────────────────────────
     * Measured from the FIRST page rather than described in the spec, because
     * the head has no marker of its own -- it is simply the manufacturer's name,
     * the sheet's title, the Kennblatt line and the column headings, and each
     * manufacturer words all four differently.
     *
     * But it is always the same HEIGHT: the first page's head runs from the top
     * of the file to the first row, and the column headings sit at a fixed
     * position within it. So the first page measures the block, and every later
     * repetition of the headings is cut out to the same size. A template does
     * not change its head between pages -- and if one ever did, the rows would
     * still be found, only their cells would carry a little more of the page.
     * ─────────────────────────────────────────────────────────────────────────
     *
     * The manufacturer's name and the sheet's title are cut out separately, by
     * repetition. They have to be: DG's last page carries the same four lines
     * but heads a DIFFERENT table, so there is no column heading to anchor on --
     * and the row above them would otherwise take "DG Aviation GmbH" for its
     * authority number.
     *
     * @param  list<string>  $lines
     * @param  list<int>  $boundaries
     * @return array<int, true>
     */
    private function pageHeads(array $lines, array $boundaries, int $numberColumn): array
    {
        $anchors = [];

        foreach ($lines as $index => $line) {
            $headings = $this->headingsIn($line, $this->measured, []);

            if (isset($headings['number'], $headings['title'])) {
                $anchors[] = $index;
            }
        }

        $this->headAnchors = $anchors;

        if ($anchors === []) {
            return [];
        }

        $tally = [];

        foreach ($lines as $line) {
            foreach (LayoutTable::assign($line, $boundaries) as $column => $text) {
                $key = $column.':'.trim($text);
                $tally[$key] = ($tally[$key] ?? 0) + 1;
            }
        }

        /** @var callable(int, string, int): bool $recurs */
        $recurs = static fn (int $column, string $text, int $times): bool => ($tally[$column.':'.trim($text)] ?? 0) >= $times;

        // Above the headings: as many lines as stood above them on the first
        // page. Bounded, so a sheet with an unusual first page cannot swallow
        // half the table.
        $above = min($anchors[0], 8);
        $head = [];

        foreach ($anchors as $anchor) {
            for ($i = max(0, $anchor - $above); $i <= $anchor; $i++) {
                $head[$i] = true;
            }

            /*
             * Below the headings the head is measured rather than counted, since
             * a head can be two lines tall on one page and four on the next.
             * A heading block runs three lines at most -- the German line, the
             * English one, the units -- and beyond that only a line that says
             * the same thing it says on every other page continues it.
             *
             * A number ends it outright, whatever the count says. Schleicher's
             * later pages set the first row only two lines under the heading,
             * and counting a fixed height from the first page took that row's
             * issue date for a heading.
             */
            for ($i = $anchor + 1; $i <= $anchor + 6 && isset($lines[$i]); $i++) {
                $cells = LayoutTable::assign($lines[$i], $boundaries);

                if (isset($cells[$numberColumn]) && $this->isNumber($cells[$numberColumn])) {
                    break;
                }

                /*
                 * The three lines of grace below a heading, and why a tiling
                 * sheet gets none.
                 *
                 * A heading can be several lines tall, and on a single-page
                 * sheet there is no repetition to prove where it stops -- hence
                 * the count. But where the rows TILE around centred numbers, the
                 * first row begins directly under the heading and its number
                 * comes a line or two LATER: SZD's Junior sheet puts "S/N:
                 * B-1498, B-1499, B-1500," two lines under the heading and
                 * BE-001/85 on the line after that.
                 *
                 * Counted as head, that line was dropped as page furniture --
                 * silently, and it was the row's serial range. A serial range
                 * that goes missing makes a directive look inapplicable.
                 *
                 * So on those sheets anything that carries text and does not
                 * repeat ends the head at once. A blank line still continues it.
                 */
                $grace = $this->rowsTile ? 0 : 3;

                if ($cells !== [] && ! $this->onEveryPage($lines, $anchors, $anchor, $i) && $i - $anchor > $grace) {
                    break;
                }

                $head[$i] = true;
            }
        }

        /*
         * The rest of the page furniture, by repetition.
         *
         * A line belongs to the page and not to a row when EVERY cell on it
         * recurs -- the manufacturer's name, the sheet's title, the Kennblatt
         * line. A row always says something new somewhere, even when it opens
         * with a phrase the sheet uses often: DG's "Effective date:" heads three
         * different rows, and each carries its own deadline beside it.
         *
         * The line must also begin in the FIRST column, with a phrase rather
         * than a mark. That keeps the rule off the middle of a row, where
         * "alle W.Nr." and "optional" recur by the dozen and mean exactly what
         * they say.
         */
        $leftmost = $boundaries[0] ?? $numberColumn;

        foreach ($lines as $index => $line) {
            $cells = LayoutTable::assign($line, $boundaries);
            $first = trim((string) ($cells[$leftmost] ?? ''));

            if (mb_strlen($first) < 8 || ! $recurs($leftmost, $first, 3)) {
                continue;
            }

            /*
             * WHAT THE SPEC HAS ALREADY NAMED IS NOT FURNITURE.
             *
             * SZD prints the type under every number on the Jantar sheet --
             * "Jantar Standard-3", sixteen times, first column, every cell of
             * the line recurring. By this rule alone that is page furniture,
             * and it was removed: the number cell lost its second line, so the
             * row's middle moved up and the row came out a third too short.
             *
             * But the spec lists it under `ignore`, which says precisely that
             * it is an entry IN A ROW that happens not to be a number. A line
             * the spec has accounted for belongs to the table.
             */
            if ($this->ignore !== null && preg_match($this->ignore, $first) === 1) {
                continue;
            }

            $everywhere = true;

            foreach ($cells as $column => $text) {
                $everywhere = $everywhere && $recurs($column, $text, 3);
            }

            if ($everywhere) {
                $head[$index] = true;
            }
        }

        return $head;
    }

    /**
     * Entries the pattern refused inside a row, minus the ones it swallowed.
     *
     * A number split over two lines leaves its second half in this list --
     * "Service Info" on one line, "76/12" on the next -- and reporting that as a
     * possibly-lost directive would be reporting the row we just read. Anything
     * standing INSIDE the text the pattern consumed is therefore dropped, and
     * anything after it is not: that is the difference between a number that
     * wrapped and a second entry nobody read.
     *
     * @param  list<string>  $skips
     */
    private function report(array $skips, string $cell): void
    {
        $consumed = 0;

        if (preg_match($this->numberPattern, trim($cell), $m, PREG_OFFSET_CAPTURE) === 1) {
            $consumed = (int) $m[0][1] + mb_strlen((string) $m[0][0]);
        }

        foreach ($skips as $skip) {
            $at = mb_strpos($cell, $skip);

            if ($at !== false && $at < $consumed) {
                continue;
            }

            $this->skipped[] = $skip;
        }
    }

    /**
     * Number-column entries that repeat across the sheet, and are not numbers.
     *
     * ─────────────────────────────────────────────────────────────────────────
     * Every page repeats the head, the Kennblatt line and the title block, and
     * each of them drops a fragment into the number column. Listing those words
     * in the code would be a list of German and English page furniture that the
     * next manufacturer spells differently.
     *
     * Repetition is the manufacturer-neutral tell: a heading appears on all
     * twelve pages, a directive number appears once. Three is the threshold
     * because a sheet can legitimately list the same note twice -- DG-1000 lists
     * TM1000/26 under two ADs -- and because two occurrences of an unrecognised
     * form is exactly the case that must still be reported.
     * ─────────────────────────────────────────────────────────────────────────
     *
     * @param  list<string>  $lines
     * @param  list<int>  $boundaries
     * @return array<string, true>
     */
    private function repeatedEntries(array $lines, array $boundaries, int $numberColumn): array
    {
        $tally = [];

        foreach ($lines as $line) {
            $cell = LayoutTable::assign($line, $boundaries)[$numberColumn] ?? null;

            if ($cell === null || $this->isNumber($cell)) {
                continue;
            }

            $tally[$cell] = ($tally[$cell] ?? 0) + 1;
        }

        return array_map(
            static fn (): bool => true,
            array_filter($tally, static fn (int $count): bool => $count >= 3),
        );
    }

    /**
     * Whether an unmatched entry is part of the form rather than a lost row.
     *
     * Dates belong to the row above and the sheet's own title block spills into
     * this column. Neither is a directive somebody needs to hear about -- and a
     * report full of them is a report nobody reads, which would defeat its
     * purpose.
     *
     * @param  array<string, true>  $repeated
     */
    private function isFurniture(string $value, array $repeated): bool
    {
        $value = trim($value);

        if ($value === '' || $this->looksLikeDate($value) || isset($repeated[$value])) {
            return true;
        }

        /*
         * What this manufacturer is known to put in the number column besides
         * numbers. DG stacks the supplier's own reference under its note
         * ("Solo", "SB 4600-4") and its issue marker under the number
         * ("Revision 2").
         *
         * In the SPEC rather than here, and narrow on purpose: every word listed
         * is a directive this report can no longer warn about, so the list is
         * the manufacturer's own vocabulary and somebody's decision, not a
         * built-in convenience.
         */
        if ($this->ignore !== null && preg_match($this->ignore, $value) === 1) {
            return true;
        }

        // A directive number is short. "Übersicht Technische Mitteilungen und
        // Lufttüchtigkeitsanweisungen für Muster ..." is not one, and reporting
        // it as a possibly-lost directive would bury the ones that really are.
        return mb_strlen($value) > 24;
    }

    /**
     * Whether this number is the open row's own number in the other language.
     *
     * ─────────────────────────────────────────────────────────────────────────
     * A bilingual sheet repeats the number too: DG writes "TM DG-SS-09" on the
     * German line and "TN DG-SS-09" on the English one below it, and TM1000/30
     * to TM1000/52 all carry a TN twin. Read as row starts, every one of those
     * would arrive twice -- once in German and once in English -- which is
     * exactly the duplication the overview was supposed to end.
     *
     * The test is the number, not the marker: the two lines are the same
     * directive precisely when they name the same one. A revision is therefore
     * NOT swallowed -- "TM 359/17 Rev.1" is not "359/17" -- and neither is a
     * note the sheet genuinely lists twice, because those stand pages apart with
     * other rows between them.
     * ─────────────────────────────────────────────────────────────────────────
     *
     * @param  array{row: int, lines: list<int>, skips: list<string>, number: string}|null  $current
     */
    private function isTranslationOf(string $cell, ?array $current): bool
    {
        if (! $this->bilingual || $current === null || $current['number'] === '') {
            return false;
        }

        return $this->numberIn($cell) === $current['number'];
    }

    /**
     * Whether a row begins here although the number column holds no number.
     *
     * ─────────────────────────────────────────────────────────────────────────
     * A row without a manufacturer's number is still a directive, and often the
     * most binding kind: LS4 carries EASA AD 2022-0230 with a dash where the TM
     * number would be, and Schleicher lists LTA 1993-001/3 and LTA 82-216 with
     * "--" and "ohne". The document IS the AD; there was never a TM.
     *
     * Only a placeholder starts such a row -- never an empty cell, which is what
     * every wrapped line has. Whether it really was one is decided at the end,
     * where a block that turns out to have no authority number either is
     * dropped and reported by name.
     *
     * @param  array<string, int>  $columns
     */
    private function startsWithoutNumber(string $cell, array $columns): bool
    {
        return isset($columns['authority'])
            && preg_match(self::PLACEHOLDER, trim($cell)) === 1;
    }

    private function isNumber(string $value): bool
    {
        $value = trim($value);

        if ($value === '' || $this->looksLikeDate($value)) {
            return false;
        }

        return preg_match($this->numberPattern, $value) === 1;
    }

    /**
     * Whether a cell is nothing but a date.
     *
     * Checked before the number pattern and before the report, in both
     * directions: a date must never become a directive number, and a date must
     * never be reported as a directive somebody lost. The sheets write them
     * every way there is -- 09.03.84, "1 Mar 2016", "Sept.26,2019", and
     * "Nov. 1994" with no day at all.
     */
    private function looksLikeDate(string $value): bool
    {
        $value = trim($value);

        if ($this->dateIn($value) !== null) {
            return true;
        }

        // A bare year. Half a date, left behind when a cell wrapped between the
        // month and the year -- "7. December" on one line, "2016" on the next.
        if (preg_match('/^(19|20)\d{2}$/', $value) === 1) {
            return true;
        }

        /*
         * A month with a day or a year but not both, which is what the other
         * half of that wrap looks like. Recognised so the two halves are treated
         * as what they are: the sheets date a few of the oldest notes "Nov. 1994"
         * with no day at all, which is not precise enough to record as an issue
         * date but is certainly not a directive number either.
         */
        return preg_match('/^\d{0,2}\.?\s*\p{L}{3,9}\.?\s*\d{0,4}[.,]?$/u', $value) === 1
            && $this->month(ltrim($value, '0123456789. ')) !== null;
    }

    /**
     * The number itself, out of the cell it shares with dates and kind markers.
     *
     * ─────────────────────────────────────────────────────────────────────────
     * Read from the WHOLE cell rather than from the row line, because a number
     * can be split across two lines: DG writes "Service Info" on one and "76/12"
     * underneath, and "Service Info 104-" / "19" splits a number mid-word.
     *
     * The pattern's first capture is the number; what it leaves out is the KIND
     * MARKER, and that is deliberate. One DG sheet carries "DG-SS-01" and
     * "TM DG-SS-05" for the same series, "1000/21" and "TM1000/22" for the same
     * one, and the same Service Info appears as "Service Info 99/17" on one
     * sheet and "Info 99/17" on another where the layout ran two cells together.
     * Marker and identity are different things: keeping the marker out of the
     * number is what lets one directive be recognised as one directive.
     * ─────────────────────────────────────────────────────────────────────────
     */
    private function numberIn(string $cell): string
    {
        // UNMATCHED_AS_NULL, so an alternative that carries no number at all is
        // distinguishable from a pattern that has no capture group. "Service
        // Info" on its own is a marker whose number is on the next line, and it
        // must come back empty rather than as its own name -- two rows both
        // called "Service Info" would read as one row said twice.
        if (preg_match($this->numberPattern, trim($cell), $m, PREG_UNMATCHED_AS_NULL) !== 1) {
            return '';
        }

        foreach (array_slice($m, 1) as $group) {
            if (trim((string) $group) !== '') {
                return trim((string) $group);
            }
        }

        return count($m) === 1 ? trim((string) $m[0]) : '';
    }

    private function dateIn(string $value): ?string
    {
        $slashed = $this->slashedDate($value);

        if ($slashed !== null) {
            return $slashed;
        }

        if (preg_match(self::DATE, $value, $m) === 1) {
            return $this->asDate((int) $m[3], (int) $m[2], (int) $m[1]);
        }

        if (preg_match(self::DATE_WRITTEN, $value, $m) === 1) {
            $month = $this->month($m[2]);

            if ($month !== null) {
                return $this->asDate((int) $m[3], $month, (int) $m[1]);
            }
        }

        if (preg_match(self::DATE_WRITTEN_REVERSED, $value, $m) === 1) {
            $month = $this->month($m[1]);

            if ($month !== null) {
                return $this->asDate((int) $m[3], $month, (int) $m[2]);
            }
        }

        return null;
    }

    /**
     * A date written with slashes, in the order the spec declares.
     *
     * The two-digit year resolves at 26/27, which Piper's own sheet supports:
     * its dates run 1946 to 1999 and then 2010 onwards, with nothing between, so
     * the pivot cannot land inside real data.
     */
    private function slashedDate(string $value): ?string
    {
        if ($this->dateOrder === null
            || preg_match('#\b(\d{1,2})/(\d{1,2})/(\d{2,4})\b#', $value, $m) !== 1) {
            return null;
        }

        [$month, $day] = $this->dateOrder === 'mdy'
            ? [(int) $m[1], (int) $m[2]]
            : [(int) $m[2], (int) $m[1]];

        $year = (int) $m[3];

        if (strlen($m[3]) === 2) {
            $year += $year <= 26 ? 2000 : 1900;
        }

        return checkdate($month, $day, $year)
            ? sprintf('%04d-%02d-%02d', $year, $month, $day)
            : null;
    }

    private function month(string $name): ?int
    {
        $key = mb_strtolower(mb_substr(trim($name), 0, 3));

        return self::MONTHS[$key] ?? null;
    }

    private function asDate(int $year, int $month, int $day): ?string
    {
        if ($month < 1 || $month > 12 || $day < 1 || $day > 31) {
            return null;
        }

        if ($year < 100) {
            /*
             * Two-digit years, and the sheets go back to 1981. A pivot at 50 is
             * a convention rather than a fact, but the alternative -- reading
             * "09.03.84" as 2084 -- is a directive dated sixty years in the
             * future, which no sorting or deadline calculation survives.
             */
            $year += $year < 50 ? 2000 : 1900;
        }

        return sprintf('%04d-%02d-%02d', $year, $month, $day);
    }

    /**
     * "/" is how these sheets write "no authority number".
     *
     * A date is not one either. Where a sheet sets the authority number and its
     * issue date in one column, a row whose number is missing leaves the date
     * behind -- and an authority number is what makes a row binding, so reading
     * "17. Nov. 2017" as one would make a row mandatory on the strength of a
     * date.
     */
    private function cleanAuthority(string $value): ?string
    {
        /*
         * The authority column stacks the number and its own issue date, and the
         * stitched cell holds both -- "AD 2017-0225 17. Nov. 2017". The dates are
         * cut out rather than kept, for a reason beyond tidiness: an authority
         * number is what makes a row binding, and a row whose AD number happened
         * to be missing would otherwise be marked mandatory on the strength of a
         * date left standing in the cell.
         *
         * What remains is verbatim. A row referencing an AD and its revision
         * keeps both numbers, because the sheet lists both.
         */
        $value = trim((string) preg_replace(
            [self::DATE, self::DATE_WRITTEN, self::DATE_WRITTEN_REVERSED],
            ' ',
            trim($value),
        ));

        // A label rather than a number: DG writes "Effective date:" above the
        // date the AD takes effect, in the same column as the AD's number.
        $value = trim((string) preg_replace(['/\b\p{L}[\p{L}\s]*:/u', '/\s+/'], ['', ' '], $value));

        /*
         * An authority reference always contains a digit -- it is a number. What
         * is left without one is the page catching up with the row: LS4 ends its
         * table with a footnote ("Achtung: Die verbindliche TM ... beachten")
         * that lands in this column, and a non-empty authority number is exactly
         * what makes a row mandatory. A row would have been marked binding
         * because a footnote stood underneath it.
         */
        return preg_match('/\d/', $value) === 1 ? $value : null;
    }
}
