<?php

declare(strict_types=1);

namespace Tests\Feature\Directives;

use App\Core\Documents\PdfLayoutText;
use App\Core\Http\HttpFetcher;
use App\Modules\Directives\Enums\Bindingness;
use App\Modules\Directives\Enums\DirectiveKind;
use App\Modules\Directives\Enums\SubjectKind;
use App\Modules\Directives\Sources\Configured\ConfiguredSource;
use App\Modules\Directives\Sources\Configured\OverviewSheet;
use App\Modules\Directives\Sources\Configured\SourceSpec;
use App\Modules\Directives\Sources\DirectiveRow;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Symfony\Component\Yaml\Yaml;
use Tests\TestCase;

/**
 * Scheibe Aircraft -- die Übersichten der Motorsegler und Segelflugzeuge.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * ZWEI DINGE WERDEN HIER FESTGEHALTEN, und das zweite ist das wichtigere.
 *
 * 1. Dass die Blätter gelesen werden. Scheibe ist der Hersteller des SF 25
 *    Falke, und die Zeilenzahlen unten stammen aus den Dokumenten selbst, von
 *    Hand gezählt:
 *
 *      Zugvogel III   12 Zeilen   LTM 1/66 … Gen. 01-2020
 *      SF 23           5 Zeilen   59-10/1, 62/1, 708-1, 708-2, Gen. 01-2020
 *      Bergfalke III  31 Zeilen   Änder.Nr. 3 … Gen. 01-2020
 *      SF 25 C        88 Zeilen   74 TMs der Reihe 653/657, dazu 14 Zeilen
 *                                 ohne eigene TM-Nummer (Änderungen der Zelle,
 *                                 fremde Hersteller, reine LTA-Zeilen)
 *
 * 2. Dass zwei davon NICHT vollständig gelesen werden, und woran das liegt.
 *    Die letzten beiden Tests halten den heutigen Stand fest, nicht das Ziel:
 *    Scheibe zentriert Überschrift und Zellinhalt in der Spalte, der Leser
 *    nimmt beides linksbündig an. Wer das in den gemeinsamen Klassen behebt,
 *    bekommt hier zwei rote Tests mit der Zahl, die dann stimmen muss.
 *
 * Der dritte Punkt ist die Adresse: Scheibes PDFs liegen auf einem CDN und
 * tragen "?Expires=…&Signature=…". Eine gespeicherte PDF-Adresse ist nach
 * Stunden tot. Der erste Test hält fest, dass bei jedem Abruf die Musterseite
 * geholt und die Adresse daraus neu gelesen wird.
 * ─────────────────────────────────────────────────────────────────────────────
 */
final class ScheibeTest extends TestCase
{
    // ── Die verfallende Adresse ─────────────────────────────────────────────

    #[Test]
    public function the_pdf_address_is_read_from_the_type_page_on_every_run(): void
    {
        /*
         * Der Grund, warum unter overview.documents die Musterseite steht und
         * nicht das PDF. Zwei Anfragen, in dieser Reihenfolge: die dauerhafte
         * Adresse /sf-25c, und daraus die signierte, die in wenigen Stunden
         * nicht mehr gilt.
         */
        $fetcher = new ScheibeFetcher('musterseite-sf-25c.html', 'uebersicht-sf-25c.pdf');

        try {
            (new ConfiguredSource($this->spec(), $fetcher))->fetch(['model' => 'SF 25 C']);
        } catch (RuntimeException) {
            // Warum dieser Abruf abbricht, steht im letzten Test dieser Datei.
        }

        $this->assertCount(2, $fetcher->asked);
        $this->assertSame('https://www.scheibe-aircraft.de/sf-25c', $fetcher->asked[0]);
        $this->assertStringContainsString('cdn.website-editor.net', $fetcher->asked[1]);

        // Die beiden Parameter, die die Adresse verfallen lassen. Stünde sie in
        // der Herstellerdatei, wäre der Abruf ab dem nächsten Tag ein 403 --
        // und ein 403 sieht aus wie "für dieses Muster gibt es nichts".
        $this->assertStringContainsString('Expires=', $fetcher->asked[1]);
        $this->assertStringContainsString('Signature=', $fetcher->asked[1]);
    }

    // ── Die Blätter ─────────────────────────────────────────────────────────

