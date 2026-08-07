<?php

declare(strict_types=1);

namespace App\Modules\Directives\Sources\Configured;

use App\Core\Documents\PdfLayoutText;
use App\Core\Http\FormFetcher;
use App\Core\Http\HttpFetcher;
use App\Core\Http\HttpNotFound;
use App\Modules\Directives\Enums\DirectiveKind;
use App\Modules\Directives\Sources\Aura\AuraClient;
use App\Modules\Directives\Sources\DirectiveRow;
use App\Modules\Directives\Sources\DirectiveSource;
use App\Modules\Directives\Sources\UnknownType;
use RuntimeException;

/**
 * One driver, many manufacturers.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * This is the whole point of the spec: the mechanism ships once and each
 * manufacturer is a file. A club that flies something we never anticipated drops
 * a YAML in and gets an import, without waiting for a release and without running
 * anybody's code.
 *
 * The bespoke Schleicher classes this replaces were 608 lines. What was genuinely
 * manufacturer-specific in them turned out to be patterns and column indices --
 * everything else was the same work every table-shaped list needs. That is why
 * this could become configuration at all, and why a manufacturer publishing a PDF
 * still cannot: there the work itself differs.
 * ─────────────────────────────────────────────────────────────────────────────
 */
final class ConfiguredSource implements DirectiveSource
{
    public function __construct(
        private readonly SourceSpec $spec,
        private readonly HttpFetcher $fetcher,

        // The overview sheets are PDFs. Injected rather than built inline so a
        // test can hand over a saved sheet, and because the core owns reading a
        // PDF's columns -- see PdfLayoutText.
        private readonly PdfLayoutText $pdf = new PdfLayoutText,
    ) {}

    /** The spec behind this source, for callers that need to know its shape. */
    public function spec(): SourceSpec
    {
        return $this->spec;
    }

    public function name(): string
    {
        return $this->spec->name;
    }

    public function label(): string
    {
        return $this->spec->label;
    }

    public function isAutomatic(): bool
    {
        return true;
    }

    /**
     * Whether this source can run at all right now.
     *
     * A spec that names an auth profile is useless without the credentials, and
     * saying so beats an import that returns an empty list -- which is
     * indistinguishable from a manufacturer with nothing new.
     */
    public function isUsable(): bool
    {
        if (! $this->spec->needsCredentials()) {
            return true;
        }

        [$user, $password] = $this->spec->credentials();

        return filled($user) && filled($password);
    }

    /**
     * @param  array{model?: string, url?: string, all?: bool}  $options
     * @return list<DirectiveRow>
     */
    public function fetch(array $options = []): array
    {
        if (! $this->isUsable()) {
            throw new RuntimeException(sprintf(
                '%s needs credentials. Set DIRECTIVES_%s_USER and DIRECTIVES_%s_PASSWORD in '
                .'.env -- they belong there rather than in the manufacturer file, so a spec '
                .'stays shareable.',
                $this->spec->label,
                strtoupper((string) $this->spec->authProfile),
                strtoupper((string) $this->spec->authProfile),
            ));
        }

        /*
         * The binding document, and therefore the first thing asked for.
         *
         * A spec that reads a sheet may still describe its manufacturer's table
         * or feed -- DG's stays in the file, and Schleicher's table is still
         * parsed by parseTypePage(). Those are the document library: where the
         * PDF behind a line lives. They are no longer where the lines come from.
         */
        if ($this->spec->isOverview()) {
            return $this->fetchOverview($options);
        }

        if ($this->spec->isAura()) {
            return $this->fetchAura($options);
        }

        if ($this->spec->isJson()) {
            return $this->fetchJson($options);
        }

        if (filled($options['url'] ?? null)) {
            return $this->parseTypePage(
                $this->fetcher->get((string) $options['url']),
                (string) ($options['model'] ?? ''),
                (string) $options['url'],
            );
        }

        // A list served ten at a time, which has to be walked more than once.
        if ($this->spec->isPaged()) {
            return $this->fetchPaged($options);
        }

        /*
         * One page for everything -- no index, no per-type request. Schempp-Hirth
         * lists all 458 notes for every type at once, and asking it per type
         * would be the same page fetched over and over.
         */
        if ($this->spec->isSinglePage()) {
            $url = $this->searchUrl((string) $this->spec->pageUrl, (string) ($options['model'] ?? ''));

            return $this->parseTypePage(
                $this->fetcher->get($url),
                (string) ($options['model'] ?? ''),
                $url,
            );
        }

        $types = $this->types();

        if (filled($options['model'] ?? null)) {
            $model = (string) $options['model'];
            $url = $this->matchType($types, $model);

            if ($url === null) {
                // The manufacturer's index says it is not one of theirs -- see
                // UnknownType. Every source is asked about every type in the
                // fleet, so most weeks most combinations land here.
                throw new UnknownType(sprintf(
                    '%s publishes no list for "%s". Known types: %s.',
                    $this->spec->label,
                    $model,
                    implode(', ', array_keys($types)),
                ));
            }

            return $this->parseTypePage($this->fetcher->get($url), $model, $url);
        }

        if (($options['all'] ?? false) !== true) {
            throw new RuntimeException(sprintf(
                'Choose a type, or ask for all of them explicitly. Available: %s.',
                implode(', ', array_keys($types)),
            ));
        }

        // Everything. One request per type, so it is never the default.
        $rows = [];

        foreach ($types as $model => $url) {
            foreach ($this->parseTypePage($this->fetcher->get($url), $model, $url) as $row) {
                $rows[] = $row;
            }
        }

        return $rows;
    }

    /**
     * The manufacturer's own overview sheet, read as the list.
     *
     * ─────────────────────────────────────────────────────────────────────────
     * ONE REQUEST, ONE DOCUMENT, AND A CHECK THAT IT WAS READ WHOLE.
     *
     * The sheet is the binding document -- the page an inspector signs -- so the
     * set of directives for a type is exactly what it lists. Which means an
     * unreadable line is not a cosmetic problem but a missing obligation, and
     * this refuses rather than importing a shortened list: OverviewSheet reports
     * every number-column entry its pattern did not recognise, and any report at
     * all stops the import for that type and names them.
     *
     * That is deliberately strict. A club that gets "1 Eintrag nicht erkannt:
     * TM-XY-3" can fix the manufacturer file that afternoon; a club that gets 63
     * of 64 rows and no message has an aircraft with an obligation nobody knows
     * about.
     * ─────────────────────────────────────────────────────────────────────────
     *
     * @param  array{model?: string, url?: string}  $options
     * @return list<DirectiveRow>
     */
    private function fetchOverview(array $options): array
    {
        $model = (string) ($options['model'] ?? '');
        $url = filled($options['url'] ?? null)
            ? (string) $options['url']
            : $this->overviewFor($model);

        $document = $this->overviewDocument($url);
        $sheet = $this->spec->overviewSheet();
        $rows = $sheet->rows($this->pdf->fromFile($document['path'], $this->spec->overviewTextMode));

        @unlink($document['path']);

        $skipped = $sheet->skipped();

        if ($skipped !== []) {
            throw new RuntimeException(sprintf(
                '%s: die Übersicht %s enthält %d Eintrag/Einträge, die das Nummernmuster '
                .'nicht kennt: %s. Die Übersicht ist das verbindliche Dokument -- eine '
                .'unvollständig gelesene Liste wäre schlimmer als keine, deshalb wurde '
                .'nichts übernommen. Bitte das Muster in der Herstellerdatei ergänzen.',
                $this->spec->label,
                $model !== '' ? $model : basename($document['url']),
                count($skipped),
                implode(', ', array_map(static fn (string $s): string => '"'.$s.'"', $skipped)),
            ));
        }

        return array_values(array_filter(array_map(
            fn (array $row): ?DirectiveRow => $this->overviewRow($row, $model, $document['url']),
            $rows,
        )));
    }

    /**
     * Where this type's sheet is.
     *
     * Either the spec lists it, or the manufacturer links it from the type's own
     * page -- Schleicher puts a "_TM_UE_D.pdf" beside every type's table. A type
     * nobody has a sheet for is an UnknownType rather than an empty result: most
     * weeks most manufacturers are asked about an aircraft they never built.
     */
    private function overviewFor(string $model): string
    {
        $listed = $model !== '' ? $this->spec->overviewDocumentFor($model) : null;

        if ($listed !== null) {
            return $listed;
        }

        // One sheet for everything -- the general notes that apply to every type.
        if ($this->spec->overviewUrl !== null) {
            return $this->spec->overviewUrl;
        }

        if ($model === '') {
            throw new RuntimeException(sprintf(
                '%s führt je Muster eine eigene Übersicht. Bitte ein Muster angeben.',
                $this->spec->label,
            ));
        }

        if ($this->spec->overviewPattern !== null && $this->spec->indexUrl !== '') {
            $types = $this->types();
            $page = $this->matchType($types, $model);

            if ($page === null) {
                throw new UnknownType(sprintf(
                    '%s publishes no list for "%s". Known types: %s.',
                    $this->spec->label,
                    $model,
                    implode(', ', array_keys($types)),
                ));
            }

            $sheet = $this->overviewUrl($this->fetcher->get($page));

            if ($sheet === null) {
                /*
                 * The type exists and has no sheet. Said out loud rather than
                 * quietly returning nothing: on Schleicher's site every aircraft
                 * page carries one, so a missing link means the page changed --
                 * and an empty result would read as "no directives".
                 */
                throw new RuntimeException(sprintf(
                    '%s: auf der Musterseite für "%s" steht keine Übersicht. Entweder hat '
                    .'der Hersteller die Seite geändert, oder für dieses Muster gibt es '
                    .'keine -- beides muss jemand ansehen.',
                    $this->spec->label,
                    $model,
                ));
            }

            return $sheet;
        }

        throw new UnknownType(sprintf(
            '%s führt keine Übersicht für "%s". Bekannt: %s.',
            $this->spec->label,
            $model,
            implode(', ', array_keys($this->spec->overviewDocuments)),
        ));
    }

