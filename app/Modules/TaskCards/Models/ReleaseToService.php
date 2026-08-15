<?php

declare(strict_types=1);

namespace App\Modules\TaskCards\Models;

use App\Models\User;
use App\Modules\Fleet\Models\Aircraft;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

/**
 * A certificate of release to service.
 *
 * Named ReleaseToService rather than Release because the table is `releases` and
 * "Release" alone reads like a software version everywhere else in the codebase.
 *
 * Immutable from the moment it is written. Not "should not be edited" -- cannot
 * be, in the model, because a certificate whose text can change afterwards is
 * not a certificate. Corrections are new records pointing at this one.
 */
final class ReleaseToService extends Model
{
    protected $table = 'releases';

    /**
     * Der Nachweis einer Person, die NICHT hier im System steht.
     *
     * Kein Qualification::TYPE_*, und das mit Absicht: Diese Nummer wurde
     * abgeschrieben, nicht geprüft. Sie unter denselben Typ zu stellen wie
     * eine hinterlegte Lizenz hiesse zu behaupten, jemand habe sie gesehen.
     */
    public const CREDENTIAL_EXTERNAL = 'external_licence';

    protected $fillable = [
        'work_order_id',
        'aircraft_id',
        'aircraft_registration',
        'aircraft_model',
        'number',
        'statement',
        'maintenance_data',
        'released_at',
        'released_by',
        'released_by_name',
        'is_external',
        'recorded_by',
        'recorded_by_name',
        'external_organisation',
        'qualification_type',
        'qualification_reference',
        'qualification_category',

        // What the licence was limited to when it signed -- frozen like the rest
        // of the credential, because 66.A.50 lets limitations be lifted later.
        'qualification_limitations',
        'qualification_valid_until',
        'counters_at_release',
        'supersedes_release_id',
        'correction_reason',
    ];

    protected function casts(): array
    {
        return [
            'released_at' => 'datetime',
            'is_external' => 'boolean',
            'qualification_valid_until' => 'date',
            'counters_at_release' => 'array',
        ];
    }

    protected static function booted(): void
    {
        self::updating(function (): never {
            throw new RuntimeException(
                'A release cannot be changed. A correction is a new release referencing '
                .'this one -- the original keeps its text and its signature.'
            );
        });

        self::deleting(function (): never {
            throw new RuntimeException(
                'A release is not deleted. It is superseded by a new one that says what '
                .'was wrong with it.'
            );
        });
    }

    /** @return BelongsTo<WorkOrder, $this> */
    public function workOrder(): BelongsTo
    {
        return $this->belongsTo(WorkOrder::class);
    }

    /** @return BelongsTo<Aircraft, $this> */
    public function aircraft(): BelongsTo
    {
        return $this->belongsTo(Aircraft::class);
    }

    /** @return BelongsTo<User, $this> */
    public function releasedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'released_by');
    }

    /** @return BelongsTo<ReleaseToService, $this> */
    public function supersedes(): BelongsTo
    {
        return $this->belongsTo(self::class, 'supersedes_release_id');
    }

    /**
     * Whether a later release has replaced this one.
     *
     * Asked rather than stored, because the answer lives in the other record and
     * a flag here would be a second place to keep it right.
     */
    public function isSuperseded(): bool
    {
        return self::where('supersedes_release_id', $this->id)->exists();
    }

    public function isCorrection(): bool
    {
        return $this->supersedes_release_id !== null;
    }

    /**
     * The releases that still stand.
     *
     * @param  Builder<ReleaseToService>  $query
     */
    public function scopeCurrent(Builder $query): void
    {
        $query->whereNotIn(
            'id',
            self::query()->whereNotNull('supersedes_release_id')->select('supersedes_release_id'),
        );
    }

    public function label(): string
    {
        return sprintf('%s — %s', $this->number, $this->released_at->format('d.m.Y'));
    }
}