    #[Test]
    public function the_zugvogel_iii_sheet_is_read_whole(): void
    {
        $rows = $this->rows('uebersicht-zugvogel-iii.pdf');

        $this->assertCount(12, $rows);
        $this->assertSame('Änd. 2/65', $rows[0]['number']);
        $this->assertSame('01-2020', $rows[11]['number']);
    }

    #[Test]
    public function the_sf_23_sheet_is_read_whole(): void
    {
        /*
         * Fünf Zeilen, und drei Schreibweisen der Nummer auf einem einzigen
         * Blatt: "Änderungsanweis. 1", "62/1" aus den frühen Sechzigern, und
         * "708-1" nach dem Kennblatt A.579 (708).
         */
        $rows = $this->rows('uebersicht-sf-23.pdf');

        $this->assertCount(5, $rows);
        $this->assertSame(
            ['Änderungsanweis. 1', '62/1', '708-1', '708-2', '01-2020'],
            array_column($rows, 'number'),
        );
    }

    #[Test]
    public function the_issue_date_comes_from_under_the_number_and_never_from_the_datum_column(): void
    {
        /*
         * Scheibes Blatt hat eine Spalte "Datum (Date)" -- und die gehört dem
         * PRÜFER: dort trägt er bei der Jahresnachprüfung ein, wann er die
         * Anweisung abgearbeitet hat. Im Blatt ist sie leer.
         *
         * Das Ausgabedatum steht unter der Nummer, in derselben Zelle
         * ("Ausgabe / Edition"). Würde overview.headings die Spalte "datum"
         * beschreiben, käme für jede Zeile null zurück -- ein Blatt voller
         * Anweisungen ohne Ausgabedatum.
         */
        $rows = $this->rows('uebersicht-zugvogel-iii.pdf');

        $this->assertSame('1974-10-31', $this->find($rows, '214-1')['issued_at']);
        $this->assertSame('2020-11-30', $this->find($rows, '01-2020')['issued_at']);
    }

    #[Test]
    public function a_line_without_a_tm_number_is_carried_by_its_lta(): void
    {
        /*
         * Vier der zwölf Zeilen des Zugvogel III haben keine TM-Nummer: Scheibe
         * schreibt "---", weil das Dokument die LTA selbst ist. Sie sind
         * trotzdem Zeilen des unterschriebenen Blattes -- und die verbindlichen
         * dazu.
         */
        $rows = $this->rows('uebersicht-zugvogel-iii.pdf');
        $withoutNumber = array_values(array_filter($rows, fn (array $r): bool => $r['number'] === ''));

        $this->assertCount(4, $withoutNumber);
        $this->assertSame(
            ['67-96', '74-5', '82-216', '1989-018/3'],
            array_column($withoutNumber, 'authority_number'),
        );

        // Und die LTA-Nummer bleibt aus der Nummer heraus, wo es eine TM gibt.
        $this->assertSame('74-323/2', $this->find($rows, '653-1/75')['authority_number']);
    }

    #[Test]
    public function a_foreign_makers_note_gets_no_invented_number(): void
    {
        /*
         * "Tost Betr.Anw. / Nov. 1973" steht auf fast jedem Scheibe-Blatt: die
         * Betriebsanweisung des Kupplungsherstellers, verbindlich gemacht durch
         * LTA 74-5. Scheibe hat ihr keine TM-Nummer gegeben, also bekommt sie
         * hier auch keine -- die Zeile läuft unter 74-5.
         *
         * Das Nummernmuster erkennt den Eintrag trotzdem, sonst würde die Zeile
         * gar nicht erst beginnen und stattdessen als unerkannter Eintrag den
         * ganzen Abruf abbrechen.
         */
        $rows = $this->rows('uebersicht-zugvogel-iii.pdf');
        $tost = $this->find($rows, '', '74-5');

        $this->assertSame('', $tost['number']);
        $this->assertSame('Kupplungen justieren auf Einstellwert 7.', $tost['title']);
        $this->assertSame('Alle Werknummern', $tost['compliance']);
    }

    #[Test]
    public function the_durchfuehrung_column_decides_what_is_binding(): void
    {
        $rows = $this->directiveRows('Zugvogel III', 'uebersicht-zugvogel-iii.pdf');

        $this->assertCount(12, $rows);

        // "Bei Bedarf" und "Wahlweise alle Werknummern" -- Scheibes eigene
        // Wörter, aus der Spalte gezogen und nicht ausgedacht.
        $this->assertSame(Bindingness::Optional, $this->row($rows, '214-3/2')->bindingness);
        $this->assertSame(Bindingness::Optional, $this->row($rows, '214-2')->bindingness);

        // "Alle Werknummern" ist keine Wahl, sondern eine Betroffenheit.
        $this->assertSame(Bindingness::Mandatory, $this->row($rows, '214 – 4/1')->bindingness);

        // Und wo eine LTA danebensteht, ist die Zeile verbindlich, was immer in
        // der Spalte steht.
        $this->assertSame(Bindingness::Mandatory, $this->row($rows, '653-1/75')->bindingness);
    }