    /**
     * The sheet itself, on disk.
     *
     * A landing page in between is normal -- DG's sheets live behind a file
     * manager whose page carries the actual download link. The PDF is written to
     * a temporary file because that is what pdftotext reads.
     *
     * @return array{path: string, url: string}
     */
    private function overviewDocument(string $url): array
    {
        $body = $this->fetcher->get($url);

        if (! str_starts_with($body, '%PDF') && $this->spec->overviewDocumentPattern !== null) {
            if (preg_match($this->spec->overviewDocumentPattern, $body, $m) !== 1) {
                throw new RuntimeException(sprintf(
                    '%s: auf %s steht kein Link auf die Übersicht als PDF.',
                    $this->spec->label,
                    $url,
                ));
            }

            $url = html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $body = $this->fetcher->get($url);
        }

        if (! str_starts_with($body, '%PDF')) {
            /*
             * Checked, because the alternative is worse than an error: a login
             * page or a 404 body handed to pdftotext yields nothing, and nothing
             * reads as "this manufacturer published no directives".
             */
            throw new RuntimeException(sprintf(
                '%s: %s hat kein PDF geliefert. Möglicherweise wurde die Datei verschoben '
                .'oder die Seite verlangt eine Anmeldung.',
                $this->spec->label,
                $url,
            ));
        }

        $path = tempnam(sys_get_temp_dir(), 'aeronance-overview-');

        if ($path === false || file_put_contents($path, $body) === false) {
            throw new RuntimeException('Die Übersicht liess sich nicht zwischenspeichern.');
        }

        return ['path' => $path, 'url' => $url];
    }

    /**
     * The identifier at the start of a cell, without whatever follows it.
     *
     * Deliberately conservative: it takes the first whitespace-separated token
     * and nothing else. A cell that begins with prose rather than a number
     * yields that prose, which is visible and correctable -- guessing which word
     * "looks like" the number would be neither.
     */
    private function leadingIdentifier(string $cell): string
    {
        $first = preg_split('/\s+/', trim($cell))[0] ?? '';

        return rtrim(trim($first), ',;');
    }

    /**
     * One line of the sheet as a directive.
     *
     * @param  array{number: string, issued_at: ?string, authority_number: ?string,
     *               subject: ?string, title: string, summary: string, compliance: ?string}  $row
     */
    private function overviewRow(array $row, string $model, string $url): ?DirectiveRow
    {
        /*
         * A row with no manufacturer number of its own is identified by the
         * authority's -- but by the NUMBER in that cell, not by the whole cell.
         *
         * Schleicher's ASK 21 sheet carries "1993-001/3 ersetzt 93-001/2 und
         * 93-001 v." in the LTA column: the number plus a note about what it
         * supersedes. Taking the cell whole put forty-four characters of prose
         * into a field an inspector reads as an identifier -- and two rows down
         * the same directive would never match itself.
         *
         * The full text stays in externalReference, where the supersession note
         * is worth having. Only the leading token becomes the number.
         */
        $number = $row['number'] !== ''
            ? $row['number']
            : $this->leadingIdentifier((string) $row['authority_number']);

        if ($number === '') {
            return null;
        }

        $compliance = (string) ($row['compliance'] ?? '');
        $subject = (string) ($row['subject'] ?? '');

        return new DirectiveRow(
            number: $number,
            title: $row['title'] !== '' ? $row['title'] : $number,

            // A line with no number of the manufacturer's own IS the authority's
            // document -- LS4 lists EASA AD 2022-0230 with a dash where the TM
            // number would be, because there never was a TM.
            kind: $row['number'] !== ''
                ? $this->spec->defaultKind
                : $this->authorityKind($row['authority_number']),
            subjectKind: $this->spec->subjectKind,

            /*
             * The urgency column is why this sheet is worth reading: the document
             * library says nothing at all about how binding a note is.
             *
             * Some manufacturers put it in the NUMBER instead. Grob prints a
             * cross in one of four narrow columns (Alert/Mandatory/Recommended/
             * Optional) -- unreadable without deciding which column a bare "X"
             * belongs to, and being one column out turns "recommended" into
             * "optional", the direction that lets a note be waived. The same
             * sheet spells it out in the designations of its newer bulletins:
             * MSB817-59, OSB817-61, RSB817-75. Reading those is a measurement;
             * reading the crosses would be a guess.
             */
            bindingness: $this->spec->mapsCompliance() || $this->spec->bindingnessSource !== null
                ? $this->spec->bindingnessFor(
                    $row['authority_number'],
                    $this->spec->bindingnessSource === 'number' ? $row['number'] : $compliance,
                )
                : null,
            issuer: $this->spec->issuer,
            summary: $this->overviewSummary($row),

            // The one field only this sheet has. The library carries the moment
            // of a bulk import and nothing else.
            issuedAt: $row['issued_at'],

            // Never lifted out of prose -- a deadline inside a sentence is a
            // deadline that can be lifted out wrongly.
            complyBefore: null,
            subjectModel: $model !== '' ? $model : null,
            serialFrom: $this->serialRange($subject)[0],
            serialTo: $this->serialRange($subject)[1],
            isRecurring: false,

            // The sheet itself, until the document library can name the single
            // PDF behind this line. It is the document an inspector wants.
            referenceUrl: $url,
            externalReference: $row['authority_number'],
        );
    }

    /**
     * @param  array{issued_at: ?string, authority_number: ?string, subject: ?string,
     *               title: string, summary: string, compliance: ?string}  $row
     */
    private function overviewSummary(array $row): ?string
    {
        $parts = [];

        if (filled($row['authority_number'])) {
            $parts[] = 'LTA/AD: '.$row['authority_number'];
        }

        if (filled($row['compliance'])) {
            $parts[] = 'Dringlichkeit: '.$row['compliance'];
        }

        if (filled($row['subject'])) {
            $parts[] = 'Betroffen: '.$row['subject'];
        }

        // The whole cell, both languages, verbatim -- the title is one line of
        // it and a person reading the row wants the rest.
        if ($row['summary'] !== '' && $row['summary'] !== $row['title']) {
            $parts[] = $row['summary'];
        }

        return $parts === [] ? null : implode("\n", $parts);
    }

