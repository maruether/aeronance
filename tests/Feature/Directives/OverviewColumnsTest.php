<?php

declare(strict_types=1);

namespace Tests\Feature\Directives;

use App\Core\Documents\PdfLayoutText;
use App\Modules\Directives\Sources\Configured\ConfiguredSource;
use App\Modules\Directives\Sources\Configured\OverviewSheet;
use App\Modules\Directives\Sources\Configured\SourceSpec;
use App\Modules\Directives\Sources\DirectiveRow;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Yaml\Yaml;
use Tests\TestCase;

/**
 * Where a column is, and where a heading only appears to be.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * Both defects here were found on Grob's G 109 sheet and both are general: they
 * would hit any manufacturer who prints the same way. Neither announces itself
 * -- the sheet is read, rows come back, and the text in them belongs to the
 * wrong cell.
 * ─────────────────────────────────────────────────────────────────────────────
 */
final class OverviewColumnsTest extends TestCase
{
    #[Test]
    public function a_heading_repeated_on_every_page_is_not_a_column(): void
    {
        /*
         * Columns are measured by counting where text recurs, with five
         * occurrences as the bar. A six-page sheet clears that bar with its OWN
         * HEADINGS: "Title" printed once per page counts six times and becomes a
         * column that no data ever uses -- and the heading then snaps to itself
         * instead of to the entries underneath.
         *
         * Measured on this sheet: the word "Title" sits at 57, every title under
         * it begins at 24, and 57 was measured as a column purely because the
         * word repeats. The "Print Date" footer did the same at 8, next to the
         * numbers at 0.
         */
        $sheet = $this->sheet();

        $columns = $this->measuredColumns($sheet);

        $this->assertContains(0, $columns, 'Die Nummernspalte muss gemessen werden.');
        $this->assertContains(24, $columns, 'Die Titelspalte muss gemessen werden.');
        $this->assertNotContains(56, $columns, 'Die Überschrift "Title" ist keine Spalte.');
        $this->assertNotContains(98, $columns, 'Die Überschrift "Alert" ist keine Spalte.');
    }

    #[Test]
    public function a_centred_heading_belongs_to_the_column_it_sits_over(): void
    {
        /*
         * With the headings centred, the nearest measured START is the wrong
         * answer: "Title" at 57 is nearer to 84 than to the 24 where its data
         * begins. The column it SITS OVER is the last one at or before it.
         *
         * Declared in the spec rather than detected, because on a narrow column
         * the centre and the start are the same place -- the two layouts are
         * indistinguishable there, and guessing wrong is silent.
         */
        $rows = $this->sheet()->rows($this->text());

        $this->assertNotSame([], $rows);

        $titles = array_map(static fn (array $r): string => (string) ($r['title'] ?? ''), $rows);
        $joined = implode(' ', $titles);

        $this->assertStringContainsString('Zuladung', $joined, 'Die Titel müssen aus der Titelspalte kommen.');
    }

    #[Test]
    public function a_date_written_with_hyphens_is_read(): void
    {
        // Grob writes "12-May-1981". Unambiguous -- a month name cannot be
        // mistaken for a day -- but the pattern only allowed spaces and dots,
        // so all 70 rows arrived undated.
        $rows = $this->sheet()->rows($this->text());

        $dated = array_filter($rows, static fn (array $r): bool => filled($r['issued_at'] ?? null));

        $this->assertGreaterThan(50, count($dated), 'Die Ausgabedaten müssen gelesen werden.');
    }

    #[Test]
    public function a_head_spread_wider_by_another_poppler_is_still_found(): void
    {
        /*
         * ─────────────────────────────────────────────────────────────────────
         * DIE REGRESSION, DIE NUR DIE CI SAH.
         *
         * pdftotext verteilt denselben Tabellenkopf je nach Version über
         * verschieden viele Zeilen -- 26.04 über drei, 25.03 über sieben, weil
         * die ältere Fassung mehr Leerraum stehen lässt. Der Leser borgte
         * fehlende Überschriften aus fest verdrahteten zwei Zeilen Umgebung und
         * wies das Blatt deshalb unter 25.03 rundheraus ab.
         *
         * Gesucht wird jetzt bis zur ersten Zeile mit einer Anweisungsnummer --
         * die Grenze, die das Dokument selbst setzt. Beide Fassungen liegen im
         * Repo, damit diese Zusicherung nicht davon abhängt, welches poppler
         * auf dem Rechner installiert ist, der sie prüft.
         * ─────────────────────────────────────────────────────────────────────
         */
        $wider = (string) file_get_contents(
            __DIR__.'/../../Fixtures/Grob/list-of-sb-817.layout-25.03.txt',
        );

        $sheet = $this->sheet();
        $rows = $sheet->rows($wider);

        $this->assertCount(70, $rows, 'Auch die breiter gesetzte Fassung wird ganz gelesen.');
        $this->assertSame([], $sheet->skipped());
        $this->assertSame('817-1', $rows[0]['number']);
    }

