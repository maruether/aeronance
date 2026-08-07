<?php

declare(strict_types=1);

namespace App\Modules\Warehouse\Models;

use App\Models\User;
use App\Modules\Warehouse\Enums\RepairDestination;
use App\Modules\Warehouse\Enums\RepairState;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A part that left the store to be repaired.
 *
 * Not a repair workflow -- the warehouse only answers two questions: where is
 * the part, and is it coming back. What happens at the shop belongs to the shop,
 * or to a component repair module if one is ever built.
 *
 * The record exists at all because a dispatched part is off the shelf but still
 * the club's property. Booking it out as an ordinary issue would have made it
 * disappear from the books the moment it went into the parcel, and "where is the
 * tow release?" would have no answer.
 */
final class RepairDispatch extends Model
{
    use SoftDeletes;

    protected $attributes = [
        'destination' => 'external',
        'state' => 'dispatched',
    ];

    protected $fillable = [
        'part_type_id',
        'stock_lot_id',
        'quantity',
        'serial_number',
        'destination',
        'supplier_id',
        'shop_name',
        'shop_approval',
        'dispatch_reference',
        'reason',
        'restricted_to_aircraft',
        'aircraft_type',
        'dispatched_at',
        'dispatched_by',
        'expected_back_at',
        'state',
        'returned_at',
        'returned_by',
        'returned_lot_id',
        'return_note',
    ];

    protected function casts(): array
    {
        return [
            'destination' => RepairDestination::class,
            'state' => RepairState::class,
            'quantity' => 'float',
            'dispatched_at' => 'date',
            'expected_back_at' => 'date',
            'returned_at' => 'date',
        ];
    }

    /** @return BelongsTo<PartType, $this> */
    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function partType(): BelongsTo
    {
        return $this->belongsTo(PartType::class);
    }

    /**
     * The lot the part came out of.
     *
     * @return BelongsTo<StockLot, $this>
     */
    public function lot(): BelongsTo
    {
        return $this->belongsTo(StockLot::class, 'stock_lot_id');
    }

    /**
     * The lot it came back in -- a different one, carrying a different paper.
     *
     * @return BelongsTo<StockLot, $this>
     */
    public function returnedLot(): BelongsTo
    {
        return $this->belongsTo(StockLot::class, 'returned_lot_id');
    }

    /** @return BelongsTo<User, $this> */
    public function dispatchedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'dispatched_by');
    }

    /** @return BelongsTo<User, $this> */
    public function returnedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'returned_by');
    }

    /** @param  Builder<RepairDispatch>  $query */
    public function scopeOpen(Builder $query): void
    {
        $query->where('state', RepairState::Dispatched->value);
    }

    /**
     * Past the date it was expected back.
     *
     * Not an error -- shops run late. But nobody remembers a part that has been
     * away eight months, and that is exactly when it gets written off in
     * everyone's head while still standing in the books.
     *
     * @param  Builder<RepairDispatch>  $query
     */
    public function scopeOverdue(Builder $query): void
    {
        $query->open()
            ->whereNotNull('expected_back_at')
            ->whereDate('expected_back_at', '<', now()->toDateString());
    }

    public function isOverdue(): bool
    {
        return $this->state->isOpen()
            && $this->expected_back_at !== null
            && $this->expected_back_at->toDateString() < now()->toDateString();
    }

    /**
     * Whether the part will still be tied to one aircraft when it returns.
     *
     * The whole reason a club sends a part away rather than fitting it
     * elsewhere: a certificate from the repairing organisation discharges the
     * restriction. This says what is at stake before the part comes back.
     */
    public function carriesAircraftRestriction(): bool
    {
        return $this->restricted_to_aircraft !== null;
    }

    public function label(): string
    {
        $name = $this->partType?->name ?? '?';

        return filled($this->serial_number)
            ? sprintf('%s (S/N %s)', $name, $this->serial_number)
            : $name;
    }
}
