<?php

declare(strict_types=1);

namespace App\Modules\Warehouse\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * A place where things are kept -- a room, a hall, a cupboard.
 *
 * A quarantine location is an ordinary location with a flag. 145.A.42 requires
 * unserviceable and unsalvageable parts to be kept apart from usable ones, and
 * making that a TYPE rather than a separate table means a lot keeps its
 * identity, its certificate and its history when it moves there. Blocking
 * something is a transfer, not a loss of information.
 */
final class StorageLocation extends Model
{
    use LogsActivity, SoftDeletes;

    protected $fillable = ['name', 'description', 'is_quarantine'];

    protected function casts(): array
    {
        return ['is_quarantine' => 'boolean'];
    }

    /** @return HasMany<StorageCompartment, $this> */
    public function compartments(): HasMany
    {
        return $this->hasMany(StorageCompartment::class);
    }

    /** @param  Builder<StorageLocation>  $query */
    public function scopeQuarantine(Builder $query): void
    {
        $query->where('is_quarantine', true);
    }

    /** @param  Builder<StorageLocation>  $query */
    public function scopeOrdinary(Builder $query): void
    {
        $query->where('is_quarantine', false);
    }

    /**
     * What of this record ends up in the audit trail.
     *
     * Only the fields that carry meaning are logged, and only when they
     * actually change -- a trail full of no-op saves is one nobody reads.
     */
    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['name', 'is_quarantine'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges()
            ->useLogName('warehouse');
    }
}
