<?php

declare(strict_types=1);

namespace App\Modules\Directives\Models;

use App\Modules\Directives\Enums\Bindingness;
use App\Modules\Directives\Enums\DirectiveKind;
use App\Modules\Directives\Enums\SubjectKind;
use App\Modules\Fleet\Models\Aircraft;
use App\Modules\Fleet\Models\AircraftType;
use App\Modules\Fleet\Models\ComponentType;
use App\Modules\Fleet\Models\Installation;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * One line of a manufacturer's or authority's list.
 *
 * the constraint governs this table: "Die Übersichtsliste ändert sich
 * herstellerseitig nicht oder wird länger." So a directive is never edited away
 * and never really deleted -- a newer one supersedes it, and both stay readable.
 * Soft deletes exist for the one honest case: a line typed by mistake.
 */
final class Directive extends Model
{
    use LogsActivity, SoftDeletes;

    /**
     * Recorded in place of a qualification type when somebody acts as the
     * aircraft's holder rather than under a licence.
     *
     * Stored so the record says which capacity the decision was taken in -- a
     * technical judgement and an operator's call read very differently two years
     * later.
     */
    public const CAPACITY_HOLDER = 'holder';

    /**
     * Mandatory unless somebody says otherwise.
     *
     * The safe side: treating a binding directive as optional would let it be
     * waived, while treating an optional one as binding only means it shows up
     * until somebody corrects the row. The DB default alone was not enough --
     * it applies on insert, so a freshly created model carried null and every
     * bindingness check threw.
     */
    protected $attributes = [
        'bindingness' => 'mandatory',
    ];

    protected $fillable = [
        'source',
        'external_reference',
        'number',
        'title',
        'summary',
        'kind',
        'bindingness',
        'issuer',
        'issued_at',
        'comply_before',
        'subject_kind',
        'subject_model',
        'aircraft_type_id',
        'component_type_id',
        'subject_designation',
        'subject_part_number',
        'serial_from',
        'serial_to',
        'is_recurring',
        'interval_months',
        'interval_counter',
        'interval_value',
        'superseded_by_id',
        'reference_url',
    ];

    protected function casts(): array
    {
        return [
            'kind' => DirectiveKind::class,
            'bindingness' => Bindingness::class,
            'subject_kind' => SubjectKind::class,
            'issued_at' => 'date',
            'comply_before' => 'date',
            'is_recurring' => 'boolean',
            'interval_value' => 'decimal:2',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['number', 'title', 'kind', 'bindingness', 'comply_before', 'superseded_by_id'])
            ->logOnlyDirty()
            ->useLogName('directives');
    }

    /**
     * The catalogued type this is about, if it has been linked.
     *
     * @return BelongsTo<AircraftType, $this>
     */
    public function aircraftType(): BelongsTo
    {
        return $this->belongsTo(AircraftType::class, 'aircraft_type_id');
    }

    /**
     * The catalogued component this is about, if it has been linked.
     *
     * @return BelongsTo<ComponentType, $this>
     */
    public function componentType(): BelongsTo
    {
        return $this->belongsTo(ComponentType::class, 'component_type_id');
    }

    /** @return HasMany<DirectiveApplication, $this> */
    public function applications(): HasMany
    {
        return $this->hasMany(DirectiveApplication::class);
    }

    /** @return BelongsTo<Directive, $this> */
    public function supersededBy(): BelongsTo
    {
        return $this->belongsTo(self::class, 'superseded_by_id');
    }

    public function isSuperseded(): bool
    {
        return $this->superseded_by_id !== null;
    }

    /**
     * Directives that still stand.
     *
     * @param  Builder<Directive>  $query
     */
    public function scopeCurrent(Builder $query): void
    {
        $query->whereNull('superseded_by_id');
    }

