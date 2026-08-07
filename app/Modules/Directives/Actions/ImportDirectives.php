<?php

declare(strict_types=1);

namespace App\Modules\Directives\Actions;

use App\Core\Access\Authority;
use App\Models\User;
use App\Modules\Directives\Models\Directive;
use App\Modules\Directives\Permissions;
use App\Modules\Directives\Sources\Configured\ConfiguredSource;
use App\Modules\Directives\Sources\DirectiveRow;
use App\Modules\Directives\Sources\SecondaryList;
use App\Modules\Directives\Sources\SourceRegistry;
use App\Modules\Fleet\Types\TypeLookup;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Bringing a list in, from whichever source.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * IT ONLY EVER ADDS OR UPDATES, NEVER REMOVES.
 *
 * Vorgabe: "Die Übersichtsliste ändert sich herstellerseitig nicht oder wird
 * länger." So a row that has vanished from the manufacturer's file is NOT deleted
 * here -- it might be a shortened export, a changed URL, a parser that broke. A
 * list that silently loses lines is worse than one that grows, because nobody
 * notices the loss, and the assessments hanging off those lines would go with
 * them.
 *
 * Updating is scoped to the SAME source. A manufacturer refresh must not touch a
 * line somebody typed by hand, even one with the same number -- which is exactly
 * why ManualSource exists as a source rather than as the absence of one.
 *
 * Assessments are never touched. That is the whole reason directives and
 * applications are two tables.
 * ─────────────────────────────────────────────────────────────────────────────
 */