    /**
     * A manufacturer that answers with JSON.
     *
     * ─────────────────────────────────────────────────────────────────────────
     * The second mode, and DG Aviation is why: their list is built by JavaScript
     * from a file-manager plugin, so the HTML holds folder placeholders and no
     * rows at all. Reading it as a table was never going to work.
     *
     * The shape is the same as the table mode -- a per-type request, rows out of
     * it, the same domain rules applied afterwards. Only "where a field lives"
     * changes: a dotted path instead of a cell index.
     * ─────────────────────────────────────────────────────────────────────────
     *
     * @param  array{model?: string, type_id?: string, all?: bool}  $options
     * @return list<DirectiveRow>
     */
    /**
     * A manufacturer whose portal answers an API instead of publishing a sheet.
     *
     * ─────────────────────────────────────────────────────────────────────────
     * Three calls, in the order the portal itself makes them: which libraries
     * exist, which folders one holds, which files a folder holds. A library is a
     * type ("TechPubs HK36 Super Dimona"), and only the folders the spec names
     * are read -- Diamond keeps "Service Bulletins" beside "Service
     * Informations", flight manuals and parts catalogues.
     *
     * ONLY THE BULLETINS. The brief ruled on the equivalent case at Lindner: "eine
     * ti ist keine tm, bleibt draussen." A Service Information is the same class
     * of document.
     *
     * NO BINDINGNESS IS READ, and that is a measurement rather than an omission.
     * The old Dimona bulletins are numbered "SB No. 3/2 (M)" and the (M) means
     * mandatory -- but counted across all 2508 documents it appears on one per
     * cent, because the entire DA series numbers DA20-10-01 and has no (M)
     * anywhere. Taking it would have marked ninety-nine per cent of the
     * catalogue on no evidence. Vorgabe: "Die klassifizierung muss der Nutzer
     * erledigen."
     * ─────────────────────────────────────────────────────────────────────────
     *
     * @param  array<string, mixed>  $options
     * @return list<DirectiveRow>
     */
    private function fetchAura(array $options): array
    {
        $config = $this->spec->aura;
        $class = (string) ($config['classname'] ?? '');
        $methods = (array) ($config['methods'] ?? []);

        $client = new AuraClient(
            (string) ($config['url'] ?? ''),
            (string) ($config['page'] ?? '/'),
        );

        $libraries = $client->call($class, (string) ($methods['libraries'] ?? ''), [
            'member' => $config['member'] ?? null,
        ]);

        $client->assertNotEmpty($libraries, $this->spec->label.': die Bibliotheksliste');

        $wanted = (string) ($options['model'] ?? '');
        $rows = [];
        $discarded = [];

        foreach ($libraries as $library) {
            $model = $this->modelFromLibrary((string) ($library['Name'] ?? ''));

            if ($model === null) {
                continue;
            }

            /*
             * A club flies two types and the portal carries twenty. Asking for
             * all of them is dozens of requests to somebody else's server.
             *
             * Skipped where the models sit a level below the library: there the
             * library is one drawer for every engine, and filtering on its name
             * would throw away the whole drawer. The check moves down with the
             * model -- see the loop below.
             */
            if ($wanted !== '' && ! $this->hasModelFolders()
                && ! $this->modelMatches($model, $wanted)) {
                continue;
            }

            $folders = $client->call($class, (string) ($methods['folders'] ?? ''), [
                'parentfolderId' => $library['RootContentFolderId'] ?? null,
            ]);

            /*
             * ONE LEVEL DEEPER, where the manufacturer files by model first.
             *
             * Diamond's tree is library("TechPubs DA40") -> folder("Service
             * Bulletins") -> files, so the library names the model. Austro
             * Engine puts everything in ONE library and splits by engine
             * underneath: "Engine Documentation" -> "AE300 - AE330" ->
             * "Service Bulletins - Mandatory" -> files.
             *
             * Read with Diamond's assumption that produced nothing at all: the
             * model folders do not match a document-folder pattern, so no folder
             * was ever wanted and the source reported an empty list -- which is
             * indistinguishable from "Austro published nothing".
             */
            if ($this->hasModelFolders()) {
                [$folders, $models] = $this->expandModelFolders($client, $class, $methods, $folders, $wanted);
            } else {
                $models = [];
            }

            foreach ($folders as $index => $folder) {
                $name = (string) ($folder['Title'] ?? $folder['Name'] ?? '');

                if (! $this->isWantedFolder($name)) {
                    continue;
                }

                /*
                 * The model from the folder this one sits under, where the tree
                 * has that level. A separate name on purpose: assigning back to
                 * $model would carry one folder's engine into the next library.
                 */
                $folderModel = $models[$index] ?? $model;

                $files = $client->call($class, (string) ($methods['files'] ?? ''), [
                    'parentId' => $folder['Id'] ?? null,
                ]);

                foreach ($files as $file) {
                    $row = $this->auraRow($file, $folderModel);

                    if ($row !== null) {
                        $rows[] = $row;

                        continue;
                    }

                    // Named, never dropped -- see the refusal below. The
                    // description is empty on exactly the row this catches, so
                    // the file's own name is the fallback: a refusal that
                    // cannot say WHICH document is not actionable.
                    $discarded[] = sprintf(
                        '%s: "%s"',
                        $folderModel,
                        trim((string) ($file[(string) ($this->spec->columns['title'] ?? '')] ?? ''))
                            ?: trim((string) ($file['Title'] ?? ''))
                            ?: '(ohne Bezeichnung)',
                    );
                }
            }
        }

        /*
         * A file the portal carries and we cannot number stops the import.
         *
         * The measurement that decided this: across all 945 Service Bulletins
         * exactly ONE carries no number -- a DV20 Katana bulletin whose number
         * and description fields are both absent. Diamond filed one document
         * incompletely. Dropped quietly, that is a Service Bulletin nobody ever
         * hears about.
         *
         * Importing the other 55 and mentioning it would be worse than it looks:
         * the club then holds a list that LOOKS complete. Same reason the
         * overview mode refuses a line it cannot read, and the same answer --
         * a person adds it by hand and knows it is there.
         *
         * Taking the FILE NAME as the number instead was rejected, and the whole
         * catalogue says why: the file is named "SB20-054-1-M" while numbers in
         * that series read "SB20-54/1 Mandatory". The name is a different string
         * by convention, not by accident, so it would import a number the
         * manufacturer never issued. Vorgabe: "wir raten nicht. Niemals."
         */
        if ($discarded !== []) {
            throw new RuntimeException(sprintf(
                '%s: das Portal führt %d Dokument(e) ohne Nummer im Feld "%s": %s. '
                .'Übernommen wurde nichts -- eine Liste, der ein Dokument fehlt, wird '
                .'für vollständig gehalten. Bitte den Eintrag von Hand anlegen oder die '
                .'Herstellerdatei anpassen.',
                $this->spec->label,
                count($discarded),
                (string) ($this->spec->columns['number'] ?? '?'),
                implode(', ', $discarded),
            ));
        }

        return $rows;
    }

    /**
     * The model a library is for, or null if it is not a type library.
     *
     * Diamond mixes "TechPubs DA40-180" with "Engine Documentation" and "DAI
     * Damage Reports"; the pattern in the spec says which is which and captures
     * the model out of the name.
     */
    private function modelFromLibrary(string $name): ?string
    {
        $pattern = (string) ($this->spec->aura['library_pattern'] ?? '');

        if ($pattern === '') {
            return $name;
        }

        return preg_match($pattern, $name, $m) === 1 ? trim($m[1] ?? $m[0]) : null;
    }

    private function isWantedFolder(string $name): bool
    {
        $pattern = (string) ($this->spec->aura['folder_pattern'] ?? '');

        return $pattern === '' || preg_match($pattern, $name) === 1;
    }

    /** Whether this portal files by model BELOW the library rather than by library. */
    private function hasModelFolders(): bool
    {
        return (string) ($this->spec->aura['model_folder_pattern'] ?? '') !== '';
    }

    /**
     * The document folders one level down, each with the model it belongs to.
     *
     * Returns two lists sharing an index rather than a map, because two engines
     * have folders of the SAME name ("Service Bulletins - Mandatory" exists
     * under AE300 and under AE50R) and a map keyed by name would silently keep
     * only the last one -- eight bulletins gone, with nothing to show for it.
     *
     * @param  array<string, string>  $methods
     * @param  list<array<string, mixed>>  $folders
     * @return array{0: list<array<string, mixed>>, 1: list<string>}
     */
    private function expandModelFolders(
        AuraClient $client,
        string $class,
        array $methods,
        array $folders,
        string $wanted,
    ): array {
        $pattern = (string) ($this->spec->aura['model_folder_pattern'] ?? '');
        $out = [];
        $models = [];

        foreach ($folders as $folder) {
            $name = (string) ($folder['Title'] ?? $folder['Name'] ?? '');

            if (preg_match($pattern, $name, $m) !== 1) {
                continue;
            }

            $model = trim($m[1] ?? $m[0]);

            if ($wanted !== '' && ! $this->modelMatches($model, $wanted)) {
                continue;
            }

            foreach ($client->call($class, (string) ($methods['folders'] ?? ''), [
                'parentfolderId' => $folder['Id'] ?? null,
            ]) as $child) {
                $out[] = $child;
                $models[] = $model;
            }
        }

        return [$out, $models];
    }

    /**
     * Loose model matching, because a portal writes "HK36 Super Dimona" and a
     * club writes "HK 36 Super Dimona".
     */
    private function modelMatches(string $model, string $wanted): bool
    {
        $flatten = static fn (string $v): string => strtolower(
            preg_replace('/[^a-z0-9]+/i', '', $v) ?? $v
        );

        return str_contains($flatten($model), $flatten($wanted))
            || str_contains($flatten($wanted), $flatten($model));
    }

    /**
     * One file as a directive, or null if it carries no number.
     *
     * @param  array<string, mixed>  $file
     */
    private function auraRow(array $file, string $model): ?DirectiveRow
    {
        $columns = $this->spec->columns;
        $value = static fn (string $key): string => (string) ($file[(string) ($columns[$key] ?? '')] ?? '');

        $number = trim($value('number'));

        if ($number === '') {
            return null;
        }

        $marker = $this->numberMarker($number);
        $number = $this->strippedNumber($number);
        $document = trim($value('document'));

        return new DirectiveRow(
            number: $number,
            title: trim($value('title')) ?: $number,
            kind: $this->spec->defaultKind,
            subjectKind: $this->spec->subjectKind,

            /*
             * A SUGGESTION, and only where the manufacturer states one.
             *
             * Null here does not mean "optional" -- the model defaults an unset
             * bindingness to MANDATORY, deliberately, because a binding notice
             * treated as optional could be waived while the reverse only shows
             * up until somebody corrects it. So everything unmarked stays on the
             * strict side and a person decides.
             *
             * What this reads is a marker the manufacturer writes into the
             * number itself, extracted by an anchored pattern from the spec and
             * mapped through the same table every other source uses. Measured
             * over all 944 numbered bulletins: 295 MSB, 333 OSB, 85 RSB, plus 25
             * carrying the word ("SB20-9/3 Mandatory"); 206 of the DA20 series
             * carry nothing and stay mandatory.
             */
            bindingness: $marker !== null
                ? $this->spec->bindingnessFor(null, $marker)
                : null,
            issuer: $this->spec->issuer,
            issuedAt: ($d = trim($value('date'))) !== '' ? $this->date($d) : null,
            subjectModel: $model,
            referenceUrl: $document !== '' && filled($this->spec->aura['document_url'] ?? null)
                ? str_replace('{id}', $document, (string) $this->spec->aura['document_url'])
                : null,
        );
    }