    #[Test]
    public function a_line_that_is_only_an_lta_is_recorded_as_one(): void
    {
        $rows = $this->directiveRows('Zugvogel III', 'uebersicht-zugvogel-iii.pdf');
        $lta = $this->row($rows, '82-216');

        $this->assertSame(DirectiveKind::Lta, $lta->kind);
        $this->assertSame(DirectiveKind::Tm, $this->row($rows, '214-1')->kind);
        $this->assertSame(SubjectKind::AircraftModel, $lta->subjectKind);
        $this->assertSame('Zugvogel III', $lta->subjectModel);
        $this->assertSame('Scheibe Aircraft', $lta->issuer);
    }

    #[Test]
    public function the_sheet_has_no_effectivity_column_so_none_is_invented(): void
    {
        /*
         * Andere Hersteller führen eine Spalte "Betroffene Werknummern".
         * Scheibe nicht -- die Betroffenheit steht als Fliesstext in der
         * Durchführungsspalte ("Werknummer 5500 bis einschl. 5586", "alle SF 25
         * C aus Lizenzfertigung Fa. Pützer"). Daraus einen Bereich zu schneiden
         * hiesse raten, deshalb bleiben die beiden Felder leer und der volle
         * Text steht in der Zusammenfassung.
         */
        $rows = $this->directiveRows('Zugvogel III', 'uebersicht-zugvogel-iii.pdf');
        $row = $this->row($rows, '214-2');

        $this->assertNull($row->serialFrom);
        $this->assertNull($row->serialTo);
        $this->assertStringContainsString('Wahlweise alle Werknummern', (string) $row->summary);
    }

    // ── Was dieser Leser mit Scheibes Blättern noch nicht kann ──────────────

    #[Test]
    public function the_sf_25_c_sheet_is_refused_rather_than_imported_short(): void
    {
        /*
         * DAS BLATT DES FALKE, und es geht heute nicht.
         *
         * Scheibe zentriert seine Spaltenüberschriften. Auf der SF 25 C steht
         * "Gegenstand" auf Position 74, die Einträge darunter beginnen bei 40.
         * Der Leser sucht die gemessene Spalte, die der Überschrift am nächsten
         * liegt, und findet 82 -- der ganze Gegenstand landet damit in der
         * Nummernspalte, und dort steht dann "Kupplungen alle 4 Jahre)" statt
         * einer TM-Nummer.
         *
         * Das Blatt führt 88 Zeilen. Gelesen würden 19. Genau dafür gibt es
         * skipped(): der Abruf bricht ab und nennt die Einträge, statt eine um
         * 69 Zeilen verkürzte Liste zu importieren.
         *
         * Wenn die Spaltenerkennung zentrierte Überschriften beherrscht, muss
         * dieser Test auf assertCount(88, …) umgestellt werden.
         */
        $sheet = $this->sheet();
        $sheet->rows($this->text('uebersicht-sf-25c.pdf'));

        $this->assertNotEmpty($sheet->skipped());
        $this->assertContains('Kupplungen alle 4 Jahre)', $sheet->skipped());

        // Und der Abruf lässt daraus nichts durch.
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/Nummernmuster nicht kennt/');

        (new ConfiguredSource(
            $this->spec(),
            new ScheibeFetcher('musterseite-sf-25c.html', 'uebersicht-sf-25c.pdf'),
        ))->fetch(['model' => 'SF 25 C']);
    }

