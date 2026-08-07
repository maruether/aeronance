<?php

declare(strict_types=1);

namespace App\Modules\TaskCards\Models;

use App\Models\User;
use App\Modules\Fleet\Models\Aircraft;
use App\Modules\Fleet\Models\ExternalWorkOrder;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use RuntimeException;

/**
 * A visit: "D-KABC zur Jahresnachprüfung".
 *
 * The bracket over a set of cards. Worth having in its own right because the
 * counters at the start and end belong to the visit rather than to any one card,
 * and because "what did we do to this aircraft last spring" is the question
 * people actually ask.
 */
final class WorkOrder extends Model
{
    use SoftDeletes;

    public const STATE_OPEN = 'open';

    public const STATE_CLOSED = 'closed';

    public const STATE_CANCELLED = 'cancelled';

    protected $attributes = ['state' => self::STATE_OPEN];

    protected $fillable = [
        'aircraft_id',
        'number',
        'title',
        'description',
        'opened_at',
        'closed_at',
        'opened_by',
        'state',
        'counters_at_open',
        'counters_at_close',
        'released_at',
        'external_work_order_id',
        'note',
    ];

    protected function casts(): array
    {
        return [
            'opened_at' => 'date',
            'closed_at' => 'date',
            'counters_at_open' => 'array',
            'counters_at_close' => 'array',
            'released_at' => 'datetime',
        ];
    }

    /**
     * Frozen once a release has been issued.
     *
     * The leitplanke, enforced in the model rather than only in the screens: a
     * rule that lives in a form is a rule an import, a console command or the
     * next feature does not know about.
     *
     * The guard reads the ORIGINAL value, because the one write that must get
     * through is the release itself -- null when that write began means it was
     * open, and this may be the write closing it.
     */
    protected static function booted(): void
    {
        self::updating(function (self $order): void {
            if ($order->getOriginal('released_at') !== null) {
                throw new RuntimeException(
                    'This visit has been released to service and is frozen. A correction '
                    .'is a new release, or a new visit -- never a change to this one.'
                );
            }
        });

        /*
         * The freeze covers deletion too. Soft-deleting a released visit made
         * its cards UNFREEZE -- their guards walk the relation, and a trashed
         * parent resolved to null -- so delete, edit, restore rewrote a released
         * record without a single exception. Found by the review, and the kind
         * of hole that model guards exist to close: the UI never offered the
         * delete, but an import or a console session would not have asked.
         */
        self::deleting(function (self $order): void {
            if ($order->getOriginal('released_at') !== null) {
                throw new RuntimeException(
                    'A released visit is not deleted. Its certificate refers to it, and a '
                    .'record a certificate refers to stays.'
                );
            }
        });
    }

    public function isReleased(): bool
    {
        return $this->released_at !== null;
    }

    /** @return HasMany<ReleaseToService, $this> */
    public function releases(): HasMany
    {
        return $this->hasMany(ReleaseToService::class)->orderByDesc('released_at');
    }

    /**
     * The release that stands, if any.
     */
    public function currentRelease(): ?ReleaseToService
    {
        return $this->releases()->current()->first();
    }

    /**
     * Whether everything is in place for a release.
     *
     * Every card certified or cancelled -- the same condition as closing, and for
     * the same reason: a card nobody has checked is what the second signature
     * exists to surface.
     */
    public function isReadyForRelease(): bool
    {
        return $this->taskCards()->exists() && $this->allCardsClosed();
    }

    /**
     * The external order behind parts of this visit, if any.
     *
     * A real Eloquent relation without a DB constraint: the column was shipped
     * as a plain indexed bigint, and taskcards declares `requires: fleet`, so
     * the relation is safe -- the module cannot run without the table existing.
     */
    public function externalWorkOrder(): BelongsTo
    {
        return $this->belongsTo(ExternalWorkOrder::class, 'external_work_order_id');
    }

    /** @return BelongsTo<Aircraft, $this> */
    public function aircraft(): BelongsTo
    {
        return $this->belongsTo(Aircraft::class);
    }

    /** @return BelongsTo<User, $this> */
    public function openedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'opened_by');
    }

    /** @return HasMany<TaskCard, $this> */
    public function taskCards(): HasMany
    {
        return $this->hasMany(TaskCard::class)->orderBy('number');
    }

    public function isOpen(): bool
    {
        return $this->state === self::STATE_OPEN;
    }

    /**
     * Whether every card has been dealt with.
     *
     * "Dealt with" means certified or cancelled -- a card that is merely
     * completed is one nobody has checked, and closing a visit over the top of
     * those would bury exactly the thing the second signature exists for.
     */
    public function allCardsClosed(): bool
    {
        return $this->taskCards->every(fn (TaskCard $card): bool => $card->state->isClosed());
    }

    /** @param  Builder<WorkOrder>  $query */
    public function scopeOpen(Builder $query): void
    {
        $query->where('state', self::STATE_OPEN);
    }

    public function label(): string
    {
        return sprintf('%s — %s', $this->number, $this->title);
    }
}