    private function sheet(): OverviewSheet
    {
        return new OverviewSheet(
            '/^(?:[MOAR]SB)?\d{3,4}-\d+/',
            ['number' => ['sb no'], 'title' => ['title'], 'date' => ['issue date']],
            false,
            null,
            null,
            false,
            false,
            true,
        );
    }

    /**
     * Die Textfassung des Blattes, nicht das PDF.
     *
     * ─────────────────────────────────────────────────────────────────────────
     * WEIL DIESER TEST DEN LESER PRÜFT UND NICHT PDFTOTEXT. Er behauptet
     * Spaltenpositionen -- 0 für die Nummern, 24 für die Titel, 56 gerade NICHT
     * -- und die sind eine Eigenschaft der ausgelesenen Textfassung, nicht des
     * Dokuments.
     *
     * Gemessen an ein und demselben G-109-PDF, gleicher Aufruf:
     *
     *   poppler 26.04   "SB No. /" Zeile 13, "Title" Zeile 15, Titelspalte 24
     *   poppler 25.03   "SB No. /" Zeile 19, "Title" Zeile 25, Titel bei 56
     *
     * Gegen das PDF geprüft, prüfte dieser Test also mit, welches poppler
     * zufällig installiert ist: hier grün, in der CI rot. Gegen die
     * mitgelieferte Textfassung prüft er, was er meint.
     *
     * BEIDE FASSUNGEN LIEGEN IM REPO. Die zweite ist kein toter Ballast: sie
     * ist der Beleg dafür, dass die Kopferkennung mit beiden zurechtkommt --
     * siehe reads_a_head_spread_wider_by_another_poppler(). Dass die
     * SPALTENZUORDNUNG darunter auseinanderläuft, ist ein offener Punkt und in
     * docs/INFRASTRUKTUR.md notiert, nicht hier versteckt.
     * ─────────────────────────────────────────────────────────────────────────
     */
    private function text(): string
    {
        return (string) file_get_contents(
            __DIR__.'/../../Fixtures/Grob/list-of-sb-817.layout-26.04.txt',
        );
    }

    /** @return list<int> */
    private function measuredColumns(OverviewSheet $sheet): array
    {
        $sheet->rows($this->text());

        $property = new \ReflectionProperty($sheet, 'measured');

        return $property->getValue($sheet);
    }

    #[Test]
    public function the_grob_sheet_yields_whole_titles(): void
    {
        /*
         * ─────────────────────────────────────────────────────────────────────
         * Zur Anforderung: "bitte die vollstaendigen daten ... der user
         * will nicht ueberall rein schauen muessen und es vereinfacht die
         * durchsuchbarkeit der liste."
         *
         * A title has to be findable by what it says, so a fragment ("Zuladung")
         * is not enough -- the entry it belongs to is
         * "Aenderung des Fluggewichtes von 810 kg auf 825 kg zur Erhoehung der
         * Zuladung", and nobody searches for the last word.
         * ─────────────────────────────────────────────────────────────────────
         */
        $sheet = $this->specSheet();
        $rows = $sheet->rows($this->text());

        $this->assertCount(68, $rows);

        $first = $rows[0];
        $this->assertSame('817-1', $first['number']);
        $this->assertStringContainsString('Änderung des Fluggewichtes', $first['title']);
        $this->assertStringContainsString('Zuladung', $first['title']);
        $this->assertSame('1981-05-12', $first['issued_at']);
    }

    #[Test]
    public function both_poppler_layouts_yield_the_same_columns_and_titles(): void
    {
        /*
         * ─────────────────────────────────────────────────────────────────────
         * DIE VERSIONSABHÄNGIGKEIT, ABGESCHAFFT STATT UMGANGEN.
         *
         * Hier stand einmal das Gegenteil: ein angepinnter Test, der festhielt,
         * dass unter poppler 25.03 Grobs Titel Fragmente sind ("810 kg auf 825
         * kg zur Erhöhung der"). Die Ursache lag nicht bei poppler, sondern im
         * Leser -- Seitenmöbel wurden zeichengenau verglichen, und 25.03 setzt
         * dieselbe Kopfzeile je Seite um ein Zeichen versetzt. Sie zählte
         * deshalb nicht als Wiederholung, blieb in der Spaltenmessung stehen,
         * und die mittig gesetzte Überschrift "Title" rastete auf sich selbst
         * bei 56 statt auf die Titelspalte bei 24.
         *
         * Seit der Leerraum vor dem Zählen eingeebnet wird, messen BEIDE
         * Fassungen dieselben Spalten und liefern dieselben Titel. Vorgesehen war
         * angeboten, die Version einfach festzunageln -- Docker und PVE geben
         * das her. Das wäre die kleinere Lösung gewesen: sie hätte die dritte
         * Auslieferung (eigener Server, fremdes poppler) ungeschützt gelassen.
         *
         * Beide Fassungen bleiben im Repo, damit diese Zusicherung nicht davon
         * abhängt, was auf dem prüfenden Rechner installiert ist.
         * ─────────────────────────────────────────────────────────────────────
         */
        $sheet = $this->specSheet();
        $wider = $sheet->rows((string) file_get_contents(
            __DIR__.'/../../Fixtures/Grob/list-of-sb-817.layout-25.03.txt',
        ));

        $narrow = $this->specSheet()->rows($this->text());

        $this->assertCount(68, $wider);
        $this->assertCount(68, $narrow);

        $this->assertStringContainsString('Änderung des Fluggewichtes', $wider[0]['title']);
        $this->assertSame($narrow[0]['title'], $wider[0]['title'], 'Beide Fassungen, ein Titel.');
        $this->assertSame($narrow[0]['number'], $wider[0]['number']);
    }

