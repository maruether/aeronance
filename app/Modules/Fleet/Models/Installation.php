<?php

declare(strict_types=1);

namespace App\Modules\Fleet\Models;

use App\Models\User;
use App\Modules\Fleet\Enums\CounterKind;
use App\Modules\Fleet\Enums\InstallationOrigin;
use App\Modules\Fleet\Enums\LimitKind;
use App\Modules\Fleet\Enums\UsageBasis;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * A part fitted to an aircraft.
 *
 * Which parts get one is the rule: everything that is not a standard part.
 * "Niemanden interessiert die Mutter oder Niete von Würth." What decides is the
 * paper -- if a Form 1 or a CoC came with it, that document now belongs to this
 * aircraft's life record and has to be readable there.
 *
 * From which follows something worth stating, because it shapes half this class:
 * NOT EVERY COMPONENT HAS A LIFE. "Ein Ölfilter geht z. B. automatisch mit der
 * Motorwartung und ein neuer kommt." An installation with no limits at all is
 * the ordinary case, not an unfinished one.
 */
final class Installation extends Model
{
    use SoftDeletes;

    public const DOCUMENT_NONE = 'none';

    protected $attributes = [
        'origin' => 'stock',
        'document_type' => self::DOCUMENT_NONE,
        'quantity' => 1,
        'is_present' => true,
        'is_minimum_equipment' => false,
    ];

    protected $fillable = [
        'aircraft_id',
        'origin',
        'transcribed_from',
        'transcribed_at',
        'transcribed_by',
        'transcribed_by_name',
        'external_work_order_id',
        'part_name',
        'component_type_id',
        'part_number',
        'type_designation',
        'manufacturer',
        'stock_lot_id',
        'stock_lot_number',
        'part_type_id',
        'serial_number',
        'quantity',
        'document_type',
        'document_reference',
        'document_issuer',
        'document_issuer_approval',
        'document_issued_at',
        'position',
        'lever_arm_mm',
        'is_present',
        'is_minimum_equipment',
        'installed_at',
        'installed_by',
        'installed_by_name',
        'work_order_reference',
        'counters_at_installation',
        'carried_since_new',
        'carried_since_overhaul',
        'overhauled_at',
        'overhaul_reference',
        'removed_at',
        'removed_by',
        'counters_at_removal',
        'removal_reason',
        'note',
    ];

    protected function casts(): array
    {
        return [
            'origin' => InstallationOrigin::class,
            'transcribed_at' => 'date',
            'quantity' => 'float',
            'lever_arm_mm' => 'integer',
            'is_present' => 'boolean',
            'is_minimum_equipment' => 'boolean',
            'installed_at' => 'date',
            'removed_at' => 'date',
            'document_issued_at' => 'date',
            'counters_at_installation' => 'array',
            'counters_at_removal' => 'array',
            'carried_since_new' => 'array',
            'carried_since_overhaul' => 'array',
            'overhauled_at' => 'date',
        ];
    }

    /** @return BelongsTo<Aircraft, $this> */
    /**
     * The catalogued component, if this installation names one.
     *
     * Optional alongside part_name, which stays: a component nobody has
     * catalogued must still be recordable -- the same rule as aircraft types.
     */
    public function componentType(): BelongsTo
    {
        return $this->belongsTo(ComponentType::class, 'component_type_id');
    }

    public function aircraft(): BelongsTo
    {
        return $this->belongsTo(Aircraft::class);
    }