    /**
     * The bindingness marker inside a number, or null if it carries none.
     *
     * ANCHORED on purpose. A loose search for "osb" anywhere in a number would
     * relax any document that happened to contain those letters, and relaxing is
     * the direction that can hurt: an optional notice read as binding is noise,
     * a binding one read as optional may be waived.
     */
    private function numberMarker(string $number): ?string
    {
        $pattern = (string) ($this->spec->aura['number_marker'] ?? '');

        if ($pattern === '' || preg_match($pattern, $number, $m) !== 1) {
            return null;
        }

        // Prefix or suffix -- whichever group matched.
        foreach (array_slice($m, 1) as $group) {
            if (trim((string) $group) !== '') {
                return trim((string) $group);
            }
        }

        return null;
    }

    /**
     * The number without the classification word Diamond appends to some of them.
     *
     * "SB20-9/3 Mandatory" is one document, not a number. Left in, the word also
     * makes the number UNSTABLE: were the manufacturer to reclassify it, the
     * string would change and the import would create a second directive rather
     * than update the first, splitting its assessments in two.
     */
    private function strippedNumber(string $number): string
    {
        $pattern = (string) ($this->spec->aura['number_strip'] ?? '');

        if ($pattern === '') {
            return $number;
        }

        return trim((string) preg_replace($pattern, '', $number)) ?: $number;
    }

    private function fetchJson(array $options): array
    {
        $typeId = $options['type_id'] ?? null;
        $model = (string) ($options['model'] ?? '');

        /*
         * An authority is asked by SEARCH TERM, not by an id it hands out. Where
         * the spec lists a term for this model, that is the type id -- see
         * SourceSpec::termFor().
         */
        if (blank($typeId) && $model !== '') {
            $typeId = $this->spec->termFor($model);
        }

        /*
         * A type id is demanded only where the spec HAS a type parameter. Where
         * it has none, one request carries everything -- C.E.A.P.R. does -- and
         * insisting on an id would refuse a source that needs no aircraft named.
         */
        if (blank($typeId) && ! $this->spec->fetchesEveryTypeAtOnce()) {
            throw new RuntimeException(sprintf(
                '%s needs the manufacturer\'s own type id for this request.',
                $this->spec->label,
            ));
        }

        $query = $this->spec->endpointQuery;

        if ($this->spec->typeParameter !== null) {
            $query[$this->spec->typeParameter] = (string) $typeId;
        }

        /*
         * A JSON response whose rows are a table, page by page.
         *
         * Continental answers ten at a time and ignores every page-size
         * parameter tried, so the pages have to be walked. Stopping on the FIRST
         * page that adds nothing new rather than on load_more alone: a feed that
         * repeats itself would otherwise be walked to max_pages, and a feed that
         * lies about load_more would be cut short.
         */
        if ($this->spec->rowsHtmlPath !== null) {
            return $this->fetchJsonRowsAsTable($query, $model);
        }

        if ($this->spec->postsToEndpoint()) {
            if (! $this->fetcher instanceof FormFetcher) {
                throw new RuntimeException(sprintf(
                    '%s is declared as a POST endpoint, but its fetcher cannot answer a form.',
                    $this->spec->label,
                ));
            }

            $body = $this->fetcher->post(
                $this->spec->indexUrl,
                $this->spec->endpointBody + $query,
                $this->authHeaders(),
            );
        } else {
            $url = $this->spec->indexUrl
                .(str_contains($this->spec->indexUrl, '?') ? '&' : '?')
                .http_build_query($query);

            $body = $this->fetcher->get($url, $this->authHeaders());
        }

        $decoded = json_decode($body, true);

        if (! is_array($decoded)) {
            throw new RuntimeException(sprintf(
                '%s did not answer with JSON. If it needs a login, the credentials may be '
                .'wrong or the session may have expired.',
                $this->spec->label,
            ));
        }

        $items = $this->dig($decoded, $this->spec->itemsPath);

        if (! is_array($items)) {
            return [];
        }

        $rows = [];

        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }

            $row = $this->jsonRow($item, $model);