    #[Test]
    public function the_residual_english_prefix_is_pinned_to_six(): void
    {
        /*
         * WHAT IS STILL WRONG, held to its measured size rather than hidden.
         *
         * Six of the sixty-eight titles carry the PREVIOUS entry's English as a
         * prefix. Their own German follows in full, so the line is findable --
         * but text from a neighbouring directive is in it, and that must not be
         * allowed to grow unnoticed. Same treatment as SZD's 35 mid-sentence
         * titles in docs/LTA-TM.md §15: named, counted, and watched.
         *
         * Number, issue date, bindingness and effectivity are unaffected.
         */
        $english = '/^(Increase|Special|Installation|Supplements|Exchange|Modification'
            .'|Inspection|Replacement|Application|Introduction|Extension|Change'
            .'|Improvement|Additional|Conversion|Retrofit|certification)\b/i';

        $bleeding = array_filter(
            $this->specRows(),
            static fn ($row): bool => preg_match($english, $row->title) === 1,
        );

        $this->assertLessThanOrEqual(
            6,
            count($bleeding),
            'Der englische Vorspann darf nicht mehr Zeilen betreffen als gemessen.',
        );
    }

    /** @return list<DirectiveRow> */
    private function specRows(): array
    {
        $spec = SourceSpec::fromArray(
            Yaml::parseFile(resource_path('directive-sources/grob-g109.yaml')),
            'grob-g109.yaml',
        );

        return (new ConfiguredSource(
            $spec,
            new SavedPage(__DIR__.'/../../Fixtures/Grob/list-of-sb-817.pdf'),
        ))->fetch(['model' => 'G 109']);
    }

    /** Der Leser, wie die Herstellerdatei ihn konfiguriert. */
    private function specSheet(): OverviewSheet
    {
        return SourceSpec::fromArray(
            Yaml::parseFile(resource_path('directive-sources/grob-g109.yaml')),
            'grob-g109.yaml',
        )->overviewSheet();
    }

    #[Test]
    public function a_long_sheet_is_measured_page_by_page(): void
    {
        /*
         * ─────────────────────────────────────────────────────────────────────
         * pdftotext lays each page out on its own: where a cell runs long the
         * columns beside it shift, and they shift back overleaf. Over Piper's 55
         * pages one measurement for the whole document produced 97 of some 2000
         * entries, with the number, subject and date of DIFFERENT rows mixed
         * together -- numbers reading "V-Band" and "Stabilator".
         *
         * Two more things were needed and both are declared, not guessed:
         * -fixed 2, because -layout renders Piper's number and subject as one
         * run of text ("2 Special Tubing in Fuselage"), and mdy, because the
         * dates are American and most of them are ambiguous.
         * ─────────────────────────────────────────────────────────────────────
         */
        $spec = SourceSpec::fromArray(
            Yaml::parseFile(resource_path('directive-sources/piper.yaml')),
            'piper.yaml',
        );

        $rows = $spec->overviewSheet()->rows(
            (new PdfLayoutText)->fromFile(__DIR__.'/../../Fixtures/Piper/sb-sl-index.pdf', $spec->overviewTextMode),
        );

        $this->assertGreaterThan(1500, count($rows), 'Der Index hat rund 1600 Eintraege.');

        $numbers = array_column($rows, 'number');
        $this->assertContains('2', $numbers);
        $this->assertContains('3', $numbers);

        $byNumber = [];
        foreach ($rows as $row) {
            $byNumber[$row['number']] ??= $row;
        }

        $this->assertSame('Special Tubing in Fuselage', $byNumber['2']['title']);
        $this->assertSame('Nose Heavy Condition', $byNumber['3']['title']);

        // 2/15/46 read the American way, and 46 resolved to 1946.
        $this->assertSame('1946-02-15', $byNumber['2']['issued_at']);
    }

