<?php

declare(strict_types=1);

namespace App\Modules\Fleet\Models;

use App\Modules\Fleet\Enums\LimitKind;
use App\Modules\Fleet\Enums\LimitStatus;
use App\Modules\Fleet\Enums\UsageBasis;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One limit on one fitted component.
 *
 * Several of these hang on the same installation, and that is the entire design:
 * a Tost tow release runs "2 Jahre oder 500 Starts, whatever comes first".
 * Whichever arrives first is what falls due.
 */
final class ComponentLimit extends Model
{
    protected $attributes = ['basis' => 'since_overhaul'];

    protected $fillable = [
        'installation_id',
        'kind',
        'basis',
        'tolerance_percent',
        'tolerance_absolute',
        'last_done_at',
        'last_done_value',
        'last_due_at',
        'last_due_value',
        'value',
        'due_on',
        'source',
        'note',
    ];

    protected function casts(): array
    {
        return [
            'kind' => LimitKind::class,
            'basis' => UsageBasis::class,
            'value' => 'decimal:2',
            'due_on' => 'date',
            'tolerance_percent' => 'decimal:2',
            'tolerance_absolute' => 'decimal:2',
            'last_done_at' => 'date',
            'last_done_value' => 'decimal:2',
            'last_due_at' => 'date',
            'last_due_value' => 'decimal:2',
        ];
    }

    /** @return BelongsTo<Installation, $this> */
    public function installation(): BelongsTo
    {
        return $this->belongsTo(Installation::class);
    }

    /**
     * The day this limit falls due, where it is a calendar one.
     *
     * Months run from the installation date, unless a fixed day was given --
     * some certificates name a date rather than an interval, and inventing an
     * interval from it would be arithmetic on somebody else's document.
     */
    public function dueDate(): ?Carbon
    {
        if ($this->kind === LimitKind::CalendarDate) {
            return $this->due_on;
        }

        if ($this->kind === LimitKind::CalendarMonths && $this->value !== null) {
            return $this->anchorDate()?->copy()->addMonths((int) $this->value);
        }

        return null;
    }

    /**
     * The day the current interval runs from.
     *
     * The last time the work was actually done, or the installation where it
     * never has been. Anchoring permanently to the installation was the first
     * version's mistake and is right exactly once.
     */
    public function anchorDate(): ?Carbon
    {
        return $this->last_done_at ?? $this->installation?->installed_at;
    }

    /**
     * The permitted overrun, in this limit's own unit.
     *
     * Where both forms are given the smaller wins, which is what "10 % oder
     * 1 Monat" means: ten per cent of a hundred hours is ten hours, ten per cent
     * of twelve months is more than a month, and the month is the answer.
     */
    public function tolerance(): float
    {
        $fromPercent = $this->tolerance_percent !== null && $this->value !== null
            ? (float) $this->value * ((float) $this->tolerance_percent / 100)
            : null;

        $absolute = $this->tolerance_absolute !== null ? (float) $this->tolerance_absolute : null;

        return match (true) {
            $fromPercent !== null && $absolute !== null => min($fromPercent, $absolute),
            $fromPercent !== null => $fromPercent,
            $absolute !== null => $absolute,
            default => 0.0,
        };
    }

    /**
     * The last day the work may still be done without being late.
     *
     * Calendar tolerance is expressed in months, since that is how the
     * programmes write it.
     */
    public function toleratedUntil(): ?Carbon
    {
        $due = $this->dueDate();

        if ($due === null) {
            return null;
        }

        $tolerance = $this->tolerance();

        return $tolerance <= 0 ? $due : $due->copy()->addMonths((int) ceil($tolerance));
    }

    /**
     * Where this limit stands, in four states rather than two.
     */
    public function status(int $warnWithinDays = 60): LimitStatus
    {
        if ($this->kind->isCalendar()) {
            $days = $this->remainingDays();

            if ($days === null) {
                return LimitStatus::Ok;
            }

            if ($days >= 0) {
                return $days <= $warnWithinDays ? LimitStatus::Due : LimitStatus::Ok;
            }

            $tolerated = $this->toleratedUntil();

            return $tolerated !== null && now()->startOfDay()->lte($tolerated->startOfDay())
                ? LimitStatus::InTolerance
                : LimitStatus::Overdue;
        }

        $remaining = $this->remaining();

        if ($remaining === null) {
            return LimitStatus::Ok;
        }

        if ($remaining > 0) {
            // The last tenth, for want of a date -- see CollectDueItems.
            $threshold = $this->value !== null ? (float) $this->value * 0.1 : 0.0;

            return $remaining <= $threshold ? LimitStatus::Due : LimitStatus::Ok;
        }

        return abs($remaining) <= $this->tolerance()
            ? LimitStatus::InTolerance
            : LimitStatus::Overdue;
    }

    /**
     * How much of a counted limit is left.
     *
     * Null for calendar limits -- they run down in days, not in units, and
     * mixing the two into one number is how "500" comes to mean days.
     */
    public function remaining(): ?float
    {
        if ($this->kind->isCalendar() || $this->value === null) {
            return null;
        }

        $used = $this->installation?->usage(
            $this->kind->counter(),
            $this->basis ?? UsageBasis::SinceOverhaul,
        );

        if ($used === null) {
            return null;
        }

        // Anchored at the last completion, where there has been one. Without
        // this a recurring limit would go on measuring from the installation
        // and fall due again the moment it was met.
        $anchor = $this->last_done_value !== null ? (float) $this->last_done_value : 0.0;

        return (float) $this->value - ($used - $anchor);
    }

    /**
     * The AIRCRAFT counter reading at which this falls due.
     *
     * The BWLV Betriebszeitenübersicht has a column for exactly this -- "fälliger
     * Ausbau" -- and it is not the same question as "how much is left". At the
     * hangar there is an instrument with a number on it, and somebody wants to
     * compare without doing arithmetic on a component's private running total.
     *
     * Derived from the remainder rather than re-deriving the formula: the
     * component's usage advances one for one with the aircraft's counter while
     * it is fitted, so the reading it falls due at is simply today's reading
     * plus what is left. Obvious once written down, and much harder to get
     * wrong than unpicking the installation snapshot again.
     */
    public function dueAtAircraftValue(): ?float
    {
        $remaining = $this->remaining();

        if ($remaining === null) {
            return null;
        }

        $now = $this->installation?->aircraft?->currentValue($this->kind->counter());

        return $now === null ? null : $now + $remaining;
    }

    /**
     * Days left on a calendar limit, negative once it has passed.
     */
    public function remainingDays(): ?int
    {
        $due = $this->dueDate();

        return $due === null ? null : (int) now()->startOfDay()->diffInDays($due->startOfDay(), false);
    }

    /**
     * Past its due date -- whether or not the overrun is permitted.
     *
     * Kept as the blunt question because plenty of places want exactly that.
     * Whether it is TOLERATED is status(), and the two are not the same claim.
     */
    public function isOverdue(): bool
    {
        return $this->status()->isPastDue();
    }

    /** Past everything, tolerance included. */
    public function isBeyondTolerance(): bool
    {
        return $this->status() === LimitStatus::Overdue;
    }

    public function describe(): string
    {
        if ($this->kind === LimitKind::CalendarDate) {
            return $this->due_on?->format('d.m.Y') ?? '—';
        }

        return sprintf(
            '%s %s (%s)',
            rtrim(rtrim(number_format((float) $this->value, 2, ',', '.'), '0'), ','),
            $this->kind->label(),
            ($this->basis ?? UsageBasis::SinceOverhaul)->abbreviation(),
        );
    }
}