    #[Test]
    public function a_centred_cell_still_lands_in_its_own_column(): void
    {
        /*
         * DER GEFÄHRLICHERE DER BEIDEN FEHLER WAR DIESER, weil er nichts
         * meldete -- und er ist behoben.
         *
         * Scheibe zentriert den Zellinhalt waagerecht: auf dem Bergfalke III
         * steht die TM-Nummer je nach Zeile auf Position 15 bis 25, und der
         * Strich "--" der Zeile "LTA 82-216 / Preßklemmen an Seilverbindungen"
         * auf 25 -- näher an der Gegenstandsspalte (33) als an der eigenen (15).
         * Die Zuordnung gab ihn dem Gegenstand, wo der Gegenstand schon stand;
         * die Zeile begann nie, und ihre LTA klebte an der Zeile darüber als
         * "--82-216". Das Blatt führt 31 Zeilen, gelesen wurden 30, gemeldet
         * wurde nichts.
         *
         * Zwei Zellen können sich keine Spalte teilen. Liegt zwischen ihnen
         * eine gemessene Spaltengrenze, ist die linke verrutscht und gehört in
         * ihre eigene Region -- siehe LayoutTable::assign().
         */
        $sheet = $this->sheet();
        $rows = $sheet->rows($this->text('uebersicht-bergfalke-iii.pdf'));

        $this->assertSame([], $sheet->skipped());
        $this->assertCount(31, $rows, 'Das Blatt führt 31 Zeilen.');

        // Die eigene LTA an der eigenen Zeile, und die Zeile darüber sauber.
        $this->assertContains('82-216', array_column($rows, 'authority_number'));
        $this->assertNotContains('--82-216', array_column($rows, 'authority_number'));
    }

    // ── Werkzeug ────────────────────────────────────────────────────────────

    /**
     * @return list<array{number: string, issued_at: ?string, authority_number: ?string,
     *                    subject: ?string, title: string, summary: string, compliance: ?string}>
     */
    private function rows(string $fixture): array
    {
        $sheet = $this->sheet();
        $rows = $sheet->rows($this->text($fixture));

        // Bei jeder Lesung geprüft und nicht in einem eigenen Test: ein Blatt,
        // dessen Muster veraltet ist, darf hier nie still durchgehen.
        $this->assertSame([], $sheet->skipped(), $fixture.': nichts darf unerkannt bleiben.');

        return $rows;
    }

    /** @return list<DirectiveRow> */
    private function directiveRows(string $model, string $fixture): array
    {
        return (new ConfiguredSource($this->spec(), new ScheibeFetcher(null, $fixture)))
            ->fetch(['model' => $model]);
    }

    /** @param list<array<string, mixed>> $rows */
    private function find(array $rows, string $number, ?string $authority = null): array
    {
        foreach ($rows as $row) {
            if ($row['number'] === $number
                && ($authority === null || $row['authority_number'] === $authority)) {
                return $row;
            }
        }

        $this->fail(sprintf('Keine Zeile "%s" im Blatt.', $number !== '' ? $number : (string) $authority));
    }

    /** @param list<DirectiveRow> $rows */
    private function row(array $rows, string $number): DirectiveRow
    {
        foreach ($rows as $row) {
            if ($row->number === $number) {
                return $row;
            }
        }

        $this->fail(sprintf('Keine Zeile %s im Blatt.', $number));
    }

    private function sheet(): OverviewSheet
    {
        return $this->spec()->overviewSheet();
    }

    private function spec(): SourceSpec
    {
        return SourceSpec::fromArray(
            Yaml::parseFile(resource_path('directive-sources/scheibe.yaml')),
            'scheibe.yaml',
        );
    }

    private function text(string $fixture): string
    {
        return (new PdfLayoutText)->fromFile(base_path('tests/Fixtures/Scheibe/'.$fixture));
    }
}

/**
 * Die Musterseite und das Blatt dahinter, beide gespeichert.
 *
 * Die Seite antwortet auf die dauerhafte Adresse, das PDF auf alles andere --
 * denn die Adresse des PDF steht nicht in der Herstellerdatei, sondern wird
 * jedes Mal aus der Seite gelesen, und sie sieht bei jedem Abruf anders aus.
 * Ohne Seite (page = null) antwortet der Abruf sofort mit dem PDF, was der
 * Leser ebenso akzeptiert: er prüft den Inhalt, nicht die Adresse.
 */
final class ScheibeFetcher implements HttpFetcher
{
    /** @var list<string> */
    public array $asked = [];

    public function __construct(private ?string $page, private string $pdf) {}

    public function get(string $url, array $headers = []): string
    {
        $this->asked[] = $url;

        $fixture = $this->page !== null && str_starts_with($url, 'https://www.scheibe-aircraft.de/')
            ? $this->page
            : $this->pdf;

        return (string) file_get_contents(__DIR__.'/../../Fixtures/Scheibe/'.$fixture);
    }
}