    /** @return BelongsTo<User, $this> */
    public function installedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'installed_by');
    }

    /** @return BelongsTo<ExternalWorkOrder, $this> */
    public function externalWorkOrder(): BelongsTo
    {
        return $this->belongsTo(ExternalWorkOrder::class);
    }

    /** @return HasMany<ComponentLimit, $this> */
    public function limits(): HasMany
    {
        return $this->hasMany(ComponentLimit::class);
    }

    public function isFitted(): bool
    {
        return $this->removed_at === null;
    }

    /**
     * How much this component has done on a counter, on a given basis.
     *
     * The aircraft's counter now, minus its counter when the part went on, plus
     * whatever the part had already done before it got here. That last term is
     * where TSN and TSO part company: they advance together from the moment of
     * fitting and differ only in where they started.
     *
     * Once removed, the reading is frozen at removal: a part in a box does not
     * accrue hours because the aircraft kept flying.
     */
    public function usage(?CounterKind $kind, UsageBasis $basis = UsageBasis::SinceOverhaul): ?float
    {
        if ($kind === null) {
            return null;
        }

        $atInstall = $this->counters_at_installation[$kind->value] ?? null;

        $now = $this->removed_at !== null
            ? ($this->counters_at_removal[$kind->value] ?? null)
            : $this->aircraft?->currentValue($kind);

        if ($atInstall === null) {
            /*
             * No baseline in the snapshot. THREE situations hide behind that,
             * and they get three different answers:
             *
             *  - The aircraft does not KEEP this counter at all: there is no
             *    dimension to answer in. A launch limit on an aircraft that
             *    counts no launches is a limit nobody can answer, and "0 used"
             *    would present that as "plenty left". Null.
             *
             *  - Kept, but STILL never read: nothing counted has advanced, so
             *    what the papers brought along is the whole answer. A
             *    sixty-year-old aircraft onboarded with its engine at 1800 h
             *    stays at 1800 h until somebody starts reading the counter.
             *
             *  - Kept and read SINCE: there is a figure now, but no baseline
             *    to measure it against. The difference is unanswerable --
             *    handing the part the whole reading would gift it the
             *    aircraft's entire life, and "zero used" would be a
             *    comforting guess. Null again.
             */
            if ($now !== null) {
                return null;
            }

            $kept = $this->aircraft?->keeps($kind) ?? false;

            return $kept ? $this->carried($kind, $basis) : null;
        }

        if ($now === null) {
            return null;
        }

        return $this->carried($kind, $basis) + max(0.0, (float) $now - (float) $atInstall);
    }

    /** Total time since the part was made. */
    public function timeSinceNew(?CounterKind $kind): ?float
    {
        return $this->usage($kind, UsageBasis::SinceNew);
    }

    /** Time since the last overhaul -- equal to TSN where there has been none. */
    public function timeSinceOverhaul(?CounterKind $kind): ?float
    {
        return $this->usage($kind, UsageBasis::SinceOverhaul);
    }

    /**
     * What the part brought with it on this basis.
     *
     * The fallback is the point of the second remark. Most components have
     * no overhaul concept at all, and for those TSO simply IS TSN -- so an
     * absent since-overhaul figure reads the since-new one rather than being
     * treated as nil. Treating it as nil would silently declare every part
     * factory-fresh at every refit.
     */
    private function carried(CounterKind $kind, UsageBasis $basis): float
    {
        $sinceNew = (float) ($this->carried_since_new[$kind->value] ?? 0.0);

        if ($basis === UsageBasis::SinceNew) {
            return $sinceNew;
        }

        $sinceOverhaul = $this->carried_since_overhaul[$kind->value] ?? null;

        return $sinceOverhaul === null ? $sinceNew : (float) $sinceOverhaul;
    }

    /**
     * Whether an overhaul was recorded when this part went on.
     *
     * Never inferred from a repair having taken place. the two engines went
     * to the same manufacturer and came back differently, so only the returning
     * paperwork can say -- and it says so here.
     */
    public function wasOverhauled(): bool
    {
        return $this->overhauled_at !== null;
    }

    /**
     * The limit that falls due first, and when.
     *
     * "Whatever comes first" made answerable: calendar limits are compared as
     * days remaining, counted ones as units remaining, and to put the two in one
     * order the counted ones are ranked by how close to nil they are rather than
     * by an invented date. A part with 20 launches left and eleven months to run
     * is due on the launches, and that is what this returns.
     *
     * @return array{limit: ComponentLimit, overdue: bool}|null
     */
    public function nextDue(): ?array
    {
        $best = null;
        $bestScore = null;

        foreach ($this->limits as $limit) {
            $score = $this->scoreOf($limit);

            if ($score === null) {
                continue;
            }

            if ($bestScore === null || $score < $bestScore) {
                $bestScore = $score;
                $best = $limit;
            }
        }

        return $best === null ? null : ['limit' => $best, 'overdue' => $best->isOverdue()];
    }

    public function isOverdue(): bool
    {
        return $this->limits->contains(fn (ComponentLimit $l): bool => $l->isOverdue());
    }

    /**
     * A single scale so limits of different kinds can be ordered against each
     * other. Lower is more urgent.
     *
     * Calendar limits score in days. Counted limits score in "days at the rate
     * this aircraft actually flies" would be the sophisticated answer, and it
     * would be a guess dressed as arithmetic -- an aircraft that flew 200 hours
     * last summer may fly none this one. So counted limits score by the fraction
     * of the limit left, mapped onto the same range. It orders sensibly and it
     * does not pretend to predict.
     */
    private function scoreOf(ComponentLimit $limit): ?float
    {
        if ($limit->kind->isCalendar()) {
            $days = $limit->remainingDays();

            return $days === null ? null : (float) $days;
        }

        $remaining = $limit->remaining();

        if ($remaining === null || $limit->value === null || (float) $limit->value <= 0) {
            return null;
        }

        // Fraction left, expressed on the same scale as a year of days, so a
        // limit at 10 % ranks against roughly five weeks of calendar.
        return ($remaining / (float) $limit->value) * 365.0;
    }

    /**
     * Equipment without which the aircraft does not go.
     *
     * the example is the whole rule: take out the extra Garmin G5 and it
     * flies, take out the analogue instrument and it stands. The difference is
     * this flag and nothing else -- both are instruments, both were fitted, and
     * only one of them is required.
     *
     * @param  Builder<Installation>  $query
     */
    public function scopeMinimumEquipment(Builder $query): void
    {
        $query->where('is_minimum_equipment', true);
    }

    /**
     * Whether this line is our own evidence or somebody else's, transcribed.
     *
     * Both are legitimate; only one of them was witnessed here. An auditor
     * asking "how do you know" deserves a different answer in each case, and a
     * screen that showed them alike would be giving the wrong one.
     */
    public function wasTranscribed(): bool
    {
        return $this->origin !== InstallationOrigin::Stock;
    }

    /** @param  Builder<Installation>  $query */
    public function scopeTranscribed(Builder $query): void
    {
        $query->whereNot('origin', InstallationOrigin::Stock->value);
    }

    /** @param  Builder<Installation>  $query */
    public function scopeFitted(Builder $query): void
    {
        $query->whereNull('removed_at');
    }

    /**
     * Installations carrying a certificate.
     *
     * The life record's reason for existing: one lot's Form 1 can end up in
     * several aircraft, so each of them keeps its own readable copy.
     *
     * @param  Builder<Installation>  $query
     */
    public function scopeCertified(Builder $query): void
    {
        $query->where('document_type', '!=', self::DOCUMENT_NONE)
            ->whereNotNull('document_reference');
    }

    public function label(): string
    {
        return filled($this->serial_number)
            ? sprintf('%s (S/N %s)', $this->part_name, $this->serial_number)
            : $this->part_name;
    }

    /**
     * Records the aircraft's counters as they stand, for the snapshot columns.
     *
     * @return array<string, float>
     */
    public static function snapshotOf(Aircraft $aircraft): array
    {
        return $aircraft->currentValues();
    }

    /**
     * Every limit that has run out or is within the given warning window.
     *
     * @return list<ComponentLimit>
     */
    public function limitsDueWithin(int $days, ?Carbon $on = null): array
    {
        $on ??= now();
        $due = [];

        foreach ($this->limits as $limit) {
            if ($limit->isOverdue()) {
                $due[] = $limit;

                continue;
            }

            if ($limit->kind === LimitKind::CalendarMonths || $limit->kind === LimitKind::CalendarDate) {
                $remaining = $limit->remainingDays();

                if ($remaining !== null && $remaining <= $days) {
                    $due[] = $limit;
                }
            }
        }

        return $due;
    }
}