    #[Test]
    public function nearly_every_piper_row_carries_its_date(): void
    {
        $spec = SourceSpec::fromArray(
            Yaml::parseFile(resource_path('directive-sources/piper.yaml')),
            'piper.yaml',
        );

        $rows = $spec->overviewSheet()->rows(
            (new PdfLayoutText)->fromFile(__DIR__.'/../../Fixtures/Piper/sb-sl-index.pdf', $spec->overviewTextMode),
        );

        $undated = array_filter($rows, static fn (array $r): bool => blank($r['issued_at'] ?? null));

        $this->assertLessThan(100, count($undated), 'Die allermeisten Zeilen tragen ein Ausgabedatum.');
    }

    #[Test]
    public function aquila_dates_are_right_but_for_the_one_that_is_not(): void
    {
        /*
         * Measured against the sheet itself: 39 of 40 comparable rows carry the
         * date the manufacturer printed. AT01-001 shares its page with the "list
         * of effective pages" and takes a revision date from it.
         *
         * Pinned rather than hidden, and pinned tightly: if a change makes this
         * worse the test says so, and if one makes it better the number moves
         * down deliberately.
         */
        $spec = SourceSpec::fromArray(
            Yaml::parseFile(resource_path('directive-sources/aquila.yaml')),
            'aquila.yaml',
        );

        $rows = $spec->overviewSheet()->rows(
            (new PdfLayoutText)->fromFile(__DIR__.'/../../Fixtures/Aquila/liste-der-sb.pdf'),
        );

        $dated = array_filter($rows, static fn (array $r): bool => filled($r['issued_at'] ?? null));

        $this->assertGreaterThan(200, count($dated), 'Fast jede Zeile traegt ein Ausgabedatum.');

        $byNumber = [];
        foreach ($rows as $row) {
            $byNumber[$row['number']] ??= $row;
        }

        $this->assertSame('2006-02-02', $byNumber['AT01-002']['issued_at']);
        $this->assertSame('2013-04-16', $byNumber['AT01-003']['issued_at']);
    }

    #[Test]
    public function the_g115_sheet_is_complete_but_for_its_titles(): void
    {
        /*
         * Built from the G 109 spec -- same three-line entries, same checkbox
         * matrix, same centred head. Number, date, bindingness and document all
         * come through; the TITLE begins mid-sentence, because the sheet sets it
         * at column 24 while its heading sits at 50 with a measured column in
         * between.
         *
         * Shipped with the defect named rather than withheld: the row is still
         * findable, and 185 correct dates are worth more than a blank source.
         * Pinned so it cannot quietly get worse.
         */
        $spec = SourceSpec::fromArray(
            Yaml::parseFile(resource_path('directive-sources/grob-g115.yaml')),
            'grob-g115.yaml',
        );

        $rows = $spec->overviewSheet()->rows(
            (new PdfLayoutText)->fromFile(__DIR__.'/../../Fixtures/Grob/list-of-sb-1078.pdf'),
        );

        $this->assertCount(185, $rows);

        $undated = array_filter($rows, static fn (array $r): bool => blank($r['issued_at'] ?? null));
        $this->assertSame([], $undated, 'Jede Zeile der G115 traegt ein Ausgabedatum.');

        $this->assertSame('1078-1', $rows[0]['number']);
        $this->assertSame('1987-06-24', $rows[0]['issued_at']);
    }

    #[Test]
    public function a_page_header_does_not_pass_for_a_table_head(): void
    {
        /*
         * EXTRA repeats "Doc. N°: EA-03704" in its page header -- which contains
         * the number heading -- and the real head sits two lines below it.
         * Anchoring on the first line that merely mentions a number and then
         * borrowing the subject from the window around it put the number column
         * at 167, where the numbers themselves sit at 0.
         *
         * The reader noticed and refused, which was right; it had the wrong
         * candidate. A line carrying BOTH headings itself is now preferred, and
         * Grob -- whose head is broken over three lines and where no line
         * carries both -- still falls through to the borrowing pass.
         */
        $spec = SourceSpec::fromArray(
            Yaml::parseFile(resource_path('directive-sources/extra.yaml')),
            'extra.yaml',
        );

        $rows = $spec->overviewSheet()->rows(
            (new PdfLayoutText)->fromFile(__DIR__.'/../../Fixtures/Extra/liste-sb-sl.pdf', $spec->overviewTextMode),
        );

        $this->assertGreaterThan(30, count($rows));
        $this->assertSame('SB-300-1-91', $rows[0]['number']);
        $this->assertSame('1991-10-22', $rows[0]['issued_at']);
    }
}
