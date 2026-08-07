<?php

declare(strict_types=1);

namespace App\Modules\TaskCards\Models;

use App\Models\User;
use App\Modules\Fleet\Models\Aircraft;
use App\Modules\TaskCards\Enums\FindingState;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Something noticed that was not what anybody set out to do.
 *
 * Its own entity because that is what it is: you take out a screw and see a
 * crack. It is not part of the card you were doing, and it does not go away
 * because that card is finished.
 *
 * Deferring is the state that earns the design. "Holds until the next
 * inspection" is a real and legitimate decision -- and one somebody answers for,
 * so it is frozen with the credential it was made under. The risk of a deferred
 * finding is precisely that it goes quiet, so it stays on the aircraft's open
 * list until it is resolved or dismissed.
 */
final class Finding extends Model
{
    use SoftDeletes;

    protected $attributes = [
        'state' => 'open',
        'is_blocking' => true,
    ];

    protected $fillable = [
        'aircraft_id',
        'task_card_id',
        'number',
        'title',
        'description',
        'state',
        'is_blocking',
        'found_by',
        'found_by_name',
        'found_on',
        'deferred_until',
        'deferral_reason',
        'deferred_by',
        'deferred_by_name',
        'deferral_qualification_type',
        'deferral_qualification_reference',
        'resolving_task_card_id',
        'resolved_on',
        'resolution',
    ];

    protected function casts(): array
    {
        return [
            'state' => FindingState::class,
            'is_blocking' => 'boolean',
            'found_on' => 'date',
            'deferred_until' => 'date',
            'resolved_on' => 'date',
        ];
    }

    /** @return BelongsTo<Aircraft, $this> */
    public function aircraft(): BelongsTo
    {
        return $this->belongsTo(Aircraft::class);
    }

    /** Where it was noticed. */
    /** @return BelongsTo<TaskCard, $this> */
    public function taskCard(): BelongsTo
    {
        return $this->belongsTo(TaskCard::class);
    }

    /** @return BelongsTo<User, $this> */
    public function foundBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'found_by');
    }

    public function isOutstanding(): bool
    {
        return $this->state->isOutstanding();
    }

    /**
     * Whether a deferral has run out.
     *
     * A finding deferred "until the next inspection" with a date on it becomes
     * interesting again when that date passes -- and that is exactly when
     * nobody is thinking about it.
     */
    public function deferralHasLapsed(): bool
    {
        return $this->state === FindingState::Deferred
            && $this->deferred_until !== null
            && $this->deferred_until->toDateString() < now()->toDateString();
    }

    /** @param  Builder<Finding>  $query */
    public function scopeOutstanding(Builder $query): void
    {
        $query->whereIn('state', [
            FindingState::Open->value,
            FindingState::Scheduled->value,
            FindingState::Deferred->value,
        ]);
    }

    /** @param  Builder<Finding>  $query */
    public function scopeBlocking(Builder $query): void
    {
        $query->outstanding()->where('is_blocking', true);
    }

    public function label(): string
    {
        return sprintf('%s — %s', $this->number, $this->title);
    }
}