final readonly class ImportDirectives
{
    public function __construct(
        private Authority $authority,
        private SourceRegistry $sources,

        /*
         * The fleet answers "which of our types is this?" itself.
         *
         * Asked through its own seam rather than by querying its model: the
         * directives module may depend on the fleet -- it declares that -- but
         * how a Kennblatt is written, that one type can carry several, and that
         * an unknown number is a normal outcome is the fleet's knowledge, not
         * this action's. See TypeLookup.
         */
        private TypeLookup $types,
    ) {}

    /**
     * @param  array<string, mixed>  $options
     * @return array{created: int, updated: int, unchanged: int, rows: list<string>}
     */
    public function fromSource(string $sourceName, User $user, array $options = []): array
    {
        if (! $this->authority->permits($user, Permissions::DIRECTIVES_MANAGE)) {
            throw new RuntimeException(sprintf(
                'Importing a list requires the "%s" permission.',
                Permissions::DIRECTIVES_MANAGE,
            ));
        }

        $source = $this->sources->get($sourceName);
        $rows = $source->fetch($options);

        /*
         * A secondary list yields to the source that owns the document -- see
         * SourceSpec::$secondaryList. Read from the spec rather than passed in,
         * so a club that adds such a sheet declares it once and nowhere else.
         */
        $secondary = $source instanceof SecondaryList
            || ($source instanceof ConfiguredSource && $source->spec()->secondaryList);

        return $this->store($sourceName, $rows, $secondary);
    }

    /**
     * @param  list<DirectiveRow>  $rows
     * @param  bool  $secondary  whether rows another source already owns are left to it
     * @return array{created: int, updated: int, unchanged: int, rows: list<string>}
     */
    public function store(string $sourceName, array $rows, bool $secondary = false): array
    {
        $created = 0;
        $updated = 0;
        $unchanged = 0;
        $touched = [];
        $seen = [];
        $collisions = [];
        $deferred = [];

        DB::transaction(function () use ($rows, $sourceName, $secondary, &$created, &$updated, &$unchanged, &$touched, &$seen, &$collisions, &$deferred): void {
            foreach ($rows as $row) {
                /*
                 * The same number twice in ONE fetch is not an update.
                 *
                 * ─────────────────────────────────────────────────────────────
                 * A directive is identified by source and number, and normally
                 * that holds. Not always: Schleicher's Rotax page lists
                 * TM SB-2ST-000 twice -- once for the 275 series, once for the
                 * 505 -- with different dates. Both are real.
                 *
                 * Left alone, the second row would land in the update branch
                 * below and overwrite the first. Thirteen rows read, twelve
                 * stored, and the run reports "1 neu, 1 aktualisiert" -- which
                 * is true and completely hides that a directive vanished.
                 *
                 * So the first one is kept and the rest are REPORTED. Nothing is
                 * merged, and no number is invented to tell them apart -- there
                 * is no honest way to derive one from the page.
                 * ─────────────────────────────────────────────────────────────
                 */
                $key = trim($row->number);

                if (isset($seen[$key])) {
                    $collisions[] = $key;

                    continue;
                }

                $seen[$key] = true;

                $existing = Directive::withTrashed()
                    ->where('source', $sourceName)
                    ->where('number', trim($row->number))
                    ->first();

                /*
                 * Owned elsewhere, so left there.
                 *
                 * Only for a secondary list, and only where THIS source has not
                 * filed the row before: an entry already stored here keeps its
                 * assessments and goes on being updated, because taking it away
                 * later would delete somebody's work.
                 */
                if ($secondary && $existing === null
                    && $this->heldElsewhere($sourceName, $row)) {
                    $deferred[] = trim($row->number);

                    continue;
                }

                $attributes = ['source' => $sourceName] + $row->toAttributes();

                /*
                 * Link to the catalogued type where one exists, so applicability
                 * becomes an id comparison instead of a substring guess. Exact
                 * designation only -- a loose match here would attach a
                 * manufacturer's row to the wrong variant, and the name
                 * comparison already handles those correctly at read time.
                 */
                if (filled($row->subjectModel)) {
                    $attributes['aircraft_type_id'] = $this->types->byDesignation($row->subjectModel);
                }

                /*
                 * A row that names no model but a KENNBLATT still belongs to an
                 * aircraft.
                 *
                 * The gazette is the case: it prints the holder and the type
                 * certificate ("EASA.A.189") and never the model, so nothing in
                 * the row can be matched by name. The fleet carries the same
                 * number -- Vorgabe: "die kennblattnummer ist im kfz typ im
                 * flottenmodul hinterlegt." -- and answers for it itself.
                 */
                if (blank($attributes['aircraft_type_id'] ?? null) && filled($row->subjectDesignation)) {
                    $attributes['aircraft_type_id'] = $this->types->byCertificate($row->subjectDesignation);
                }

                if ($existing === null) {
                    Directive::create($attributes);
                    $created++;
                    $touched[] = $row->number;

                    continue;
                }

                /*
                 * A soft-deleted line reappearing in the source is restored
                 * rather than duplicated -- the unique index would refuse a
                 * second row anyway, and a restored line keeps whatever
                 * assessments were made against it.
                 */
                if ($existing->trashed()) {
                    $existing->restore();
                }

                $existing->fill($attributes);

                if ($existing->isDirty()) {
                    $existing->save();
                    $updated++;
                    $touched[] = $row->number;
                } else {
                    $unchanged++;
                }
            }
        });

        return [
            'created' => $created,
            'updated' => $updated,
            'unchanged' => $unchanged,
            'rows' => $touched,

            // Numbers the source used more than once in this fetch. Only the
            // first of each was stored; the rest need a person.
            'collisions' => array_values(array_unique($collisions)),

            // Numbers a secondary list left to the source that owns them. Named
            // rather than silently dropped: "nothing was imported" and "it is
            // already there under its own manufacturer" look identical in a
            // count, and only one of them is fine.
            'deferred' => array_values(array_unique($deferred)),
        ];
    }

    /**
     * Whether the document this row points at is already held by another source.
     *
     * ─────────────────────────────────────────────────────────────────────────
     * TWO NUMBERS FOR ONE DOCUMENT, which is why matching on the row's own
     * number is not enough. Aquila reprints Rotax's bulletins under Rotax's
     * numbers, so there the two agree. The gazette does not: EASA AD 2026-0132
     * is published in Germany as D-2026-152, and no comparison of those two
     * strings will ever say they are the same paper.
     *
     * What ties them is the AUTHORITY's number, which the gazette prints beside
     * its own and which easa-ad carries as its number. So a secondary source
     * yields where either matches -- and the national number is still filed
     * where nothing else holds the document, because for an Annex-I type the
     * gazette is the only source there is.
     * ─────────────────────────────────────────────────────────────────────────
     */
    private function heldElsewhere(string $sourceName, DirectiveRow $row): bool
    {
        $candidates = [trim($row->number)];

        if (filled($row->externalReference)) {
            /*
             * "EASA AD 2026-0132" is how the gazette writes it; easa-ad files it
             * as "2026-0132". The bare number is therefore tried as well --
             * without it the two would never meet.
             */
            $candidates[] = trim($row->externalReference);

            if (preg_match('/(\d{4}-\d{3,4}[A-Z0-9-]*)/', $row->externalReference, $m) === 1) {
                $candidates[] = $m[1];
            }
        }

        return Directive::withTrashed()
            ->whereIn('number', array_values(array_unique(array_filter($candidates))))
            ->where('source', '!=', $sourceName)
            ->exists();
    }

    /**
     * A newer line replaces an older one.
     *
     * Recorded, not deleted: the record has to show that the old directive was
     * dealt with and by what. The old line's assessments stay readable too --
     * "complied with LTA 2019-05, superseded by LTA 2024-11" is the history an
     * inspector asks about.
     */
    public function supersede(Directive $old, Directive $new, User $user): Directive
    {
        if (! $this->authority->permits($user, Permissions::DIRECTIVES_MANAGE)) {
            throw new RuntimeException(sprintf(
                'Marking a directive superseded requires the "%s" permission.',
                Permissions::DIRECTIVES_MANAGE,
            ));
        }

        if ($old->is($new)) {
            throw new RuntimeException('A directive cannot supersede itself.');
        }

        if ($new->superseded_by_id === $old->id) {
            throw new RuntimeException(
                'Those two already supersede each other the other way round -- one of them '
                .'has to be the current one.'
            );
        }

        $old->update(['superseded_by_id' => $new->id]);

        return $old->fresh();
    }
}
