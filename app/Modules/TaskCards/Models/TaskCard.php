<?php

declare(strict_types=1);

namespace App\Modules\TaskCards\Models;

use App\Models\User;
use App\Modules\TaskCards\Enums\ActivityKind;
use App\Modules\TaskCards\Enums\ParticipationKind;
use App\Modules\TaskCards\Enums\TaskCardState;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use RuntimeException;

/**
 * One job.
 *
 * Carries the Part-66 fields from the very first card, which is the only way the
 * experience logbook can be what CLAUDE.md wants it to be -- "eine Auswertung,
 * keine Extra-Pflege". Add them later and there is already a year of cards that
 * cannot be evaluated.
 *
 * Registration and type are COPIED rather than read through the work order. An
 * entry in somebody's logbook records what they worked on that day, and that
 * fact does not change because the aircraft was sold, re-registered or removed
 * from the fleet list.
 */
final class TaskCard extends Model
{
    use SoftDeletes;

    protected $attributes = [
        'state' => 'open',
        'activity_kind' => 'maintenance',
    ];

    protected $fillable = [
        'work_order_id',
        'number',
        'title',
        'instruction',
        'manual_reference',
        'aircraft_registration',
        'aircraft_model',
        'ata_chapter',
        'activity_kind',
        'critical',
        'critical_reason',
        'within_pilot_owner_scope',
        'state',
        'completed_at',
        'completed_by',
        'completed_by_name',
        'work_performed',
        'inspected_at',
        'inspected_by',
        'inspected_by_name',
        'inspection_note',
        'inspection_qualification_type',
        'inspection_qualification_reference',
        'certified_at',
        'certified_by',
        'certified_by_name',
        'qualification_type',
        'qualification_reference',
        'qualification_category',
        'qualification_limitations',
        'cancellation_reason',
        'component_limit_id',
    ];

    protected function casts(): array
    {
        return [
            'state' => TaskCardState::class,
            'activity_kind' => ActivityKind::class,

            // Nullable on purpose: null is "not assessed", which is a different
            // answer from "no". See the migration.
            'within_pilot_owner_scope' => 'boolean',
            'completed_at' => 'datetime',
            'inspected_at' => 'datetime',
            'certified_at' => 'datetime',
            'critical' => 'boolean',
        ];
    }

    /**
     * Frozen with its visit.
     *
     * Freezing only the work order would have been decoration: the certificate
     * says what these cards say, so a card that can still be edited is a
     * certificate whose content can still change.
     */
    protected static function booted(): void
    {
        /*
         * withTrashed, because a soft-deleted parent is not an absent one. The
         * relation resolving to null for a trashed visit was how the review's
         * "delete the order, edit its cards, restore" bypass worked -- the guard
         * read null as "no visit, nothing frozen".
         */
        $frozen = fn (?int $orderId): bool => $orderId !== null
            && (WorkOrder::withTrashed()->find($orderId)?->isReleased() ?? false);

        self::creating(function (self $card) use ($frozen): void {
            if ($frozen($card->work_order_id)) {
                throw new RuntimeException(
                    'The visit this card belongs to has been released to service. Its '
                    .'cards are frozen.'
                );
            }
        });

        self::updating(function (self $card) use ($frozen): void {
            /*
             * A card never changes visits. Its number embeds the visit, the
             * certificate covers the visit's cards, and the guard checking only
             * the NEW parent made reparenting a freeze bypass: point the card at
             * an open visit and the frozen one quietly loses content.
             */
            if ($card->isDirty('work_order_id')) {
                throw new RuntimeException(
                    'A card stays in the visit it was raised in. Its number and the '
                    .'records that refer to it depend on that.'
                );
            }

            if ($frozen((int) $card->getOriginal('work_order_id'))) {
                throw new RuntimeException(
                    'The visit this card belongs to has been released to service. Its '
                    .'cards are frozen.'
                );
            }
        });

        self::deleting(function (self $card) use ($frozen): void {
            if ($frozen($card->work_order_id)) {
                throw new RuntimeException(
                    'The visit this card belongs to has been released to service. Its '
                    .'cards are frozen.'
                );
            }
        });
    }

    /** @return BelongsTo<WorkOrder, $this> */
    public function workOrder(): BelongsTo
    {
        return $this->belongsTo(WorkOrder::class);
    }

    /** @return BelongsTo<User, $this> */
    public function completedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'completed_by');
    }

    /** @return BelongsTo<User, $this> */
    public function certifiedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'certified_by');
    }

    /** @return HasMany<TaskCardTime, $this> */
    public function times(): HasMany
    {
        return $this->hasMany(TaskCardTime::class)->orderBy('worked_on');
    }

    /**
     * Kritisch, fertiggemeldet, aber noch nicht kontrolliert.
     *
     * Der Zustand, in dem eine Karte liegen bleibt und niemandem auffällt --
     * deshalb hat er einen Namen und wird in der Liste angezeigt.
     */
    public function awaitsIndependentInspection(): bool
    {
        return $this->critical
            && $this->state === TaskCardState::Completed
            && $this->inspected_at === null;
    }

    public function wasIndependentlyInspected(): bool
    {
        return $this->inspected_at !== null;
    }

    /**
     * Findings noticed while doing this card.
     *
     * @return HasMany<Finding, $this>
     */
    public function findings(): HasMany
    {
        return $this->hasMany(Finding::class);
    }

    public function totalMinutes(): int
    {
        return (int) $this->times->sum('minutes');
    }

    /**
     * Minutes one person put in, by how they took part.
     *
     * The shape the experience logbook needs: 66.A.20(b) counts what somebody
     * did, and doing and assisting are not the same entry.
     */
    public function minutesFor(User $user, ?ParticipationKind $as = null): int
    {
        return (int) $this->times
            ->where('user_id', $user->id)
            ->when($as !== null, fn ($times) => $times->where('participation', $as))
            ->sum('minutes');
    }

    public function isCertified(): bool
    {
        return $this->state === TaskCardState::Certified;
    }

    /** Finished, and waiting for somebody qualified to look at it. */
    public function awaitsCertification(): bool
    {
        return $this->state->awaitsCertification();
    }

    /** @param  Builder<TaskCard>  $query */
    public function scopeOpen(Builder $query): void
    {
        $query->where('state', TaskCardState::Open->value);
    }

    /** @param  Builder<TaskCard>  $query */
    public function scopeAwaitingCertification(Builder $query): void
    {
        $query->where('state', TaskCardState::Completed->value);
    }

    public function label(): string
    {
        return sprintf('%s — %s', $this->number, $this->title);
    }
}