    /**
     * ─────────────────────────────────────────────────────────────────────────
     * WHETHER THIS DIRECTIVE TOUCHES AN AIRCRAFT.
     *
     * The judgement the whole module hangs on, and it deliberately answers
     * "maybe" by erring towards YES.
     *
     * A model directive is straightforward: same type, affected. A serial-based
     * one is not -- it depends on which parts are actually fitted, and the fleet
     * only knows the components somebody recorded. So an aircraft with no
     * recorded components matches a component directive rather than escaping it:
     * the tool must not decide that a line does not apply on the strength of
     * data that was never entered.
     *
     * The answer is only ever a PROPOSAL for the list. Somebody still assesses
     * each line and can mark it not applicable with a reason -- which is exactly
     * why erring towards yes is safe here and erring towards no would not be.
     * ─────────────────────────────────────────────────────────────────────────
     */
    public function mayApplyTo(Aircraft $aircraft): bool
    {
        if ($this->isSuperseded()) {
            return false;
        }

        if ($this->subject_kind === SubjectKind::AircraftModel) {
            return $this->matchesType($aircraft);
        }

        // Serial-based: does the aircraft carry something that looks like the
        // subject? A type restriction, if given, still has to hold.
        if (($this->subject_model !== null || $this->aircraft_type_id !== null)
            && ! $this->matchesType($aircraft)) {
            return false;
        }

        $fitted = $aircraft->installations()->get();

        if ($fitted->isEmpty()) {
            // Nothing recorded is not evidence of absence.
            return true;
        }

        return $fitted->contains(fn (Installation $i): bool => $this->matchesFitted($i));
    }

    /**
     * Whether a fitted part is the one this directive is about.
     *
     * Matched on designation or part number, then narrowed by the serial range.
     * Text comparison for serials on purpose: manufacturers write "0123", "A-45"
     * and "1000 and up", and treating those as numbers is how a range check
     * quietly stops working.
     */
    private function matchesFitted(Installation $installation): bool
    {
        /*
         * Exact where both sides are catalogued -- the same sharpening the
         * aircraft types brought, now for components. the Tost coupling is
         * the case: "Sicherheitskupplung Europa G 88" matched by substring would
         * also hit "Europa G 88 Mk II" if such a thing existed.
         */
        if ($this->component_type_id !== null && $installation->component_type_id !== null) {
            return (int) $this->component_type_id === (int) $installation->component_type_id
                && $this->serialInRange($installation->serial_number);
        }

        /*
         * part_name, not a relation. Installations carry the part's name as text
         * -- the loose cross-module reference (D4) -- so there is nothing to join
         * to. My first version eager-loaded a partType relation that does not
         * exist, which would have thrown on every component directive.
         */
        $name = (string) ($installation->part_name ?? '');
        $partNumber = $installation->part_number ?? '';

        $subjectHit = ($this->subject_designation !== null
                && $name !== ''
                && stripos($name, $this->subject_designation) !== false)
            || ($this->subject_part_number !== null
                && $partNumber !== ''
                && strcasecmp(trim($partNumber), trim($this->subject_part_number)) === 0);

        if (! $subjectHit) {
            return false;
        }

        return $this->serialInRange($installation->serial_number);
    }

    /**
     * Whether a serial falls in the directive's range.
     *
     * Unknown serial -> yes. Same reasoning as an aircraft with no recorded
     * components: a missing entry must not exempt anybody.
     */
    public function serialInRange(?string $serial): bool
    {
        if ($this->serial_from === null && $this->serial_to === null) {
            return true;
        }

        if ($serial === null || trim($serial) === '') {
            return true;
        }

        $s = trim($serial);

        /*
         * Natural comparison, case-insensitive -- and this is not a detail.
         * Vorgabe: "seriennummern können buchstaben und zeichen enthalten."
         *
         * Plain string comparison gets zero-padding wrong in the dangerous
         * direction: "99" > "0100" lexicographically, so a part numbered 99 would
         * fall INSIDE a range starting at 0100 and the directive would be reported
         * as applying to it -- or worse, the other way round for an upper bound.
         * strnatcasecmp compares "99" < "100" numerically where both sides are
         * numbers and still handles "A-45" < "A-99" as a person would read it.
         */
        if ($this->serial_from !== null && strnatcasecmp($s, trim($this->serial_from)) < 0) {
            return false;
        }

        if ($this->serial_to !== null && strnatcasecmp($s, trim($this->serial_to)) > 0) {
            return false;
        }

        return true;
    }

