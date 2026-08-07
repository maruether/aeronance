<?php

declare(strict_types=1);

namespace App\Modules\Directives\Models;

use App\Models\User;
use App\Modules\Directives\Enums\ComplianceState;
use App\Modules\Fleet\Models\Aircraft;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * What this operation says about one directive for one aircraft.
 *
 * The record an inspector actually reads, line by line. Every state change is in
 * the audit trail, because the interesting question afterwards is never "what
 * does it say now" but "when did somebody decide that, and who".
 */
final class DirectiveApplication extends Model
{
    use LogsActivity;

    protected $fillable = [
        'directive_id',
        'aircraft_id',
        'aircraft_registration',
        'state',
        'assessed_at',
        'assessed_by',
        'assessed_by_name',
        'qualification_type',
        'qualification_reference',
        'reason',
        'method',
        'counters_at_compliance',
        'task_card_reference',
        'next_due_at',
        'next_due_value',
    ];

    protected function casts(): array
    {
        return [
            'state' => ComplianceState::class,
            'assessed_at' => 'date',
            'next_due_at' => 'date',
            'next_due_value' => 'decimal:2',
            'counters_at_compliance' => 'array',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['state', 'reason', 'assessed_by_name', 'next_due_at', 'task_card_reference'])
            ->logOnlyDirty()
            ->useLogName('directives');
    }

    /** @return BelongsTo<Directive, $this> */
    public function directive(): BelongsTo
    {
        return $this->belongsTo(Directive::class);
    }

    /** @return BelongsTo<Aircraft, $this> */
    public function aircraft(): BelongsTo
    {
        return $this->belongsTo(Aircraft::class);
    }

    /** @return BelongsTo<User, $this> */
    public function assessedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assessed_by');
    }

    /**
     * ─────────────────────────────────────────────────────────────────────────
     * WHETHER THIS LINE STILL WANTS ATTENTION.
     *
     * Four ways it can, and they are genuinely different situations:
     *
     *  - nobody has looked at it (open)
     *  - somebody looked and said it has not been done (not_carried_out)
     *  - it was done, it recurs, and the interval has come round again
     *  - it was done, it recurs, and the aircraft has passed the counter value
     *
     * the rule lives in the last two: "abgehakte punkte so lange abgehakt
     * bis ihre laufzeit kickt." A ticked line is closed -- until it is not.
     * ─────────────────────────────────────────────────────────────────────────
     */
    public function isOutstanding(?string $on = null): bool
    {
        $today = $on ?? now()->toDateString();

        return match ($this->state) {
            ComplianceState::Open, ComplianceState::NotCarriedOut => true,
            ComplianceState::NotApplicable => false,
            ComplianceState::Complied => $this->recurrenceHasComeRound($today),
        };
    }

    /**
     * Whether a complied recurring directive is due again.
     *
     * Calendar and counter are checked independently and either is enough --
     * "2 Jahre oder 500 Starts, whatever comes first" is the same rule the fleet
     * already applies to component limits.
     */
    public function recurrenceHasComeRound(?string $on = null): bool
    {
        if (! ($this->directive?->is_recurring ?? false)) {
            return false;
        }

        $today = $on ?? now()->toDateString();

        if ($this->next_due_at !== null && $this->next_due_at->toDateString() <= $today) {
            return true;
        }

        if ($this->next_due_value !== null) {
            $counter = $this->directive->interval_counter;
            $current = $counter !== null
                ? ($this->aircraft?->currentValues()[$counter] ?? null)
                : null;

            if ($current !== null && (float) $current >= (float) $this->next_due_value) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether this line grounds the aircraft.
     *
     * Only mandatory directives do. A TM left undone is a decision an operation
     * may take and answer for; an LTA left undone is not. Both still show up as
     * open items -- the difference is whether the aircraft may fly.
     */
    public function isBlocking(?string $on = null): bool
    {
        if (! $this->isOutstanding($on)) {
            return false;
        }

        /*
         * An unassessed line grounds the aircraft whatever its bindingness.
         *
         * Vorgabe: "nicht beurteilt ist ne red flag und verhindert die freigabe."
         * And rightly: nobody has read the line, so nobody can say whether it is
         * the optional one or the one that matters. The uncertainty is the
         * problem, not the directive.
         */
        if ($this->state === ComplianceState::Open) {
            return true;
        }

        // Beyond that, only mandatory lines ground it. A TM the operation
        // decided against is a decision it answers for.
        return $this->directive?->isMandatory() ?? false;
    }

    /**
     * Whether this line stands in the way of a release to service.
     *
     * ─────────────────────────────────────────────────────────────────────────
     * NOT the same question as grounding the aircraft, and the difference is
     * worth being exact about.
     *
     * A CRS says the work in this visit was carried out properly. An aircraft
     * whose ARC has expired is not airworthy, but the maintenance done on it can
     * still be released -- the two statements are about different things.
     *
     * An UNASSESSED directive is different: signing a release while nobody has
     * read a line of the manufacturer's list is signing over an unknown. That is
     * the red flag the brief means, and it is why this method exists separately from
     * isBlocking().
     * ─────────────────────────────────────────────────────────────────────────
     */
    public function blocksRelease(?string $on = null): bool
    {
        return $this->isOutstanding($on)
            && ($this->state === ComplianceState::Open || ($this->directive?->isMandatory() ?? false));
    }

    /** @param  Builder<DirectiveApplication>  $query */
    public function scopeUnassessed(Builder $query): void
    {
        $query->where('state', ComplianceState::Open->value);
    }

    /** @param  Builder<DirectiveApplication>  $query */
    public function scopeNotCarriedOut(Builder $query): void
    {
        $query->where('state', ComplianceState::NotCarriedOut->value);
    }

    public function describe(): string
    {
        $parts = [$this->state->label()];

        if ($this->assessed_at !== null) {
            $parts[] = $this->assessed_at->format('d.m.Y');
        }

        if ($this->assessed_by_name !== null) {
            $parts[] = $this->assessed_by_name;
        }

        if ($this->task_card_reference !== null) {
            $parts[] = $this->task_card_reference;
        }

        return implode(' · ', $parts);
    }
}
