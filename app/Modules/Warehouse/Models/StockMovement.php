<?php

declare(strict_types=1);

namespace App\Modules\Warehouse\Models;

use App\Models\User;
use App\Modules\Warehouse\Enums\MovementType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

/**
 * One movement of stock -- append-only.
 *
 * Decision E1. Stock is the sum of these; there is no quantity anywhere to
 * overwrite. That has three consequences worth stating, because they are the
 * reason for the design rather than side effects of it:
 *
 *  - A correction is a counter-booking. The original stays visible, and both
 *    together explain what happened -- which is what a stocktaking difference
 *    actually looks like on paper too.
 *  - The history is the ledger itself, not a separate log kept alongside it
 *    that may or may not have been maintained. The legacy system had such a
 *    log, and it was empty.
 *  - "Where did this part come from" is answerable by walking movement -> lot
 *    -> certificate, without a second system.
 *
 * Editing and deleting are refused in the model, not merely discouraged. A
 * ledger that can be altered is not a ledger.
 */
final class StockMovement extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'part_type_id',
        'stock_lot_id',
        'type',
        'quantity',
        'occurred_at',
        'user_id',
        'work_order_reference',
        'aircraft_reference',
        'reverses_movement_id',
        'note',
    ];

    protected function casts(): array
    {
        return [
            'type' => MovementType::class,
            'quantity' => 'decimal:3',
            'occurred_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        self::updating(function (): never {
            throw new RuntimeException(
                'Stock movements cannot be changed. A mistake is put right with a counter-booking.'
            );
        });

        self::deleting(function (): never {
            throw new RuntimeException(
                'Stock movements cannot be deleted -- they are the stock. Book a correction instead.'
            );
        });
    }

    /** @return BelongsTo<PartType, $this> */
    public function partType(): BelongsTo
    {
        return $this->belongsTo(PartType::class);
    }

    /** @return BelongsTo<StockLot, $this> */
    public function lot(): BelongsTo
    {
        return $this->belongsTo(StockLot::class, 'stock_lot_id');
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<StockMovement, $this> */
    public function reverses(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reverses_movement_id');
    }

    /** @param  Builder<StockMovement>  $query */
    public function scopeInbound(Builder $query): void
    {
        $query->where('quantity', '>', 0);
    }

    /** @param  Builder<StockMovement>  $query */
    public function scopeOutbound(Builder $query): void
    {
        $query->where('quantity', '<', 0);
    }

    public function isInbound(): bool
    {
        return (float) $this->quantity > 0;
    }
}
