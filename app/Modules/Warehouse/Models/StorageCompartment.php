<?php

declare(strict_types=1);

namespace App\Modules\Warehouse\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * A compartment within a location -- the actual place something sits.
 *
 * The hierarchy is exactly two levels deep, as it was in the legacy system:
 * location, then compartment. Deeper nesting was never needed and would make
 * every lookup harder to read.
 */
final class StorageCompartment extends Model
{
    use LogsActivity, SoftDeletes;

    protected $fillable = ['storage_location_id', 'name', 'description'];

    /** @return BelongsTo<StorageLocation, $this> */
    public function location(): BelongsTo
    {
        return $this->belongsTo(StorageLocation::class, 'storage_location_id');
    }

    /** @return HasMany<StockLot, $this> */
    public function lots(): HasMany
    {
        return $this->hasMany(StockLot::class);
    }

    public function isQuarantine(): bool
    {
        return $this->location?->is_quarantine ?? false;
    }

    public function fullName(): string
    {
        return sprintf('%s [%s]', $this->location?->name ?? '?', $this->name);
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
            ->logOnly(['name', 'storage_location_id'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges()
            ->useLogName('warehouse');
    }
}
