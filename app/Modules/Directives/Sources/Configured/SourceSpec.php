<?php

declare(strict_types=1);

namespace App\Modules\Directives\Sources\Configured;

use App\Modules\Directives\Enums\Bindingness;
use App\Modules\Directives\Enums\DirectiveKind;
use App\Modules\Directives\Enums\SubjectKind;
use App\Modules\Directives\Sources\SourceCredentials;
use InvalidArgumentException;

/**
 * How to read one manufacturer's TM/LTA list, as data.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * Vorgabe: "bau das Modul so das ich den abrufmechanismus pro hersteller per
 * config file einspielen kann, das macht die Verbreitung und updates einfacher."
 *
 * DATA, NOT CODE, and that is a hard line rather than a stylistic one. CLAUDE.md:
 * "Kein Code-Nachladen zur Laufzeit — in keiner Ausbaustufe." A spec that could
 * carry a callback would turn every manufacturer file into a way to run arbitrary
 * PHP on the club's server, which is exactly the door that guardrail closes. So a
 * spec is patterns and field mappings, interpreted by ONE driver that ships with
 * the release.
 *
 * The cost of that choice, stated plainly: this reads manufacturers who publish a
 * TABLE. Somebody who publishes a PDF, or a JavaScript-rendered list, still needs
 * a class. That covers Schleicher today, and the class seam stays for the rest.
 * ─────────────────────────────────────────────────────────────────────────────
 */