            if ($row !== null) {
                $rows[] = $row;
            }
        }

        return $rows;
    }

    /**
     * Rows delivered as HTML inside a JSON envelope, page by page.
     *
     * @param  array<string, string>  $query
     * @return list<DirectiveRow>
     */
    private function fetchJsonRowsAsTable(array $query, string $model): array
    {
        $rows = [];
        $seen = [];
        $page = 1;

        do {
            $paged = $query;

            if ($this->spec->pageParameter !== null) {
                $paged[$this->spec->pageParameter] = (string) $page;
            }

            /*
             * POST where the spec says so, and Continental is why it matters:
             * the same route answers a GET, but IGNORES the page parameter --
             * every page comes back as page one. Walked with GET the import
             * would have stopped after ten of 250 bulletins and looked complete.
             */
            if ($this->spec->postsToEndpoint()) {
                if (! $this->fetcher instanceof FormFetcher) {
                    throw new RuntimeException(sprintf(
                        '%s is declared as a POST endpoint, but its fetcher cannot answer a form.',
                        $this->spec->label,
                    ));
                }

                $response = $this->fetcher->post(
                    $this->spec->indexUrl,
                    $this->spec->endpointBody + $paged,
                    $this->authHeaders(),
                );
            } else {
                $url = $this->spec->indexUrl
                    .(str_contains($this->spec->indexUrl, '?') ? '&' : '?')
                    .http_build_query($paged);

                $response = $this->fetcher->get($url, $this->authHeaders());
            }

            $decoded = json_decode($response, true);

            if (! is_array($decoded)) {
                throw new RuntimeException(sprintf(
                    '%s did not answer with JSON.',
                    $this->spec->label,
                ));
            }

            $fragment = $this->dig($decoded, $this->spec->rowsHtmlPath);

            if (! is_string($fragment) || trim($fragment) === '') {
                break;
            }

            /*
             * Wrapped, because the fragment is rows without their table and the
             * table pattern is what the reader looks for first. Everything after
             * this is the ordinary table reader.
             */
            $fresh = 0;

            foreach ($this->parseTypePage('<table>'.$fragment.'</table>', $model, $this->spec->indexUrl) as $row) {
                if (isset($seen[$row->number])) {
                    continue;
                }

                $seen[$row->number] = true;
                $rows[] = $row;
                $fresh++;
            }

            // Nothing new on a whole page means the feed is repeating itself.
            if ($fresh === 0) {
                break;
            }

            if ($this->spec->pageDelayMs > 0) {
                usleep($this->spec->pageDelayMs * 1000);
            }

            $page++;

            /*
             * The ceiling is a backstop, not an end condition -- and reaching it
             * is an ERROR rather than a result.
             *
             * A paged feed that stops at max_pages returns a list that looks
             * complete and is not: Continental holds 499 bulletins over 51
             * pages, and a cap of 40 handed back 400 of them with nothing to say
             * that 99 were missing. A short list of binding instructions is
             * worse than no list, because nobody goes looking for what they were
             * not told was absent.
             */
            if ($this->spec->pageParameter !== null && $page > $this->spec->maxPages) {
                throw new RuntimeException(sprintf(
                    '%s: nach %d Seiten (%d Einträgen) kamen immer noch neue Einträge, aber '
                    .'max_pages ist erreicht. Die Liste wäre unvollständig, deshalb wurde '
                    .'nichts übernommen -- bitte page.pagination.max_pages in der '
                    .'Herstellerdatei erhöhen.',
                    $this->spec->label,
                    $this->spec->maxPages,
                    count($rows),
                ));
            }
        } while ($this->spec->pageParameter !== null);

        return $rows;
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function jsonRow(array $item, string $model): ?DirectiveRow
    {
        $number = $this->spec->cleanNumber($this->field($item, 'number'));

        if ($number === '') {
            return null;
        }

        /*
         * The type from the ROW where the source carries one.
         *
         * C.E.A.P.R. answers with every type at once and names the affected ones
         * per line ("DR400/(...);R3000/(...)"), so there is no model to pass in.
         * Falling back to the option keeps every per-type source unchanged.
         */
        $rowModel = $this->field($item, 'model');

        if ($rowModel !== '') {
            $model = $rowModel;
        }

        $title = $this->field($item, 'title');
        $compliance = $this->field($item, 'compliance');

        /*
         * Where the manufacturer states the bindingness somewhere other than an
         * urgency column, that field decides -- and stays out of the summary,
         * which is what `compliance` would not have done.
         */
        $bindingnessText = $this->spec->bindingnessSource !== null
            ? $this->fieldByPath($item, $this->spec->bindingnessSource)
            : $compliance;
        $affected = $this->field($item, 'affected');
        $authorityNumber = $this->field($item, 'authority_number');
        $document = $this->field($item, 'document');
        $date = $this->date($this->field($item, 'date'));

        return new DirectiveRow(
            number: $number,
            title: $title !== '' ? $title : $number,
            kind: $this->spec->defaultKind,
            subjectKind: $this->spec->subjectKind,

            // The same rule as the table mode, from the same spec -- an authority
            // number wins, an unlisted wording is binding.
            bindingness: $this->spec->bindingnessFor(
                $authorityNumber !== '' ? $authorityNumber : null,
                $bindingnessText,
            ),
            issuer: $this->spec->issuer,
            summary: $this->summaryFrom($compliance, $affected),
            issuedAt: $date,

            // Never lifted out of prose, never inferred -- unchanged.
            complyBefore: null,
            subjectModel: $model !== '' ? $model : null,
            isRecurring: false,
            referenceUrl: $document !== '' ? $this->spec->documentUrlFor($document) : null,
            externalReference: $authorityNumber !== '' ? $authorityNumber : null,
        );
    }

    /**
     * One field out of an item, by the path the spec gives.
     *
     * Returns '' for a column the spec does not map, which is NOT what digging a
     * null path does: that returns the whole item. The first version passed the
     * null straight through and cast an array to a string on every spec that
     * omitted an optional column -- which is most of them.
     */
    private function field(array $item, string $name): string
    {
        $path = $this->spec->column($name);

        if ($path === null || $path === '') {
            return '';
        }

        $value = $this->dig($item, (string) $path);

        return is_scalar($value) ? $this->text((string) $value) : '';
    }

    /**
     * The same, but by a path given directly rather than by column name.
     *
     * @param  array<string, mixed>  $item
     */
    private function fieldByPath(array $item, string $path): string
    {
        $value = $this->dig($item, $path);

        return is_scalar($value) ? $this->text((string) $value) : '';
    }

    /**
     * The page address with this model's search term filled in.
     *
     * ─────────────────────────────────────────────────────────────────────────
     * An AUTHORITY is asked by term, not by an id it hands out -- the same shape
     * as the Federal Register, and for the same reason. EASA's tool answers a
     * plain GET with the whole result table, so the only thing missing is which
     * word to ask with.
     *
     * A URL without the placeholder is returned untouched, so every existing
     * single-page source is unaffected.
     *
     * REFUSED BY NAME where the spec knows no term for the model: asking without
     * one returns EVERY airworthiness directive there is, and thousands of
     * foreign ADs arriving as unassessed lines would block the release of an
     * aircraft none of them concern.
     */
    private function searchUrl(string $url, string $model): string
    {
        if (! str_contains($url, '{term}')) {
            return $url;
        }

        $term = $model !== '' ? $this->spec->termFor($model) : null;

        if ($term === null) {
            throw new UnknownType(sprintf(
                '%s kennt keinen Suchbegriff für "%s". Ohne einen käme die Liste ALLER '
                .'Lufttüchtigkeitsanweisungen zurück -- bitte das Muster in der '
                .'Herstellerdatei unter terms eintragen.',
                $this->spec->label,
                $model,
            ));
        }

        return str_replace('{term}', rawurlencode($term), $url);
    }

    /**
     * A dotted path into a decoded response.
     *
     * "files.0.title" or just "files". Deliberately tiny: a full expression
     * language would be a way to make specs clever, and clever specs are how a
     * config format turns back into code.
     */
    private function dig(mixed $data, ?string $path): mixed
    {
        if ($path === null || $path === '') {
            return $data;
        }

        foreach (explode('.', $path) as $segment) {
            if (! is_array($data) || ! array_key_exists($segment, $data)) {
                return null;
            }

            $data = $data[$segment];
        }

        return $data;
    }

    /** @return array<string, string> */
    private function authHeaders(): array
    {
        [$user, $password] = $this->spec->credentials();

        if (blank($user) || blank($password)) {
            return [];
        }

        return ['Authorization' => 'Basic '.base64_encode($user.':'.$password)];
    }

    private function summaryFrom(string $compliance, string $affected): ?string
    {
        $parts = [];

        if ($compliance !== '') {
            $parts[] = 'Dringlichkeit: '.$compliance;
        }

        if ($affected !== '') {
            $parts[] = 'Betroffen: '.$affected;
        }

        return $parts === [] ? null : implode("\n", $parts);
    }

    private function date(string $value): ?string
    {
        $value = trim($value);

        if ($value === '') {
            return null;
        }

        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $value, $m) === 1) {
            return sprintf('%s-%s-%s', $m[1], $m[2], $m[3]);
        }

        /*
         * Spaces after the dots are allowed because Zlin writes "25. 11. 2024",
         * which is ordinary Czech typography. A widening, not a change: every
         * date that parsed before parses to the same value.
         */
        if (preg_match('/(\d{1,2})\.\s*(\d{1,2})\.\s*(\d{4})/', $value, $m) === 1) {
            return sprintf('%04d-%02d-%02d', (int) $m[3], (int) $m[2], (int) $m[1]);
        }

        /*
         * SEPARATED BY DASHES, AND ONLY WHERE THE SPEC SAYS WHICH WAY ROUND.
         *
         * ─────────────────────────────────────────────────────────────────────
         * "26-10-2023" is a date twice over. Flight Design means 26 October;
         * an American sheet writing "05-10-2020" means 10 May. Nothing in the
         * string decides it, so nothing here guesses -- without a declared
         * order the field stays empty, exactly as it does for C.E.A.P.R.
         *
         * Measured before declaring it for Flight Design: of its 304 dates, 230
         * have a first number above twelve and NOT ONE has a second number
         * above twelve. Day first, proven by the sheet rather than assumed from
         * the manufacturer's country.
         *
         * Below the ISO branch on purpose: "2023-10-26" starts with four digits
         * and is already answered above.
         * ─────────────────────────────────────────────────────────────────────
         */
        if ($this->spec->dateOrder !== null
            && preg_match('/^(\d{1,2})-(\d{1,2})-(\d{4})$/', $value, $m) === 1) {
            [$day, $month] = $this->spec->dateOrder === 'mdy'
                ? [(int) $m[2], (int) $m[1]]
                : [(int) $m[1], (int) $m[2]];

            return checkdate($month, $day, (int) $m[3])
                ? sprintf('%04d-%02d-%02d', (int) $m[3], $month, $day)
                : null;
        }

        return $this->dateWithMonthName($value);
    }

    /**
     * A date written with the month's name instead of its number.
     *
     * ─────────────────────────────────────────────────────────────────────────
     * Two forms, both real and both unambiguous -- which is why they are read at
     * all, unlike C.E.A.P.R.'s numeric column where the order cannot be settled:
     *
     *   Limbach       "23. Mar 2006"    German day, English month
     *   MT-Propeller  "June 10, 2026"   English throughout
     *
     * A month NAME cannot be confused with a day, so neither form can silently
     * swap the two. Only these two orders are accepted; anything else stays null
     * rather than being guessed at.
     * ─────────────────────────────────────────────────────────────────────────
     */
    private function dateWithMonthName(string $value): ?string
    {
        static $months = [
            'jan' => 1, 'feb' => 2, 'mar' => 3, 'mär' => 3, 'maer' => 3, 'apr' => 4,
            'may' => 5, 'mai' => 5, 'jun' => 6, 'jul' => 7, 'aug' => 8, 'sep' => 9,
            'okt' => 10, 'oct' => 10, 'nov' => 11, 'dec' => 12, 'dez' => 12,
        ];

        $month = static function (string $name) use ($months): ?int {
            $key = mb_strtolower(mb_substr(trim($name), 0, 3));

            return $months[$key] ?? null;
        };

        // "23. Mar 2006" / "23 March 2006" / "24 September, 2010"
        //
        // The comma is UL Power's, who write the weekday too ("Friday 24
        // September, 2010"). It separates month from year and can carry no other
        // meaning there, so accepting it cannot make a date ambiguous -- the
        // same reasoning as the spaces Zlin puts in "25. 11. 2024".
        if (preg_match('/(\d{1,2})\.?\s+([A-Za-zÄÖÜäöü]{3,})\.?,?\s+(\d{4})/u', $value, $m) === 1
            && ($nr = $month($m[2])) !== null) {
            return sprintf('%04d-%02d-%02d', (int) $m[3], $nr, (int) $m[1]);
        }

        // "June 10, 2026" / "June 10 2026"
        if (preg_match('/([A-Za-zÄÖÜäöü]{3,})\.?\s+(\d{1,2})(?:st|nd|rd|th)?,?\s+(\d{4})/u', $value, $m) === 1
            && ($nr = $month($m[1])) !== null) {
            return sprintf('%04d-%02d-%02d', (int) $m[3], $nr, (int) $m[2]);
        }

        return null;
    }

    /**
     * The types this manufacturer publishes, model => url.
     *
     * @return array<string, string>
     */
    public function types(): array
    {
        $html = $this->fetcher->get($this->spec->indexUrl);

        preg_match_all($this->spec->linkPattern, $html, $matches, PREG_SET_ORDER);

        $found = [];

        foreach ($matches as $m) {
            // Named groups, so a spec says which capture is which rather than
            // depending on the order somebody happened to write them in.
            $url = $m['url'] ?? ($m[1] ?? null);
            $model = $this->text($m['model'] ?? ($m[2] ?? ''));

            if ($url === null || $model === '' || mb_strlen($model) > 60) {
                continue;
            }

            if ($this->spec->modelFilter !== null
                && preg_match($this->spec->modelFilter, $model) !== 1) {
                continue;
            }

            $found[$model] ??= $url;
        }

        return $found;
    }

    /**
     * The manufacturer's own overview list for a type, where the spec knows one.
     *
     * "Die reichen und können zum Hersteller verlinken" -- captured per type so
     * the aircraft type record can point at it.
     */
    public function overviewUrl(string $html): ?string
    {
        if ($this->spec->overviewPattern === null) {
            return null;
        }

        return preg_match($this->spec->overviewPattern, $html, $m) === 1 ? $m[1] : null;
    }

    /**
     * @return list<DirectiveRow>
     */
    public function parseTypePage(string $html, string $model, ?string $pageUrl = null): array
    {
        if ($this->spec->isList()) {
            return $this->parseList($html, $model, $pageUrl);
        }

        /*
         * ONE TABLE, OR ALL OF THEM -- and the spec has to say which.
         *
         * ─────────────────────────────────────────────────────────────────────
         * The first table is the right answer more often than not: Aviat prints
         * its Service Bulletins in one table and its Service LETTERS in a second
         * below, and a letter is not a directive. Reading both put four letters
         * on the club's list as though somebody had to act on them.
         *
         * Bristell is the opposite case, and the reason this is a choice at all:
         * two tables, one for the B23 and one for everything else, and the
         * second holds 11 of the 38 bulletins -- among them four safety alerts.
         * Taking the first alone returned 27 rows with an empty completeness
         * report and nothing to say a whole table had been passed over.
         *
         * Neither shape can be told from the other by looking, so it is
         * declared. Default stays at one, because that is what every spec
         * written before this assumed.
         * ─────────────────────────────────────────────────────────────────────
         */
        if (preg_match_all($this->spec->tablePattern, $html, $tableMatches) < 1) {
            return [];
        }

        $tables = $this->spec->allTables ? $tableMatches[0] : [$tableMatches[0][0]];

        preg_match_all($this->spec->rowPattern, implode("\n", $tables), $rowMatches);

        $rows = [];

        /*
         * A row that names a model instead of carrying a directive.
         *
         * ─────────────────────────────────────────────────────────────────────
         * Lange lists all its types in ONE table and separates them with a row
         * of two cells: "E1-Antares (Antares 20E)", then its notes, then
         * "Antares 23T", and so on. min_cells drops those rows -- correctly, they
         * are not directives -- and with them the only statement of which type
         * the rows underneath belong to. Nineteen 20E notes and three 23E notes
         * arrived indistinguishable from one another.
         *
         * So the row is read for the model before it is dropped for its width.
         * ─────────────────────────────────────────────────────────────────────
         */
        $section = $model;

        foreach ($rowMatches[1] ?? [] as $rowHtml) {
            if ($this->spec->sectionPattern !== null
                && preg_match($this->spec->sectionPattern, $this->text($rowHtml), $found) === 1) {
                $section = trim($found[1] ?? $found[0]);

                continue;
            }

            preg_match_all($this->spec->cellPattern, $rowHtml, $cellMatches);

            $cells = $cellMatches[1] ?? [];

            if (count($cells) < $this->spec->minCells || str_contains($rowHtml, '<th')) {
                continue;
            }

            $row = $this->toRow($cells, $rowHtml, $section, $pageUrl);

            if ($row !== null) {
                $rows[] = $row;
            }
        }

        return $rows;
    }

    /**
     * A list of links rather than a table.
     *
     * ─────────────────────────────────────────────────────────────────────────
     * LTB Lindner, who look after the Grob gliders, publish through a WordPress
     * PDF-manager: one <li> per document, no columns at all. The number and the
     * subject share one string, so each field is found by its own pattern.
     *
     * WHAT THIS MODE DELIBERATELY LEAVES EMPTY is the point. Lindner's items carry
     * a data-date, and it is tempting -- but 83 of the 92 entries on the G 103
     * page share 2021-04-05, which is when the documents were bulk-uploaded, not
     * when the notes were issued. Taking it would write 83 wrong dates into
     * aircraft records. A spec simply maps no date, the field stays empty, and a
     * visibly missing date beats a confidently wrong one.
     * ─────────────────────────────────────────────────────────────────────────
     *
     * @return list<DirectiveRow>
     */
    private function parseList(string $html, string $model, ?string $pageUrl): array
    {
        preg_match_all($this->spec->rowPattern, $html, $matches);

        $items = $matches[0] ?? [];
        $rows = [];
        $seen = [];

        foreach ($items as $item) {
            $number = $this->byPattern($item, 'number');

            if ($number === '') {
                continue;
            }

            /*
             * One document can appear twice on a page -- Lindner links several
             * language variants of the same note. Keyed on the number, so the
             * first wins and the list does not carry a row somebody has to assess
             * twice.
             */
            if (isset($seen[$number])) {
                continue;
            }

            $seen[$number] = true;

            $row = $this->rowFromItem($item, $model, $pageUrl);

            if ($row !== null) {
                $rows[] = $row;
            }
        }

        return $rows;
    }

    /**
     * One list item as a directive, or null if it is not one.
     *
     * ─────────────────────────────────────────────────────────────────────────
     * NULL IS A REAL ANSWER HERE, and it is new with DG.
     *
     * The other list sources publish nothing but directives, so a row that did
     * not match was a parsing failure. DG's document library holds 1894 files of
     * which roughly a third are directives; the rest are manuals, working
     * instructions, flight manuals and overviews. Reading those in as directives
     * would fill a club's overview with documents nobody can "comply with".
     *
     * The number pattern therefore decides what IS one, and it has to be
     * anchored: "MM DG-1000T Rev25 TM1000-52rev2 affected pages" is a manual
     * supplement whose title happens to contain a TM number. An unanchored
     * pattern invents a directive out of it.
     * ─────────────────────────────────────────────────────────────────────────
     */
    private function rowFromItem(string $item, string $model, ?string $pageUrl = null): ?DirectiveRow
    {
        /*
         * A paged TABLE row is read by cell, not by pattern.
         *
         * This path was written for lists, where every column IS a regex. EASA
         * is the first table that pages, and its columns are indices -- read as
         * patterns they are integers, which preg_match refuses outright.
         */
        if (! $this->spec->locatesFieldsByPattern()) {
            preg_match_all($this->spec->cellPattern, $item, $cellMatches);
            $cells = $cellMatches[1] ?? [];

            return count($cells) >= $this->spec->minCells
                ? $this->toRow($cells, $item, $model, $pageUrl)
                : null;
        }

        $number = $this->byPattern($item, 'number');

        if ($number === '') {
            return null;
        }

        $title = $this->byPattern($item, 'title');
        $compliance = $this->byPattern($item, 'compliance');
        $authorityNumber = $this->byPattern($item, 'authority_number');
        $document = $this->byPattern($item, 'document');
        $dateText = $this->byPattern($item, 'date');

        return new DirectiveRow(
            number: $number,
            title: $title !== '' ? $title : $number,
            kind: $this->spec->defaultKind,
            subjectKind: $this->spec->subjectKind,

            /*
             * A source with NO urgency column at all says nothing about
             * bindingness, which is different from a column that happens to be
             * empty.
             *
             * bindingnessFor() reads an empty column as mandatory, and that is
             * right where a column exists: Schleicher leaving one blank means
             * "no exception applies". DG's feed has no such column anywhere, so
             * asking it would turn silence into a claim -- every one of DG's
             * documents would arrive marked mandatory on no evidence at all,
             * and the optional ones the requirement was to have included would be
             * mislabelled the moment they arrived.
             *
             * Null instead, which DirectiveRow resolves from the kind: a TM is
             * optional until an authority adopts it. Either way the row arrives
             * unassessed and blocks the release until somebody qualified has
             * ruled on it -- the label is a starting point, not the answer.
             */
            bindingness: $this->spec->mapsCompliance()
                ? $this->spec->bindingnessFor(
                    $authorityNumber !== '' ? $authorityNumber : null,
                    $compliance,
                )
                : null,
            issuer: $this->spec->issuer,
            summary: $this->summaryFrom($compliance, ''),

            // Only if the spec maps one -- see the note above.
            issuedAt: $dateText !== '' ? $this->date($dateText) : null,
            complyBefore: null,
            subjectModel: $model !== '' ? $model : null,
            isRecurring: false,
            referenceUrl: $document !== '' ? $document : $pageUrl,
            externalReference: $authorityNumber !== '' ? $authorityNumber : null,
        );
    }

    /**
     * A list that arrives ten at a time, walked until it stops moving.
     *
     * ─────────────────────────────────────────────────────────────────────────
     * Walking the pages once is the obvious implementation and it silently loses
     * documents. DG's feed reorders between requests -- measured over 15 pages,
     * 12 of 165 entries came back twice -- and every duplicate on one page is a
     * document that slid off another before it was read.
     *
     * So the whole list is walked repeatedly until a complete pass finds nothing
     * new. That converges quickly (usually the second pass) and it is
     * self-limiting. A list still producing new entries after maxPasses is not
     * papered over with one more pass: it is reported, because at that point
     * nobody can say what "the list" even is.
     *
     * Where the manufacturer publishes an inventory -- a sitemap -- the result
     * is counted against it afterwards. That is the only check that can say
     * "fourteen missing" rather than "looks fine".
     * ─────────────────────────────────────────────────────────────────────────
     *
     * @param  array<string, mixed>  $options
     * @return list<DirectiveRow>
     */
    private function fetchPaged(array $options): array
    {
        $model = (string) ($options['model'] ?? '');
        $slug = $this->typeSlugFor($model);

        /** @var array<string, DirectiveRow> $found keyed by the item's own link */
        $found = [];
        $passes = 0;

        do {
            $passes++;
            $before = count($found);

            for ($page = 1; $page <= $this->spec->maxPages; $page++) {
                try {
                    $html = $this->fetcher->get(
                        $this->searchUrl($this->spec->pagedUrl($slug, $page), $model),
                    );
                } catch (HttpNotFound $e) {
                    /*
                     * How these lists end: WordPress serves a 404 past the last
                     * page rather than an empty feed.
                     *
                     * On the FIRST page it means something else entirely -- a
                     * wrong URL, or a type slug this manufacturer does not know.
                     * Treating that as "the list ended" would report an empty
                     * catalogue for a manufacturer publishing hundreds, which is
                     * the failure this module exists to prevent. So it is only
                     * ever an ending from page two on.
                     */
                    if ($page === 1) {
                        throw $e;
                    }

                    break;
                }

                // A pause between requests. 190 pages is a lot to ask of
                // somebody else's server in a burst, and DG starts refusing
                // connections when pushed -- which arrives as a transport error
                // mid-list, i.e. as an incomplete run.
                if ($this->spec->pageDelayMs > 0) {
                    usleep($this->spec->pageDelayMs * 1000);
                }

                preg_match_all($this->spec->rowPattern, $html, $matches);
                $items = $matches[0] ?? [];

                // Some feeds do serve an empty page instead. Both are endings.
                if ($items === []) {
                    break;
                }

                foreach ($items as $item) {
                    $key = $this->itemKey($item);

                    if ($key === '' || isset($found[$key])) {
                        continue;
                    }

                    $row = $this->rowFromItem($item, $model);

                    if ($row !== null) {
                        $found[$key] = $row;
                    } else {
                        // Remembered even when it is not a directive, so a
                        // manual does not get re-read on every pass -- and so the
                        // inventory check below sees it as "seen, not wanted"
                        // rather than "missing".
                        $found[$key] = null;
                    }
                }
            }

            $settled = count($found) === $before;
        } while (! $settled && $passes < $this->spec->maxPasses);

        if (! $settled) {
            throw new RuntimeException(sprintf(
                '%s: die Liste kam auch nach %d Durchläufen nicht zur Ruhe -- sie '
                .'ordnet sich zwischen den Abrufen um. Ein Import daraus wäre '
                .'unvollständig, ohne es zu zeigen.',
                $this->spec->label,
                $passes,
            ));
        }

        $this->assertNothingMissing(array_keys($found), $slug);

        return array_values(array_filter($found));
    }

    /**
     * Everything the manufacturer says it has, against what we read.
     *
     * Only possible for a whole-catalogue run: an inventory lists every document
     * there is, and cannot say which of them belong to one aircraft type. A
     * per-type fetch therefore relies on the settling loop alone, and this is
     * skipped rather than fudged with a guess about which entries "should" have
     * appeared.
     *
     * @param  list<string>  $seen
     */
    private function assertNothingMissing(array $seen, ?string $slug): void
    {
        if (! $this->spec->hasInventory() || filled($slug)) {
            return;
        }

        $inventory = $this->fetcher->get((string) $this->spec->inventoryUrl);

        preg_match_all((string) $this->spec->inventoryPattern, $inventory, $matches);

        $expected = array_unique($matches[1] ?? $matches[0] ?? []);

        if ($expected === []) {
            throw new RuntimeException(sprintf(
                '%s: das Dokumentverzeichnis liess sich nicht lesen. Ohne es lässt '
                .'sich nicht sagen, ob der Abruf vollständig war.',
                $this->spec->label,
            ));
        }

        $missing = array_values(array_diff(
            array_map([$this, 'normaliseLink'], $expected),
            array_map([$this, 'normaliseLink'], $seen),
        ));

        if ($missing === []) {
            return;
        }

        throw new RuntimeException(sprintf(
            '%s: %d von %d Dokumenten fehlen im Abruf, z. B. %s. Die Liste blättert '
            .'unzuverlässig; ein Import wäre unvollständig, ohne es zu zeigen.',
            $this->spec->label,
            count($missing),
            count($expected),
            implode(', ', array_slice($missing, 0, 3)),
        ));
    }

    /**
     * Trailing slashes and protocols differ between a feed and a sitemap.
     */
    private function normaliseLink(string $link): string
    {
        return rtrim(preg_replace('#^https?://#i', '', trim($link)) ?? $link, '/');
    }

    /** What identifies an item across two passes -- its own link, not its number. */
    private function itemKey(string $item): string
    {
        $link = $this->spec->itemLinkPattern;

        if ($link === null) {
            /*
             * A TABLE that pages needs its key from a cell, not a pattern.
             *
             * columns.number is a regex in a list spec and a cell INDEX in a
             * table one. Read as a regex it is the integer 0, and preg_match
             * refuses it -- "Delimiter must not be alphanumeric". Until EASA
             * there was no paged table to notice: DG and its kind are lists.
             */
            if (! $this->spec->locatesFieldsByPattern()) {
                preg_match_all($this->spec->cellPattern, $item, $cellMatches);

                return $this->text($this->cell($cellMatches[1] ?? [], 'number'));
            }

            return $this->byPattern($item, 'number');
        }

        return preg_match($link, $item, $m) === 1 ? trim($m[1] ?? $m[0]) : '';
    }

    /**
     * The manufacturer's own slug for a model, verified.
     *
     * A wrong slug in a query parameter does not fail -- the feed answers with
     * the whole catalogue or with nothing, and both read as an answer. So it is
     * checked against the manufacturer's own index of types, and an unknown
     * model is refused by name rather than turned into an empty result.
     */
    private function typeSlugFor(string $model): ?string
    {
        if ($model === '' || $this->spec->typeParameter === null) {
            return null;
        }

        $slug = strtolower(trim(preg_replace('/[^A-Za-z0-9]+/', '-', $model) ?? $model, '-'));

        if (! $this->spec->hasTypeIndex()) {
            return $slug;
        }

        $index = $this->fetcher->get((string) $this->spec->typeIndexUrl);

        preg_match_all((string) $this->spec->typeIndexPattern, $index, $matches);

        $known = array_map(
            static fn (string $v): string => strtolower(trim($v)),
            $matches[1] ?? $matches[0] ?? [],
        );

        if ($known === []) {
            throw new RuntimeException(sprintf(
                '%s: die Musterliste liess sich nicht lesen, deshalb lässt sich "%s" '
                .'nicht prüfen. Ein unbekanntes Muster liefert eine leere Liste, und '
                .'die sieht aus wie "nichts veröffentlicht".',
                $this->spec->label,
                $model,
            ));
        }

        if (! in_array($slug, $known, true)) {
            $suggestions = array_slice(array_values(array_filter(
                $known,
                static fn (string $k): bool => str_starts_with($k, substr($slug, 0, 2)),
            )), 0, 8);

            throw new UnknownType(sprintf(
                '%s kennt kein Muster "%s"%s',
                $this->spec->label,
                $model,
                $suggestions === []
                    ? '.'
                    : '. Gemeint sein könnte: '.implode(', ', $suggestions),
            ));
        }

        return $slug;
    }

    /**
     * One field out of a list item, by the pattern the spec gives.
     *
     * The first capture group if there is one, otherwise the whole match -- so a
     * spec can write either '/title="([^"]*)"/' or '/TM-[A-Z]?\d+/' and get what
     * it obviously meant.
     */
    private function byPattern(string $item, string $field): string
    {
        $pattern = $this->spec->column($field);

        if ($pattern === null || $pattern === '') {
            return '';
        }

        if (preg_match((string) $pattern, $item, $m) !== 1) {
            return '';
        }

        return $this->text($m[1] ?? $m[0]);
    }

    /**
     * @param  list<string>  $cells
     */
    private function toRow(array $cells, string $rowHtml, string $model, ?string $pageUrl): ?DirectiveRow
    {
        [$number, $date] = $this->numberAndDate($this->cell($cells, 'number'));

        /*
         * A separate date column, where the manufacturer has one.
         *
         * Schleicher puts the number and the date in one cell; Schempp-Hirth gives
         * the date its own. Reading the number cell for a date that is not there
         * simply yielded null before -- so this is an addition rather than a
         * change, and the Schleicher spec is untouched by it.
         */
        if ($this->spec->column('date') !== null) {
            $date = $this->dateFromCell($this->cell($cells, 'date')) ?? $date;
        }
        [$authorityNumber, $authorityDate] = $this->numberAndDate($this->cell($cells, 'authority_number'));

        if ($number === null && $authorityNumber === null) {
            return null;
        }

        // A row the manufacturer puts in its own table that is not a directive.
        if ($number !== null && $this->spec->ignoreNumber !== null
            && preg_match($this->spec->ignoreNumber, $number) === 1) {
            return null;
        }

        // What is not part of the number, off -- the same rule as the JSON mode.
        // MT-Propeller prints the file size next to it ("1R12 (.pdf, 1550k)"),
        // which changes with every revision and would make the number unstable.
        if ($number !== null) {
            $number = $this->spec->cleanNumber($number);
        }

        $title = $this->german($this->cell($cells, 'title'));
        $affected = $this->german($this->cell($cells, 'affected'));
        $compliance = $this->german($this->cell($cells, 'compliance'));

        /*
         * The kind in front of the number -- unless the manufacturer's own
         * number already carries it. See SourceSpec::$prefixKindInNumber.
         */
        $designation = $number === null
            ? (string) $authorityNumber
            : ($this->spec->prefixKindInNumber
                ? strtoupper($this->spec->defaultKind->value).' '.$number
                : $number);

        /*
         * A manufacturer's table can carry the AUTHORITY's directives too --
         * SOLO lists EASA ADs beside its own TMs. Recognised by how the number
         * reads, because that is the only thing that tells them apart there.
         */
        $isAuthority = $number !== null
            && $this->spec->authorityNumberPattern !== null
            && preg_match($this->spec->authorityNumberPattern, $number) === 1;

        return new DirectiveRow(
            number: $designation,
            title: $title !== '' ? $title : $designation,
            kind: match (true) {
                $number === null => $this->authorityKind($authorityNumber),
                $isAuthority => $this->authorityKind($number),
                default => $this->spec->defaultKind,
            },
            subjectKind: $this->spec->subjectKind,
            bindingness: $this->spec->bindingnessFor($authorityNumber, $compliance),
            issuer: $this->spec->issuer,

            // Everything the columns say, verbatim: an urgency column is free text
            // and no mapping survives it, so a person reads the original.
            summary: $this->summary($compliance, $affected, $authorityNumber, $authorityDate, $cells),

            issuedAt: $date ?? $authorityDate,

            // Never lifted out of prose -- a deadline inside a sentence is a
            // deadline that can be lifted out wrongly.
            complyBefore: null,

            subjectModel: $model !== '' ? $model : null,
            serialFrom: $this->serialRange($affected)[0],
            serialTo: $this->serialRange($affected)[1],

            // Never inferred from wording.
            isRecurring: false,

            referenceUrl: $this->document($rowHtml, $pageUrl) ?? $pageUrl,
            externalReference: $authorityNumber,
        );
    }

    /** @param  list<string>  $cells */
    private function cell(array $cells, string $field): string
    {
        $index = $this->spec->column($field);
        $cell = $index !== null && isset($cells[(int) $index]) ? $cells[(int) $index] : '';

        /*
         * Furniture removed before the cell is read.
         *
         * A responsive table repeats its column heading INSIDE every cell, for
         * the narrow layout where there is no header row to look up to. Stemme
         * does: each cell begins with <div class="identifier">Date/File</div>,
         * so the number came out as "Date/File SB_914_042" -- a designation the
         * manufacturer never wrote and that matches no document.
         *
         * Stripped rather than parsed around, because the label is not data: it
         * is the header, printed in the wrong place for our purposes.
         */
        if ($cell !== '' && $this->spec->cellStrip !== null) {
            $cell = (string) preg_replace($this->spec->cellStrip, '', $cell);
        }

        return $cell;
    }

    /**
     * @return array{0: ?string, 1: ?string}
     */
    private function numberAndDate(string $cell): array
    {
        $text = $this->text($cell);
        $date = null;

        if (preg_match('/(\d{1,2})\.(\d{1,2})\.(\d{4})/', $text, $m) === 1) {
            $date = sprintf('%04d-%02d-%02d', (int) $m[3], (int) $m[2], (int) $m[1]);
            $text = str_replace($m[0], '', $text);
        }

        // Issue markers are dropped: a revised note is the same note, and matching
        // on the bare number lets a re-import update the row.
        $text = preg_replace('/\b(Ausgabe|Issue|Rev\.?)\s+[IVX0-9]+/ui', '', $text) ?? $text;
        $text = trim(str_replace(['–', '—'], '-', $text));

        if ($text === '' || preg_match('/^[-\s]+$/', $text) === 1) {
            return [null, $date];
        }

        return [preg_replace('/\s+/', ' ', $text), $date];
    }

    private function dateFromCell(string $cell): ?string
    {
        return $this->date($this->text($cell));
    }

    private function authorityKind(?string $number): DirectiveKind
    {
        if ($number === null) {
            return DirectiveKind::Lta;
        }

        if ($this->spec->authorityKindPattern !== null) {
            return preg_match($this->spec->authorityKindPattern, $number) === 1
                ? DirectiveKind::Ad
                : DirectiveKind::Lta;
        }

        return stripos($number, 'AD') !== false ? DirectiveKind::Ad : DirectiveKind::Lta;
    }

    /**
     * @return array{0: ?string, 1: ?string}
     */
    private function serialRange(string $affected): array
    {
        $text = str_replace(["\u{00A0}"], ' ', $affected);

        if (preg_match('/(?:von|ab|from)\s+(?:Werk-?Nr\.?|S\/N)\s*([A-Za-z0-9\-\/]+)\s+(?:bis|to)\s+(?:Werk-?Nr\.?\s*)?([A-Za-z0-9\-\/]+)/ui', $text, $m) === 1) {
            return [$m[1], $m[2]];
        }

        if (preg_match('/\b(?:ab|from)\s+(?:Werk-?Nr\.?|S\/N)\s*([A-Za-z0-9\-\/]+)/ui', $text, $m) === 1) {
            return [$m[1], null];
        }

        if (preg_match('/\b(?:bis|to)\s+(?:Werk-?Nr\.?|S\/N)\s*([A-Za-z0-9\-\/]+)/ui', $text, $m) === 1) {
            return [null, $m[1]];
        }

        return [null, null];
    }

    /** @param  list<string>  $cells */
    private function summary(
        string $compliance,
        string $affected,
        ?string $authorityNumber,
        ?string $authorityDate,
        array $cells,
    ): ?string {
        $parts = [];

        if ($authorityNumber !== null) {
            $parts[] = sprintf(
                'LTA/AD: %s%s',
                $authorityNumber,
                $authorityDate !== null ? ' vom '.date('d.m.Y', strtotime($authorityDate)) : '',
            );
        }

        if ($compliance !== '') {
            $parts[] = 'Dringlichkeit: '.$compliance;
        }

        if ($affected !== '') {
            $parts[] = 'Betroffen: '.$affected;
        }

        $english = $this->english($this->cell($cells, 'title'));

        if ($english !== '') {
            $parts[] = $english;
        }

        return $parts === [] ? null : implode("\n", $parts);
    }

    private function document(string $rowHtml, ?string $base = null): ?string
    {
        $pattern = $this->spec->documentPattern ?? '#href="([^"]*\.pdf)"#i';

        if (preg_match($pattern, $rowHtml, $m) !== 1) {
            return null;
        }

        return $this->absoluteUrl($m[1], $base);
    }

    /**
     * A link as it stands, or made absolute against the page it was found on.
     *
     * ─────────────────────────────────────────────────────────────────────────
     * A relative href is ordinary HTML, and storing one is a dead link in every
     * record that carries it. Zlin is the source that showed it: all 577 rows
     * link to "/download/bulletin/<name>.pdf", so every single reference would
     * have been unusable -- and unusable in the quiet way, because the field is
     * filled and looks right until somebody clicks it.
     *
     * Resolved against the page rather than a spec field, because the answer is
     * not a manufacturer's decision: it is what a browser does.
     * ─────────────────────────────────────────────────────────────────────────
     */
    private function absoluteUrl(string $url, ?string $base): string
    {
        $url = trim($url);

        if ($url === '' || $base === null || preg_match('#^[a-z][a-z0-9+.-]*://#i', $url) === 1) {
            return $url;
        }

        $parts = parse_url($base);

        if (! isset($parts['scheme'], $parts['host'])) {
            return $url;
        }

        $origin = $parts['scheme'].'://'.$parts['host']
            .(isset($parts['port']) ? ':'.$parts['port'] : '');

        // Protocol-relative ("//host/path") keeps the page's scheme.
        if (str_starts_with($url, '//')) {
            return $parts['scheme'].':'.$url;
        }

        if (str_starts_with($url, '/')) {
            return $origin.$url;
        }

        $dir = rtrim(dirname($parts['path'] ?? '/'), '/');

        return $origin.$dir.'/'.$url;
    }

    /**
     * @param  array<string, string>  $types
     */
    private function matchType(array $types, string $model): ?string
    {
        $normalise = static fn (string $s): string => strtolower(
            preg_replace('/[^a-z0-9]/i', '', $s) ?? $s,
        );

        $wanted = $normalise($model);

        if ($wanted === '') {
            return null;
        }

        foreach ($types as $name => $url) {
            if ($normalise($name) === $wanted) {
                return $url;
            }
        }

        // Longest partial, so "ASK 21" does not accidentally pick "ASK 21 B".
        $best = null;
        $bestLength = 0;

        foreach ($types as $name => $url) {
            $candidate = $normalise($name);

            if ($candidate !== '' && str_contains($wanted, $candidate) && strlen($candidate) > $bestLength) {
                $best = $url;
                $bestLength = strlen($candidate);
            }
        }

        return $best;
    }

    /** The German half of a bilingual cell -- the English sits in a coloured div. */
    private function german(string $cell): string
    {
        return $this->text(
            preg_replace('#<div[^>]*class="[^"]*colorBlue[^"]*".*?</div>#is', '', $cell) ?? $cell,
        );
    }

    private function english(string $cell): string
    {
        return preg_match('#<div[^>]*class="[^"]*colorBlue[^"]*"[^>]*>(.*?)</div>#is', $cell, $m) === 1
            ? $this->text($m[1])
            : '';
    }

    private function text(string $html): string
    {
        $text = preg_replace('#<br\s*/?>#i', ' ', $html) ?? $html;
        $text = strip_tags($text);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = str_replace("\u{00A0}", ' ', $text);

        return trim(preg_replace('/\s+/', ' ', $text) ?? $text);
    }
}
