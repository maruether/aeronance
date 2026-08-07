<?php

declare(strict_types=1);

namespace App\Modules\Fleet\Models;

use App\Models\User;
use App\Modules\Fleet\Enums\ExternalWorkState;
use App\Modules\Fleet\Enums\ReleasedBy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A job given to another organisation.
 *
 * Records the event, not the work: who had the aircraft, what came back, whose
 * signature closed it. The tasks and hours belong to the task card module when
 * it arrives -- this side of it stands alone either way, because a life record
 * has to say what happened to the aircraft whether or not anybody wrote cards.
 */
final class ExternalWorkOrder extends Model
{
    use SoftDeletes;

    protected $attributes = ['state' => 'commissioned'];

    protected $fillable = [
        'aircraft_id',
        'shop_name',
        'shop_approval',
        'order_reference',
        'scope',
        'sent_at',
        'expected_back_at',
        'returned_at',
        'sent_by',
        'state',
        'released_by',
        'released_at',
        'release_reference',
        'released_by_name',
        'released_by_approval',
        'released_by_user',
        'qualification_type',
        'qualification_reference',
        'qualification_category',
        'report_reference',
        'note',
    ];

    protected function casts(): array
    {
        return [
            'state' => ExternalWorkState::class,
            'released_by' => ReleasedBy::class,
            'sent_at' => 'date',
            'expected_back_at' => 'date',
            'returned_at' => 'date',
            'released_at' => 'date',
        ];
    }

    /** @return BelongsTo<Aircraft, $this> */
    public function aircraft(): BelongsTo
    {
        return $this->belongsTo(Aircraft::class);
    }

    /** @return BelongsTo<User, $this> */
    public function sentBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sent_by');
    }

    /**
     * Parts the shop fitted during this job.
     *
     * @return HasMany<Installation, $this>
     */
    public function installations(): HasMany
    {
        return $this->hasMany(Installation::class);
    }

    public function isReleased(): bool
    {
        return $this->state === ExternalWorkState::Released;
    }

    /**
     * Back, and nothing yet says it may fly.
     *
     * The gap worth naming: the aircraft is in the hangar and looks finished.
     */
    public function isAwaitingRelease(): bool
    {
        return $this->state->isAwaitingRelease();
    }

    public function isOverdue(): bool
    {
        return $this->state === ExternalWorkState::Commissioned
            && $this->expected_back_at !== null
            && $this->expected_back_at->toDateString() < now()->toDateString();
    }

    /** @param  Builder<ExternalWorkOrder>  $query */
    public function scopeOpen(Builder $query): void
    {
        $query->whereIn('state', [
            ExternalWorkState::Commissioned->value,
            ExternalWorkState::Returned->value,
        ]);
    }

    /** @param  Builder<ExternalWorkOrder>  $query */
    public function scopeAwaitingRelease(Builder $query): void
    {
        $query->where('state', ExternalWorkState::Returned->value);
    }

    public function label(): string
    {
        return sprintf('%s — %s', $this->shop_name, $this->sent_at->format('d.m.Y'));
    }
}