final readonly class SourceSpec
{
    /** An HTML table on a per-type page -- Schleicher and its kind. */
    public const TYPE_TABLE = 'table';

    /**
     * A list of links -- LTB Lindner, who look after the Grob gliders.
     *
     * Neither a table nor an API: a WordPress PDF-manager plugin renders one <li>
     * per document. Each item does carry structure -- a title attribute, the link,
     * a date -- but the number and the subject arrive as ONE string ("TM-G05
     * Seilrollen Durchmesser 42mm"), so a field is found by pattern rather than by
     * position. That is the only difference from the table mode.
     */
    public const TYPE_LIST = 'list';

    /**
     * A JSON endpoint -- DG Aviation and anybody else with an API.
     *
     * Added because DG's list is not a table at all: their site builds it with
     * JavaScript from a WordPress file-manager plugin, so the HTML carries folder
     * placeholders and nothing else. Reading that as a table was never going to
     * work, and the driver growing a second mode is what the brief anticipated when
     * he said the config format would.
     */
    public const TYPE_JSON = 'json';

    /**
     * The manufacturer's own overview sheet, as a PDF.
     *
     * ─────────────────────────────────────────────────────────────────────────
     * THE REGULAR WAY, not a special case. Vorgabe: "die übersicht ist das
     * bindende dokument, das haben alle hersteller. die anderen files sind die
     * details dazu."
     *
     * Every manufacturer here publishes one sheet per type -- DG as
     * "Uebersicht-Overview_TM-<Muster>", Schleicher as "<Kennblatt>_TM_UE_D.pdf",
     * Lindner as "<Muster>-LTA-TM-Uebersicht-<Datum>.pdf". It is the page an
     * inspector signs, one line per directive, carrying the issue date, the
     * authority's number, the effectivity by serial number and the urgency. The
     * document library beside it holds the details: the notes themselves, in two
     * languages, without a date anywhere.
     *
     * So the sheet is the list and the library is the attachments. That also
     * settles what completeness means -- reading ONE document to the end, which
     * OverviewSheet::skipped() can answer for, instead of hoping a paged feed
     * turned every page of a list that reorders between requests.
     *
     * The other modes stay. A library still has to be walked to link a line to
     * its document, and a manufacturer without a sheet still has a table.
     * ─────────────────────────────────────────────────────────────────────────
     */
    public const TYPE_OVERVIEW = 'overview';

    /**
     * A manufacturer whose portal answers an API instead of publishing a sheet.
     *
     * Diamond has no overview document at all; their Salesforce portal serves
     * the same content as structured data -- with an issue date on every row,
     * which no other source manages. Named for the platform rather than the
     * manufacturer, because every Experience Cloud portal answers this way.
     */
    public const TYPE_AURA = 'aura';

    /**
     * @param  array<string, int|string>  $columns  field => cell index (table) or path (json)
     * @param  list<string>  $optionalPhrases
     * @param  list<string>  $recommendedPhrases
     * @param  array<string, string>  $endpointQuery
     */
    public function __construct(
        public string $name,
        public string $label,
        public string $indexUrl,
        public string $linkPattern,
        public string $tablePattern,
        public string $rowPattern,
        public string $cellPattern,
        public array $columns,
        public string $type,

        /*
         * The name of a credential set in .env, never the credentials.
         *
         * CLAUDE.md: "Secrets & Tokens: nur in .env bzw. verschlüsselten
         * DB-Feldern." A manufacturer spec is meant to be shared -- a club should
         * be able to pass its DG file to the next club without passing its
         * password too, and a spec in the repo must never carry one.
         */
        public ?string $authProfile,

        /** Where the rows live in the response, e.g. "files" or "data.items". */
        public ?string $itemsPath,

        /** Extra query parameters for the per-type request. */
        public array $endpointQuery,

        /** How a type id is placed into the request, e.g. "categoryid". */
        public ?string $typeParameter,

        /**
         * A single page holding everything, for manufacturers with no per-type
         * split. Schempp-Hirth lists all 458 notes for every type at once.
         */
        public ?string $pageUrl,

        /**
         * A list served ten at a time.
         *
         * ─────────────────────────────────────────────────────────────────────
         * The query parameter that turns the page, and how many pages may be
         * asked for before the driver gives up. Null means the list arrives
         * whole, which is how every other manufacturer here works.
         *
         * DG serves its 1894 documents through a WordPress feed capped at ten
         * per page, and posts_per_page is ignored. That alone would be a chore
         * rather than a problem -- the problem is that the pages SHIFT between
         * requests. Measured over 15 pages: 12 of 165 entries came back twice,
         * and a duplicate on one page is a document missing from another. That
         * is why paging here repeats until a pass finds nothing new instead of
         * walking the pages once.
         * ─────────────────────────────────────────────────────────────────────
         */
        public ?string $pageParameter,
        public int $maxPages,
        public int $maxPasses,

        /** Milliseconds between two page requests -- politeness, and reliability. */
        public int $pageDelayMs,

        /**
         * Where the manufacturer lists everything it has, for counting against.
         *
         * A sitemap, usually. Not used to READ the directives -- it carries no
         * titles -- but to answer the one question a paged fetch cannot answer
         * for itself: did we see all of them? Without it, a run that lost
         * fourteen documents looks exactly like a run that lost none.
         */
        public ?string $inventoryUrl,
        public ?string $inventoryPattern,
        public ?string $itemLinkPattern,

        /**
         * The manufacturer's own list of types, for checking a model against.
         *
         * A wrong type slug in a query parameter does not fail -- it returns an
         * empty list, which reads as "nothing published for this aircraft". So
         * the slug is verified against the manufacturer's own index first, and
         * an unknown one is refused by name.
         */
        public ?string $typeIndexUrl,
        public ?string $typeIndexPattern,

        /**
         * An Apex endpoint and the three calls that walk it: which libraries
         * exist, which folders a library holds, which files a folder holds.
         *
         * The `member` is the portal's GUEST id, not anybody's account -- see
         * AuraClient. Without it the API answers SUCCESS with nothing.
         *
         * @var array<string, mixed>
         */
        public array $aura,

        // Form login -- the third way a manufacturer gates its list.
        public ?string $loginUrl,
        public string $loginFormPattern,
        public string $loginUserField,
        public string $loginPasswordField,
        /** @var array<string, string> */
        public array $loginExtra,
        public ?string $loginSuccessPattern,

        /**
         * A login the source can also do WITHOUT.
         *
         * C.E.A.P.R. is why this exists: the list answers anonymously (measured,
         * 286 rows), but subscribers get their subscription content behind the
         * same portal. An optional login must not gate the source -- without
         * credentials it runs anonymously instead of refusing, and the
         * credentials page offers the login rather than demanding it.
         */
        public bool $loginOptional,

        /**
         * A CA bundle completing an incomplete chain.
         *
         * Only ever ADDS an intermediate; the chain must still reach a trusted
         * root. Switching verification off is not an option this format offers,
         * and the difference between the two is the entire justification for it.
         */
        public ?string $caBundle,
        public DirectiveKind $defaultKind,
        public SubjectKind $subjectKind,
        public ?string $issuer = null,

        /**
         * Whether this sheet lists approved CHANGES rather than obligations.
         *
         * The manufacturers keep the two apart themselves -- Schleicher on a
         * page called "Allgemeine TM", DG in a sheet called "general" -- so the
         * spec says which kind of page it points at and every row inherits it.
         * Nothing is inferred from a source name.
         */
        public ?string $modelFilter = null,
        public ?string $overviewPattern = null,
        public ?string $documentPattern = null,
        public int $minCells = 2,
        public array $optionalPhrases = [],
        /** @var list<string> */
        public array $recommendedPhrases = [],
        public ?string $mandatoryOverride = null,
        public ?string $authorityKindPattern = null,

        // ── The overview sheet ──────────────────────────────────────────────
        //
        // What a directive number looks like on THIS manufacturer's sheets, and
        // what its columns are called. Nothing else about reading the sheet is
        // configurable: the mechanism is the same table for everybody, and only
        // the vocabulary differs. DG heads a column "Dringlichkeit" where
        // Schleicher heads it "Termin".
        public ?string $overviewNumberPattern = null,

        /** @var array<string, list<string>> field => lowercase heading fragments */
        public array $overviewHeadings = [],

        /** Whether the sheet repeats every row underneath itself in English. */
        public bool $overviewBilingual = false,

        /** Whether a blank line ends a row rather than sitting inside one. */
        public bool $overviewBlankSeparates = false,
        public bool $overviewNumbersCentred = false,

        /** Whether the sheet centres its headings over their columns. */
        public bool $overviewHeadingsCentred = false,

        /** Noise a sheet's drifting columns push into the title. */
        public ?string $overviewTitleStrip = null,

        /** Whether a long sheet is measured page by page rather than as a whole. */
        public bool $overviewColumnsPerPage = false,

        /**
         * A pdftotext layout flag other than the default -layout.
         *
         * Only for a sheet whose columns nearly touch: Piper sets the number one
         * space from the subject, and -layout renders the two as one run of
         * text. No column measurement recovers a boundary that is not in the
         * output. See PdfLayoutText.
         */
        public ?string $overviewTextMode = null,

        /** How a slash-separated date on this sheet is ordered: 'mdy' or 'dmy'. */
        public ?string $overviewDateOrder = null,

        /** A mark in front of every title, where the sheet truncates them. */
        public ?string $overviewTitlePrefix = null,

        /** Whether the rows tile contiguously around their centred numbers -- see OverviewSheet. */
        public bool $overviewRowsTile = false,

        /** Whether every table the pattern matches is read, not only the first -- see ConfiguredSource. */
        public bool $allTables = false,

        /**
         * How this source orders a date written with separators, where it uses one.
         *
         * Declared, never detected -- "05-10-2020" is a valid date read either
         * way. Without this the field stays empty rather than being guessed at.
         * The overview reader has its own copy of this for the same reason.
         */
        public ?string $dateOrder = null,

        /**
         * Whether this source is a SECOND list of documents somebody else owns.
         *
         * ─────────────────────────────────────────────────────────────────────
         * Most manufacturers publish their own directives and nobody else's.
         * Aquila publishes everything that concerns the AT01 -- their own notes
         * beside Rotax's, MT-Propeller's and Garmin's -- and uses the ORIGINAL
         * numbers: 67 of the 74 Rotax-shaped numbers on their sheet appear
         * character for character in rotax.yaml too.
         *
         * Filed as they stand, those 67 become a second copy of a directive the
         * club already has, and an inspector ticks each one twice.
         *
         * With this set, a row whose number ALREADY EXISTS under another source
         * is left to that source. What is unique to this sheet is still filed --
         * and that is the point: seven Rotax numbers appear only here, withdrawn
         * from Rotax's own search but still effective for early serials. Vorgabe: * "ich hätte lieber eine nicht mehr gültige tm, die ich als alt abhaken
         * kann wie eine zu wenig."
         *
         * Declared per source rather than derived, and off by default: it is a
         * statement about what a sheet IS, and no reading of the page can tell.
         * ─────────────────────────────────────────────────────────────────────
         */
        public bool $secondaryList = false,

        /** A line below which the sheet is no longer the directive table. */
        public ?string $overviewEndsAt = null,

        /** What this manufacturer writes in the number column that is not a number. */
        public ?string $overviewIgnore = null,

        /**
         * The sheet's address, per type.
         *
         * Written out rather than derived, because a manufacturer's file names
         * are nobody's system: DG's LS4 sheet is
         * "uebersicht-overview_tm-ls4_ls4-a_ls4-b-2" and its DG-300 sheet lives
         * under "dg single seaters". A guessed URL that 404s would read as "this
         * manufacturer publishes nothing for your aircraft", and one that hits
         * the wrong sheet would fill an aircraft's record with another type's
         * directives. Where a manufacturer links the sheet from the type's own
         * page -- Schleicher does -- page.overview_pattern finds it instead and
         * no list is needed.
         *
         * @var array<string, string>
         */
        public array $overviewDocuments = [],

        /** One sheet for everything -- the general notes that apply to every type. */
        public ?string $overviewUrl = null,

        /** How the PDF is found on the page the address leads to, if it is not the PDF itself. */
        public ?string $overviewDocumentPattern = null,

        /**
         * How the endpoint is asked -- GET, or a form POST.
         *
         * C.E.A.P.R. serves Robin's whole list as JSON without a login, but only
         * to a POST; every GET on the same path is a 404. Declared rather than
         * guessed, and GET stays the default because everybody else answers one.
         */
        public string $endpointMethod = 'GET',

        /**
         * The form fields that go with a POST.
         *
         * C.E.A.P.R. wants `nav`, and wants it EMPTY to mean "every type" -- an
         * unknown value there returns HTTP 200 with `{}`, which is precisely the
         * silent-nothing this module refuses to hand on as a result.
         *
         * @var array<string, string>
         */
        public array $endpointBody = [],

        /**
         * How a bare document id becomes an address.
         *
         * A manufacturer who serves JSON tends to give the file, not the link:
         * C.E.A.P.R.'s `fic` is "1784636809-090702_Installation-batterie.pdf" and
         * nothing else. Stored verbatim that is a broken reference in every
         * record. `{document}` is filled in, and a spec without this key keeps
         * the old behaviour of using the field as it stands.
         */
        public ?string $documentUrlTemplate = null,

        /**
         * What to cut off a number that is not part of it.
         *
         * C.E.A.P.R. appends the bindingness to the title-cum-number
         * ("SB 119 - Mandatory"), in five spellings. Left in place the number is
         * UNSTABLE: the day the manufacturer restages that note, the string
         * changes and the import files a second directive instead of updating the
         * first -- and the two carry different assessments. Diamond has the same
         * trap and strips it for the same reason.
         */
        public ?string $numberStrip = null,

        /**
         * Which field carries the bindingness, where it is not the urgency column.
         *
         * C.E.A.P.R. writes it into the document's own designation
         * ("SB 119 - Mandatory", 102 of 286; Recommended 48, Optional 7) and has
         * no urgency column at all. Pointing `compliance` at that field would
         * work, but the summary would then read "Dringlichkeit: SB 119 -
         * Mandatory", which is not an urgency and not what the manufacturer
         * said. So the marker is read from here and stays out of the summary.
         */
        public ?string $bindingnessSource = null,

        /**
         * Whether the document's kind is put in front of its number.
         *
         * ─────────────────────────────────────────────────────────────────────
         * TRUE by default, because that is what every source built so far
         * stores: Schleicher's "1234" is filed as "TM 1234", and the number is
         * the key an import matches on to UPDATE a directive rather than file a
         * second one. Flipping it globally would orphan every existing record.
         *
         * FALSE where the manufacturer's number already says what it is. Rotax
         * writes ASB-2026-001, SB-2026-002R00, SI-05-1998 -- prefixing those
         * yields "SB ASB-2026-001", a designation nobody at Rotax would
         * recognise and which no longer matches the document it names.
         * ─────────────────────────────────────────────────────────────────────
         */
        public bool $prefixKindInNumber = true,

        /**
         * Markup removed from every cell before it is read.
         *
         * For responsive tables, which repeat the column heading inside each
         * cell so a narrow layout has something to show. See cell().
         */
        public ?string $cellStrip = null,

        /**
         * What this manufacturer puts in the number column that is not one.
         *
         * MT-Propeller heads its own bulletin table with a row for the LIST of
         * bulletins ("List (.pdf, 135k)"). It is a document they publish, but it
         * is not a directive, and filed as one it becomes an open point nobody
         * can carry out. The overview reader has had `overview.ignore` for the
         * same reason since Scheibe; this is the same idea for a table.
         */
        public ?string $ignoreNumber = null,

        /**
         * A table row that names a model rather than carrying a directive.
         *
         * Lange separates the types in one table with a two-cell row. Dropped
         * for its width -- which is right, it is not a directive -- it takes the
         * only statement of which type the rows below belong to with it.
         */
        public ?string $sectionPattern = null,

        /**
         * A number that is an AUTHORITY's, not the manufacturer's.
         *
         * SOLO lists its own TMs and the EASA airworthiness directives that
         * concern the same engines in ONE table, distinguished only by how the
         * number reads: "TM 4603-1" beside "AD 2007-0001R1-E". Filed under the
         * source's default kind, twelve airworthiness directives would be
         * recorded as technical notes -- and the difference between the two is
         * exactly the difference between "must" and "may".
         *
         * The bindingness is unaffected either way (both default to binding),
         * so this corrects the label rather than the obligation.
         */
        public ?string $authorityNumberPattern = null,

        /**
         * Where a JSON response keeps its rows as HTML rather than as fields.
         *
         * ─────────────────────────────────────────────────────────────────────
         * Continental serves its 250 service bulletins through a WordPress AJAX
         * route that answers {"data": "<tr>…</tr>", "load_more": true}. The
         * visible <table id="bulletin-table"> in the page carries only a <thead>
         * -- read as HTML it is empty, which looks exactly like a manufacturer
         * with nothing published.
         *
         * So the response is JSON and the rows inside it are a table. Named as
         * such rather than given its own mode: everything after this point is
         * the ordinary table reader, with the ordinary row and cell patterns.
         * ─────────────────────────────────────────────────────────────────────
         */
        public ?string $rowsHtmlPath = null,

        /**
         * The value a type parameter takes, per aircraft model.
         *
         * ─────────────────────────────────────────────────────────────────────
         * The JSON mode otherwise takes its type id from the caller, which works
         * where the manufacturer hands out ids. An authority does not: the
         * Federal Register is asked with a SEARCH TERM, and the term that finds
         * a PA-18's directives is the one naming Piper.
         *
         * Written out per model rather than derived, for the same reason
         * overview.documents is: a guessed term does not fail, it returns an
         * empty list -- and an empty list of airworthiness directives reads as
         * "nothing was issued for your aircraft".
         *
         * @var array<string, string>
         */
        public array $endpointTerms = [],
    ) {}

    /**
     * @param  array<string, mixed>  $raw
     */
    public static function fromArray(array $raw, string $origin): self
    {
        $need = static function (array $data, string $key, string $origin): mixed {
            if (! array_key_exists($key, $data) || $data[$key] === null || $data[$key] === '') {
                throw new InvalidArgumentException(sprintf(
                    'The source spec %s is missing "%s".',
                    $origin,
                    $key,
                ));
            }

            return $data[$key];
        };

        $index = $raw['index'] ?? [];
        $page = $raw['page'] ?? [];
        $columns = $raw['columns'] ?? [];

        if (! is_array($index) || ! is_array($page) || ! is_array($columns)) {
            throw new InvalidArgumentException(sprintf(
                'The source spec %s must contain index, page and columns sections.',
                $origin,
            ));
        }

        $endpoint = $raw['endpoint'] ?? [];

        if (! is_array($endpoint)) {
            throw new InvalidArgumentException(sprintf(
                'The endpoint section of %s must be a mapping.',
                $origin,
            ));
        }

        $login = $raw['login'] ?? [];
        $tls = $raw['tls'] ?? [];
        $paging = $page['pagination'] ?? [];
        $inventory = $raw['inventory'] ?? [];
        $types = $raw['types'] ?? [];

        if (! is_array($login) || ! is_array($tls)) {
            throw new InvalidArgumentException(sprintf(
                'The login and tls sections of %s must be mappings.',
                $origin,
            ));
        }

        $type = (string) ($raw['type'] ?? self::TYPE_TABLE);

        if (! in_array($type, [self::TYPE_TABLE, self::TYPE_JSON, self::TYPE_LIST, self::TYPE_OVERVIEW, self::TYPE_AURA], true)) {
            throw new InvalidArgumentException(sprintf(
                'The source spec %s declares an unknown type "%s". Known: table, json, list, overview.',
                $origin,
                $type,
            ));
        }

        $overview = $raw['overview'] ?? [];

        if (! is_array($overview)) {
            throw new InvalidArgumentException(sprintf(
                'The overview section of %s must be a mapping.',
                $origin,
            ));
        }

        $headings = [];

        foreach ((array) ($overview['headings'] ?? []) as $field => $labels) {
            $headings[(string) $field] = array_values(array_map(
                static fn (mixed $l): string => mb_strtolower(trim((string) $l)),
                (array) $labels,
            ));
        }

        $spec = new self(
            name: (string) $need($raw, 'name', $origin),
            label: (string) ($raw['label'] ?? $raw['name']),
            /*
             * A single-page source needs no index: Schempp-Hirth lists every
             * type's notes on one page, so demanding an index URL and a link
             * pattern would force two meaningless entries into its spec.
             */
            /*
             * An overview spec needs no index of its own when it carries the
             * sheets' addresses -- and demanding one would mean inventing a page
             * for DG, whose sheets are listed nowhere but in its sitemap.
             */
            indexUrl: match (true) {
                isset($page['url']) => (string) $page['url'],

                /*
                 * An overview spec that carries the sheets' addresses needs no
                 * index of its own -- demanding one would mean inventing a page
                 * for DG, whose sheets are listed nowhere but in its sitemap. One
                 * that finds its sheet on the type's page still needs it, and is
                 * still refused without it.
                 */
                $type === self::TYPE_OVERVIEW
                    && ! isset($index['url'])
                    && ! isset($page['overview_pattern']) => '',

                // An Aura source has no index page to walk -- it asks the portal
                // which libraries exist. Its endpoint lives under `aura`.
                $type === self::TYPE_AURA => (string) ($raw['aura']['url'] ?? ''),
                default => (string) $need($type === self::TYPE_JSON ? $endpoint : $index, 'url', $origin),
            },
            linkPattern: in_array($type, [self::TYPE_TABLE, self::TYPE_LIST], true) && ! isset($page['url'])
                ? (string) $need($index, 'link_pattern', $origin)
                : (string) ($index['link_pattern'] ?? ''),
            // A JSON spec has no table to describe, so these are only demanded
            // where they are actually used.
            // A list has rows but no table and no cells; JSON has neither. Each
            // is demanded only where it is actually used.
            tablePattern: $type === self::TYPE_TABLE
                ? (string) $need($page, 'table_pattern', $origin)
                : (string) ($page['table_pattern'] ?? ''),
            /*
             * Demanded only where they are used, but READ wherever they stand.
             * An overview spec keeps its manufacturer's table -- that is the
             * document library, where the PDF behind a line lives -- and
             * discarding it here left parseTypePage() with an empty pattern.
             */
            rowPattern: in_array($type, [self::TYPE_TABLE, self::TYPE_LIST], true)
                ? (string) $need($page, 'row_pattern', $origin)
                : (string) ($page['row_pattern'] ?? ''),
            cellPattern: $type === self::TYPE_TABLE
                ? (string) $need($page, 'cell_pattern', $origin)
                : (string) ($page['cell_pattern'] ?? ''),
            // Cell indices for a table, dotted paths for JSON -- so no intval.
            columns: $columns,
            type: $type,
            authProfile: isset($raw['auth']) ? (string) $raw['auth'] : null,
            itemsPath: isset($endpoint['items']) ? (string) $endpoint['items'] : null,
            endpointQuery: array_map('strval', (array) ($endpoint['query'] ?? [])),
            // Read from either section: JSON mode puts it on the endpoint, a
            // paged list on the page. Same thing -- how a type is named in the
            // request -- so it would be a second field for no reason.
            typeParameter: match (true) {
                isset($endpoint['type_parameter']) => (string) $endpoint['type_parameter'],
                isset($page['type_parameter']) => (string) $page['type_parameter'],
                default => null,
            },
            pageUrl: isset($page['url']) ? (string) $page['url'] : null,
            pageParameter: isset($paging['parameter']) ? (string) $paging['parameter'] : null,
            maxPages: (int) ($paging['max_pages'] ?? 200),

            // Two passes catch a list that shifts by a page; a list still moving
            // after four is not something to paper over with a fifth.
            maxPasses: max(1, (int) ($paging['max_passes'] ?? 4)),
            pageDelayMs: max(0, (int) ($paging['delay_ms'] ?? 300)),
            inventoryUrl: isset($inventory['url']) ? (string) $inventory['url'] : null,
            inventoryPattern: isset($inventory['entry_pattern'])
                ? (string) $inventory['entry_pattern']
                : null,
            itemLinkPattern: isset($inventory['item_pattern'])
                ? (string) $inventory['item_pattern']
                : null,
            typeIndexUrl: isset($types['url']) ? (string) $types['url'] : null,
            typeIndexPattern: isset($types['entry_pattern']) ? (string) $types['entry_pattern'] : null,
            aura: (array) ($raw['aura'] ?? []),
            loginUrl: isset($login['url']) ? (string) $login['url'] : null,
            loginFormPattern: (string) ($login['form_pattern']
                ?? '#<form[^>]*action="([^"]*)"[^>]*>(.*?)</form>#is'),
            loginUserField: (string) ($login['user_field'] ?? 'user'),
            loginPasswordField: (string) ($login['password_field'] ?? 'pass'),
            loginExtra: array_map('strval', (array) ($login['extra'] ?? [])),
            loginSuccessPattern: isset($login['success_pattern'])
                ? (string) $login['success_pattern']
                : null,
            loginOptional: (bool) ($login['optional'] ?? false),
            caBundle: isset($tls['ca_bundle']) ? (string) $tls['ca_bundle'] : null,
            /*
             * An unknown kind is REFUSED, not quietly replaced by the default.
             *
             * ─────────────────────────────────────────────────────────────────
             * This used to fall back silently, and that let a plain typo through
             * unnoticed: five engine sources and one propeller source were
             * written as `engine_model` / `component_model`, neither of which
             * exists, so every one of them was filed as an AIRCRAFT model. The
             * import ran, the counts were right, the specs looked correct -- and
             * a Rotax bulletin sat in the fleet as though it belonged to an
             * airframe.
             *
             * A fallback is only safe where the wrong value is visible. Here it
             * is not, so the file is rejected by name at load, exactly like an
             * unknown `type` or a regex that does not compile.
             * ─────────────────────────────────────────────────────────────────
             */
            defaultKind: self::kind(
                DirectiveKind::class,
                $raw['default_kind'] ?? 'tm',
                'default_kind',
                $origin,
            ),
            subjectKind: self::kind(
                SubjectKind::class,
                $raw['subject_kind'] ?? 'aircraft_model',
                'subject_kind',
                $origin,
            ),
            issuer: isset($raw['issuer']) ? (string) $raw['issuer'] : null,
            modelFilter: isset($index['model_filter']) ? (string) $index['model_filter'] : null,
            overviewPattern: isset($page['overview_pattern']) ? (string) $page['overview_pattern'] : null,
            documentPattern: isset($page['document_pattern']) ? (string) $page['document_pattern'] : null,
            minCells: (int) ($page['min_cells'] ?? 2),
            optionalPhrases: array_values(array_map(
                fn (mixed $p): string => mb_strtolower(trim((string) $p)),
                (array) ($raw['bindingness']['optional_phrases'] ?? []),
            )),
            recommendedPhrases: array_values(array_map(
                fn (mixed $p): string => mb_strtolower(trim((string) $p)),
                (array) ($raw['bindingness']['recommended_phrases'] ?? []),
            )),
            mandatoryOverride: isset($raw['bindingness']['mandatory_override'])
                ? (string) $raw['bindingness']['mandatory_override']
                : null,
            authorityKindPattern: isset($raw['authority_kind_pattern'])
                ? (string) $raw['authority_kind_pattern']
                : null,
            overviewNumberPattern: isset($overview['number_pattern'])
                ? (string) $overview['number_pattern']
                : null,
            overviewHeadings: $headings,
            overviewBilingual: (bool) ($overview['bilingual'] ?? false),
            overviewBlankSeparates: (bool) ($overview['blank_separates_rows'] ?? false),
            overviewNumbersCentred: (bool) ($overview['numbers_centred_in_row'] ?? false),
            overviewHeadingsCentred: (bool) ($overview['headings_centred'] ?? false),
            overviewTitleStrip: isset($overview['title_strip']) ? (string) $overview['title_strip'] : null,
            overviewColumnsPerPage: (bool) ($overview['columns_per_page'] ?? false),
            overviewTextMode: isset($overview['text_mode']) ? (string) $overview['text_mode'] : null,
            overviewDateOrder: isset($overview['date_order'])
                ? strtolower((string) $overview['date_order'])
                : null,
            overviewTitlePrefix: isset($overview['title_prefix'])
                ? (string) $overview['title_prefix']
                : null,
            overviewRowsTile: (bool) ($overview['rows_tile_around_numbers'] ?? false),
            allTables: (bool) ($page['all_tables'] ?? false),
            dateOrder: isset($raw['date_order'])
                ? strtolower((string) $raw['date_order'])
                : null,
            secondaryList: (bool) ($raw['secondary_list'] ?? false),
            overviewEndsAt: isset($overview['ends_at']) ? (string) $overview['ends_at'] : null,
            overviewIgnore: isset($overview['ignore']) ? (string) $overview['ignore'] : null,
            overviewDocuments: array_map('strval', (array) ($overview['documents'] ?? [])),
            overviewUrl: isset($overview['url']) ? (string) $overview['url'] : null,
            overviewDocumentPattern: isset($overview['document_pattern'])
                ? (string) $overview['document_pattern']
                : null,
            endpointMethod: strtoupper((string) ($endpoint['method'] ?? 'GET')),
            endpointBody: array_map('strval', (array) ($endpoint['body'] ?? [])),
            documentUrlTemplate: isset($raw['document_url']) ? (string) $raw['document_url'] : null,
            numberStrip: isset($raw['number_strip']) ? (string) $raw['number_strip'] : null,
            bindingnessSource: isset($raw['bindingness']['source'])
                ? (string) $raw['bindingness']['source']
                : null,
            prefixKindInNumber: (bool) ($raw['number_prefix'] ?? true),
            cellStrip: isset($page['cell_strip']) ? (string) $page['cell_strip'] : null,
            ignoreNumber: isset($page['ignore']) ? (string) $page['ignore'] : null,
            sectionPattern: isset($page['section_pattern']) ? (string) $page['section_pattern'] : null,
            authorityNumberPattern: isset($raw['authority_number_pattern'])
                ? (string) $raw['authority_number_pattern']
                : null,
            rowsHtmlPath: isset($endpoint['rows_html']) ? (string) $endpoint['rows_html'] : null,
            endpointTerms: array_map('strval', (array) ($endpoint['terms'] ?? [])),
        );

        $spec->assertPatternsCompile($origin);
        $spec->assertColumns($origin);

        return $spec;
    }

    /**
     * One enum value out of a spec, or a refusal naming what was allowed.
     *
     * @template T of DirectiveKind|SubjectKind
     *
     * @param  class-string<T>  $enum
     * @return T
     */
    private static function kind(string $enum, mixed $value, string $key, string $origin): DirectiveKind|SubjectKind
    {
        $case = $enum::tryFrom((string) $value);

        if ($case === null) {
            throw new InvalidArgumentException(sprintf(
                'The source spec %s declares an unknown "%s" of "%s". Known: %s.',
                $origin,
                $key,
                (string) $value,
                implode(', ', array_column($enum::cases(), 'value')),
            ));
        }

        return $case;
    }

    /**
     * Every pattern must actually compile.
     *
     * Checked at load rather than at first use, because a broken regex in a
     * manufacturer file would otherwise surface as "this import found nothing" --
     * which looks exactly like a manufacturer who published nothing new.
     */
    private function assertPatternsCompile(string $origin): void
    {
        $patterns = array_filter([
            'index.link_pattern' => $this->linkPattern !== '' ? $this->linkPattern : null,
            'index.model_filter' => $this->modelFilter,
            'page.table_pattern' => $this->tablePattern !== '' ? $this->tablePattern : null,
            'page.row_pattern' => $this->rowPattern !== '' ? $this->rowPattern : null,
            'page.cell_pattern' => $this->cellPattern !== '' ? $this->cellPattern : null,
            'page.overview_pattern' => $this->overviewPattern,
            'page.document_pattern' => $this->documentPattern,
            'inventory.entry_pattern' => $this->inventoryPattern,
            'inventory.item_pattern' => $this->itemLinkPattern,
            'types.entry_pattern' => $this->typeIndexPattern,
            'login.form_pattern' => $this->loginUrl !== null ? $this->loginFormPattern : null,

            // In a list spec the column values ARE patterns, so a typo in one has
            // to be caught here like any other.
            ...($this->locatesFieldsByPattern()
                ? array_combine(
                    array_map(static fn (string $k): string => 'columns.'.$k, array_keys($this->columns)),
                    array_map('strval', array_values($this->columns)),
                )
                : []),
            'login.success_pattern' => $this->loginSuccessPattern,
            'bindingness.mandatory_override' => $this->mandatoryOverride,
            'authority_kind_pattern' => $this->authorityKindPattern,
            'overview.number_pattern' => $this->overviewNumberPattern,
            'overview.ends_at' => $this->overviewEndsAt,
            'overview.ignore' => $this->overviewIgnore,
            'overview.document_pattern' => $this->overviewDocumentPattern,
            'overview.title_strip' => $this->overviewTitleStrip,
            'number_strip' => $this->numberStrip,
            'page.cell_strip' => $this->cellStrip,
            'page.ignore' => $this->ignoreNumber,
            'page.section_pattern' => $this->sectionPattern,
            'authority_number_pattern' => $this->authorityNumberPattern,
        ]);

        foreach ($patterns as $key => $pattern) {
            // Warnings suppressed and the return value checked instead: preg_match
            // emits a warning AND returns false on a bad pattern, and only the
            // second is reliable to act on.
            if (@preg_match($pattern, '') === false) {
                throw new InvalidArgumentException(sprintf(
                    'The pattern "%s" in %s is not a valid regular expression.',
                    $key,
                    $origin,
                ));
            }
        }
    }

    private function assertColumns(string $origin): void
    {
        /*
         * An overview spec describes a printed table, not an HTML one: its
         * columns are found by their headings in the document itself, so what it
         * must declare are the HEADINGS and what a number looks like. Checked at
         * load for the same reason as the patterns -- a missing heading would
         * otherwise surface as "this manufacturer published nothing".
         */
        if ($this->isOverview()) {
            if ($this->overviewNumberPattern === null) {
                throw new InvalidArgumentException(sprintf(
                    'The source spec %s reads an overview sheet, so it must declare '
                    .'overview.number_pattern -- without it no line can be told from the '
                    .'page around it.',
                    $origin,
                ));
            }

            foreach (['number', 'title'] as $heading) {
                if (($this->overviewHeadings[$heading] ?? []) === []) {
                    throw new InvalidArgumentException(sprintf(
                        'The source spec %s must name the "%s" heading of the overview '
                        .'sheet under overview.headings.',
                        $origin,
                        $heading,
                    ));
                }
            }

            if ($this->overviewDocuments === [] && $this->overviewUrl === null
                && $this->overviewPattern === null) {
                throw new InvalidArgumentException(sprintf(
                    'The source spec %s reads an overview sheet but says nowhere where to '
                    .'find one: give overview.documents, overview.url, or '
                    .'page.overview_pattern for a sheet linked from the type\'s own page.',
                    $origin,
                ));
            }

            return;
        }

        foreach (['number', 'title'] as $required) {
            if (! isset($this->columns[$required])) {
                throw new InvalidArgumentException(sprintf(
                    'The source spec %s must map a "%s" column.',
                    $origin,
                    $required,
                ));
            }
        }
    }

    public function column(string $field): int|string|null
    {
        return $this->columns[$field] ?? null;
    }

    /**
     * The number as it should be stored -- without what is not part of it.
     *
     * Empty after stripping is refused by returning the original: a spec whose
     * pattern is too greedy would otherwise erase every number silently, and a
     * directive without a number cannot be matched to its successor at all.
     */
    public function cleanNumber(string $number): string
    {
        if ($this->numberStrip === null) {
            return trim($number);
        }

        $stripped = trim((string) preg_replace($this->numberStrip, '', $number));

        return $stripped !== '' ? $stripped : trim($number);
    }

    /**
     * The search term for one model, from the spec's own list.
     *
     * Matched the way overviewDocumentFor() matches a sheet: exact first, then
     * the longest listed name contained in what was asked for, so "PA-18" cannot
     * answer for "PA-18A" when the spec lists both.
     */
    public function termFor(string $model): ?string
    {
        $normalise = static fn (string $s): string => strtolower(preg_replace('/[^a-z0-9]/i', '', $s) ?? $s);
        $wanted = $normalise($model);

        if ($wanted === '' || $this->endpointTerms === []) {
            return null;
        }

        $best = null;
        $length = 0;

        foreach ($this->endpointTerms as $name => $term) {
            $candidate = $normalise((string) $name);

            if ($candidate === $wanted) {
                return $term;
            }

            if ($candidate !== '' && str_contains($wanted, $candidate) && strlen($candidate) > $length) {
                $best = $term;
                $length = strlen($candidate);
            }
        }

        return $best;
    }

    /** A document reference as an address, where the spec says how. */
    public function documentUrlFor(string $document): string
    {
        if ($document === '' || $this->documentUrlTemplate === null) {
            return $document;
        }

        return str_replace('{document}', rawurlencode($document), $this->documentUrlTemplate);
    }

    public function isJson(): bool
    {
        return $this->type === self::TYPE_JSON;
    }

    /** Whether the endpoint is asked with a form POST rather than a GET. */
    public function postsToEndpoint(): bool
    {
        return $this->endpointMethod === 'POST';
    }

    /**
     * Whether one request brings the whole list, with no type to name.
     *
     * A JSON source normally asks per type, and a missing type id is refused
     * because an empty parameter would come back as an empty list -- "nothing
     * published for your aircraft". Where a spec names no type parameter at all,
     * that risk does not exist: there is one request, and it carries everything.
     */
    public function fetchesEveryTypeAtOnce(): bool
    {
        return $this->isJson() && $this->typeParameter === null;
    }

    public function isList(): bool
    {
        return $this->type === self::TYPE_LIST;
    }

    public function isOverview(): bool
    {
        return $this->type === self::TYPE_OVERVIEW;
    }

    /**
     * A reader for this manufacturer's sheets.
     *
     * Built here rather than in the driver so the six things a sheet needs to
     * know travel together with the file that declares them.
     */
    public function overviewSheet(): OverviewSheet
    {
        return new OverviewSheet(
            (string) $this->overviewNumberPattern,
            $this->overviewHeadings,
            $this->overviewBilingual,
            $this->overviewEndsAt,
            $this->overviewIgnore,
            $this->overviewBlankSeparates,
            $this->overviewNumbersCentred,
            $this->overviewHeadingsCentred,
            $this->overviewTitleStrip,
            $this->overviewColumnsPerPage,
            $this->overviewDateOrder,
            $this->overviewTitlePrefix,
            $this->overviewRowsTile,
        );
    }

    /**
     * The sheet's address for one type, from the spec's own list.
     *
     * Matched the way a person would: "DG-300 Club Elan" is the DG-300 sheet.
     * Exact first, then the longest name contained in what was asked for, so
     * "LS10" cannot answer for "LS10-st" when the spec lists both.
     */
    public function overviewDocumentFor(string $model): ?string
    {
        $normalise = static fn (string $s): string => strtolower(preg_replace('/[^a-z0-9]/i', '', $s) ?? $s);
        $wanted = $normalise($model);

        if ($wanted === '' || $this->overviewDocuments === []) {
            return null;
        }

        $best = null;
        $length = 0;

        foreach ($this->overviewDocuments as $name => $url) {
            $candidate = $normalise((string) $name);

            if ($candidate === $wanted) {
                return $url;
            }

            if ($candidate !== '' && str_contains($wanted, $candidate) && strlen($candidate) > $length) {
                $best = $url;
                $length = strlen($candidate);
            }
        }

        return $best;
    }

    /**
     * Whether a field is located by pattern rather than by position.
     *
     * True for a list, where the number and the subject share one string and
     * nothing has a column to sit in.
     */
    public function locatesFieldsByPattern(): bool
    {
        return $this->type === self::TYPE_LIST;
    }

    /** Whether everything lives on one page rather than one page per type. */
    public function isSinglePage(): bool
    {
        if ($this->pageUrl !== null) {
            return true;
        }

        /*
         * ONE SHEET FOR EVERYTHING is a single page too, and missing that was a
         * real fault rather than a tidiness point.
         *
         * A manufacturer's general sheet has one fixed address and no per-type
         * list. Asked per fleet type -- which is what a source that is not a
         * single page gets -- overviewFor() falls back to that one address for
         * EVERY type, and every row comes back stamped with the type that was
         * asked about. A club flying an ASK 21 and a DG-300 imported DG's
         * general notes twice: once as DG-300 notes, once as ASK 21 notes.
         *
         * They stayed out of sight only because a general line is kept off the
         * outstanding list until it is carried out. The moment one such sheet is
         * NOT general -- SZD's, where eleven of thirteen lines say Mandatory --
         * the wrong stamp becomes an open point against a foreign aircraft.
         */
        return $this->overviewUrl !== null
            && $this->overviewDocuments === []
            && $this->overviewPattern === null;
    }

    /** Whether the spec reads an urgency column at all. */
    public function mapsCompliance(): bool
    {
        if ($this->isOverview()) {
            return ($this->overviewHeadings['compliance'] ?? []) !== [];
        }

        return ($this->columns['compliance'] ?? null) !== null;
    }

    public function isAura(): bool
    {
        return $this->type === self::TYPE_AURA;
    }

    /** Whether the list arrives a page at a time. */
    public function isPaged(): bool
    {
        return $this->pageParameter !== null;
    }

    /** Whether the manufacturer publishes a list of everything it has. */
    public function hasInventory(): bool
    {
        return $this->inventoryUrl !== null
            && $this->inventoryPattern !== null
            && $this->itemLinkPattern !== null;
    }

    /** Whether a model can be checked against the manufacturer's own index. */
    public function hasTypeIndex(): bool
    {
        return $this->typeIndexUrl !== null && $this->typeIndexPattern !== null;
    }

    /**
     * A URL with the page number, and the type, filled in.
     */
    public function pagedUrl(?string $typeSlug, int $page): string
    {
        /*
         * A page number in the PATH, where the site puts it there.
         *
         * EASA's tool pages with "/search/page-2/?…" and keeps the filter --
         * appending the number as a query parameter instead simply returns page
         * one, which reads as "there is nothing more" and silently truncates the
         * list at twenty of fifty-seven.
         */
        if (str_contains((string) $this->pageUrl, '{page}')) {
            $url = str_replace('{page}', (string) $page, (string) $this->pageUrl);

            if ($this->typeParameter !== null && $typeSlug !== null && $typeSlug !== '') {
                $url .= (str_contains($url, '?') ? '&' : '?')
                    .http_build_query([$this->typeParameter => $typeSlug]);
            }

            return $url;
        }

        $query = [];

        if ($this->typeParameter !== null && $typeSlug !== null && $typeSlug !== '') {
            $query[$this->typeParameter] = $typeSlug;
        }

        if ($this->pageParameter !== null) {
            $query[$this->pageParameter] = (string) $page;
        }

        $url = (string) $this->pageUrl;

        return $query === []
            ? $url
            : $url.(str_contains($url, '?') ? '&' : '?').http_build_query($query);
    }

    public function needsLogin(): bool
    {
        return $this->loginUrl !== null;
    }

    /**
     * The credentials this spec asks for, from the environment.
     *
     * Read here rather than stored on the spec so a dumped spec -- in a log, in a
     * bug report, in a shared manufacturer file -- can never carry them.
     *
     * @return array{0: ?string, 1: ?string} user, password
     */
    public function credentials(): array
    {
        /*
         * Resolved through SourceCredentials, which knows the two places a login
         * can live: the environment (authoritative, read-only) and the database
         * (what a club typed into the panel, encrypted).
         *
         * It used to read env() directly here, and that was broken in exactly
         * the installations it mattered for: env() returns null once
         * `php artisan config:cache` has run, which deploy/update.sh does on
         * every update. A gated source lost its credentials after an update and
         * reported them missing while they sat in the .env.
         */
        return app(SourceCredentials::class)->for($this->authProfile);
    }

    public function needsCredentials(): bool
    {
        return $this->authProfile !== null;
    }

    /**
     * How binding a row is, from its own words.
     *
     * The same two rules the hand-written Schleicher adapter applied, now driven
     * by the spec: an authority number wins outright, and otherwise only a listed
     * phrase makes it optional. Everything else -- including an empty column -- is
     * binding, because being wrong towards binding leaves a line on the list while
     * being wrong towards optional lets it be waived with a sentence.
     */
    public function bindingnessFor(?string $authorityNumber, string $compliance): Bindingness
    {
        if ($authorityNumber !== null && trim($authorityNumber) !== '') {
            return Bindingness::Mandatory;
        }

        $text = mb_strtolower(trim($compliance));

        if ($text === '' || $text === '-' || $text === 'keine') {
            return Bindingness::Mandatory;
        }

        /*
         * Checked BEFORE the optional phrases, because the two overlap by
         * design: a manufacturer who writes "empfohlen, wahlweise" means the
         * recommendation, and the more specific reading has to win. Reversed,
         * every recommendation with an optional word in it would flatten to
         * plain optional -- which is exactly the distinction the requirement was for.
         */
        foreach ($this->recommendedPhrases as $phrase) {
            if ($phrase !== '' && str_contains($text, $phrase)) {
                /*
                 * The same override the optional branch has, and for the same
                 * reason. SZD writes "Recommended ... Considered as mandatory
                 * for the gliders flown over 6.000 FH" in ONE cell: the word
                 * appears, and the sentence around it says the opposite. A
                 * wording that names a hard obligation is not a recommendation.
                 */
                if ($this->mandatoryOverride !== null
                    && preg_match($this->mandatoryOverride, $text) === 1) {
                    return Bindingness::Mandatory;
                }

                return Bindingness::Recommended;
            }
        }

        foreach ($this->optionalPhrases as $phrase) {
            if ($phrase !== '' && str_contains($text, $phrase)) {
                // A wording that is optional AND names a hard moment is not
                // optional -- "A) wahlweise ... B) vor dem nächsten Flug" is real.
                if ($this->mandatoryOverride !== null
                    && preg_match($this->mandatoryOverride, $text) === 1) {
                    return Bindingness::Mandatory;
                }

                return Bindingness::Optional;
            }
        }

        return Bindingness::Mandatory;
    }
}