    /**
     * Whether this directive is about that aircraft's type.
     *
     * ─────────────────────────────────────────────────────────────────────────
     * EXACT WHERE BOTH SIDES ARE CATALOGUED, loose otherwise.
     *
     * The loose comparison was all there was before the type table existed, and
     * it has to stay: a manufacturer's list names a type that may not be
     * catalogued yet, and a row must be importable before somebody curates it.
     * But where both the directive and the aircraft point at the same type
     * record, the answer is a comparison of two ids and no longer a guess about
     * spelling.
     *
     * One deliberate asymmetry: a directive WITH a type and an aircraft WITHOUT
     * one falls back to the name rather than answering no. An uncatalogued
     * aircraft must not escape a directive -- same rule as everywhere else here.
     * ─────────────────────────────────────────────────────────────────────────
     */
    private function matchesType(Aircraft $aircraft): bool
    {
        if ($this->aircraft_type_id !== null && $aircraft->aircraft_type_id !== null) {
            return (int) $this->aircraft_type_id === (int) $aircraft->aircraft_type_id;
        }

        // A line that names no model at all still names a manufacturer.
        if ($this->subject_model === null && $this->fromAnotherManufacturer($aircraft)) {
            return false;
        }

        return $this->matchesModel($aircraft->model);
    }

    /**
     * Whether this line is demonstrably somebody else's manufacturer's.
     *
     * ─────────────────────────────────────────────────────────────────────────
     * A directive with no model applies to everything, which is right for a line
     * a club typed in and wrong for a manufacturer's range-wide bulletin: SZD's
     * general sheet carries thirteen, eleven of them mandatory, and against a
     * Schleicher they are thirteen open points that are not open.
     *
     * DEMONSTRABLY is the whole rule. Not recorded is not evidence of anything,
     * and free text is compared in both directions -- a club writes "SZD" where
     * the sheet says "Allstar PZL Glider". Only when both names are known and
     * neither contains the other does the line step aside; every other case
     * keeps the existing answer, which is yes.
     * ─────────────────────────────────────────────────────────────────────────
     */
    private function fromAnotherManufacturer(Aircraft $aircraft): bool
    {
        $issuer = mb_strtolower(trim((string) $this->issuer));
        $maker = mb_strtolower(trim(
            (string) ($aircraft->manufacturer ?? $aircraft->aircraftType?->manufacturer ?? '')
        ));

        if ($issuer === '' || $maker === '') {
            return false;
        }

        foreach (preg_split('/[^\p{L}\p{N}]+/u', $maker) ?: [] as $word) {
            // One shared word is enough. "Allstar PZL Glider" and "PZL Bielsko"
            // are the same maker written two ways, and so are "SZD" and
            // "SZD / Allstar".
            if (mb_strlen($word) >= 3 && str_contains($issuer, $word)) {
                return false;
            }
        }

        return ! str_contains($issuer, $maker) && ! str_contains($maker, $issuer);
    }

    private function matchesModel(?string $model): bool
    {
        if ($this->subject_model === null) {
            return true;
        }

        if ($model === null || trim($model) === '') {
            return true;
        }

        // Substring both ways: manufacturers write "ASK 21" where the fleet says
        // "ASK 21 B", and either can be the longer string.
        $a = mb_strtolower(trim($this->subject_model));
        $b = mb_strtolower(trim($model));

        return str_contains($b, $a) || str_contains($a, $b);
    }

    /**
     * Aircraft this directive may touch.
     *
     * @return Collection<int, Aircraft>
     */
    public function candidateAircraft(): Collection
    {
        return Aircraft::query()
            ->with('installations')
            ->get()
            ->filter(fn (Aircraft $a): bool => $this->mayApplyTo($a))
            ->values();
    }

    /**
     * Whether this line must be complied with.
     *
     * Read from the bindingness column, not from the kind: the same TM becomes
     * mandatory the day an authority adopts it, and its number does not change.
     */
    public function isMandatory(): bool
    {
        return $this->bindingness === Bindingness::Mandatory;
    }

    /**
     * Whether "not carried out" is an answer that may be given here.
     *
     * Only for optional lines. There is no declaration for skipping a mandatory
     * directive -- either it is done, or it does not apply, or the aircraft does
     * not fly.
     */
    public function permitsRefusal(): bool
    {
        return $this->bindingness->permitsRefusal();
    }

    public function isOverdue(?string $on = null): bool
    {
        return $this->comply_before !== null
            && $this->comply_before->toDateString() < ($on ?? now()->toDateString());
    }

    public function label(): string
    {
        return sprintf('%s %s', strtoupper($this->kind->value), $this->number);
    }
}
