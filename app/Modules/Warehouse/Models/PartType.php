<?php

declare(strict_types=1);

namespace App\Modules\Warehouse\Models;

use App\Modules\Warehouse\Enums\LifeLimitType;
use App\Modules\Warehouse\Enums\LotState;
use App\Modules\Warehouse\Enums\PartClassification;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * A part type -- what a part IS, as opposed to what is on the shelf.
 *
 * The separation between part type and stock is the one thing worth keeping
 * from the legacy schema unchanged: this table says a 6mm bolt costs so much,
 * lives in that drawer and is a standard part; the lots say how many there
 * currently are and where each batch came from.
 */
final class PartType extends Model
{
    use LogsActivity, SoftDeletes;

    protected $fillable = [
        'name',
        'description',
        'classification',
        'supplier_id',
        'storage_compartment_id',
        'order_code',
        'ipc_part_number',
        'unit_of_measure',
        'minimum_stock',
        'maximum_stock',
        'shelf_life_days',
        'life_limit_type',
        'requires_form_one',
        'serial_tracked',
        'net_purchase_price',
    ];

    protected function casts(): array
    {
        return [
            'classification' => PartClassification::class,
            'life_limit_type' => LifeLimitType::class,
            'requires_form_one' => 'boolean',
            'serial_tracked' => 'boolean',
            'net_purchase_price' => 'decimal:2',
        ];
    }

    /** @return BelongsTo<Supplier, $this> */
    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    /** @return BelongsTo<StorageCompartment, $this> */
    public function storageCompartment(): BelongsTo
    {
        return $this->belongsTo(StorageCompartment::class);
    }

    /** @return HasMany<StockLot, $this> */
    public function lots(): HasMany
    {
        return $this->hasMany(StockLot::class);
    }

    /** @return HasMany<StockMovement, $this> */
    public function movements(): HasMany
    {
        return $this->hasMany(StockMovement::class);
    }

    /**
     * Whether this part is kept in lots or simply counted.
     *
     * Derived rather than stored, on purpose: it follows from three properties
     * that already exist, and a separate column could contradict them -- a part
     * type marked "bulk" while carrying a shelf life would be a state with no
     * sensible meaning.
     *
     * Anything with a Form 1, a shelf life or a serial number is tracked by
     * lot, because for those the question "which delivery did this come from"
     * has to have an answer. Nuts and bolts are simply counted (4.5).
     */
    public function isLotTracked(): bool
    {
        return $this->requires_form_one
            || $this->serial_tracked
            || $this->shelf_life_days !== null;
    }

    /**
     * Whether a removed one of these may go back into stock.
     *
     * False for replacement-interval parts: they are replaced, not recovered,
     * and putting one back on the shelf invites it being fitted again.
     */
    public function allowsReuseAfterRemoval(): bool
    {
        return ($this->life_limit_type ?? LifeLimitType::None)->allowsReuseAfterRemoval();
    }

    public function isBulkStock(): bool
    {
        return ! $this->isLotTracked();
    }

    /**
     * Current stock: the sum of every movement.
     *
     * There is no quantity column to read. That is decision E1, and it is what
     * makes a correction a counter-booking rather than an edit -- the ledger
     * cannot disagree with itself.
     */
    public function currentStock(): float
    {
        return (float) $this->movements()->sum('quantity');
    }

    /**
     * Stock that may actually be issued.
     *
     * Differs from currentStock as soon as something is set aside: a lot in
     * quarantine is still in the building and still on the books, but it must
     * not be fitted to an aircraft.
     */
    public function availableStock(): float
    {
        // Set by scopeWithAvailableStock when the caller asked for it, which
        // saves a query per row in a list.
        if (array_key_exists('available_stock', $this->attributes)) {
            return (float) $this->attributes['available_stock'];
        }

        return (float) self::availableMovements($this->movements())->sum('quantity');
    }

    /**
     * Stock as it stood on a given day.
     *
     * The property that makes a real stocktake possible, and one the predecessor
     * could not have had: because stock is the sum of its movements and no
     * quantity is ever overwritten, the figure for any past date is exactly
     * computable rather than estimated. Same arithmetic as for today, with a
     * different upper bound.
     *
     * A stocktake is by definition a statement AS OF a date -- usually a date
     * that has passed by the time anyone gets round to counting.
     */
    public function stockAsOf(string $date): float
    {
        return (float) $this->movements()
            ->whereDate('occurred_at', '<=', $date)
            ->sum('quantity');
    }

    /**
     * Which movements count towards issuable stock.
     *
     * Defined once and used by both the per-record calculation and the query
     * scope below. Writing the rule twice -- once in PHP, once in SQL -- is how
     * a list quietly starts disagreeing with the record it links to.
     *
     * @template TBuilder of \Illuminate\Database\Eloquent\Builder|\Illuminate\Database\Eloquent\Relations\Relation
     *
     * @param  TBuilder  $query
     * @return TBuilder
     */
    public static function availableMovements($query)
    {
        return $query->where(function ($q): void {
            /*
             * Verfuegbar ist, was auch ausgegeben werden darf -- deshalb
             * derselbe Massstab wie beim Buchen (StockLot::scopeIssuable).
             * Vorher zaehlte allein der Zustand, und ein Form-1-Los ohne
             * Nachweis stand als verfuegbar in der Zahl, obwohl die Ausgabe
             * es verweigert. Eine Zahl, die etwas verspricht, was der
             * naechste Klick zurueckweist, ist schlimmer als keine.
             */
            // Bulk stock has no lot, so nothing can be set aside.
            $q->whereNull('stock_lot_id')
                ->orWhereHas('lot', fn ($lot) => $lot->issuable());
        });
    }

    /**
     * Adds the issuable quantity as a column, so a list needs one query rather
     * than one per row.
     *
     * @param  Builder<PartType>  $query
     */
    public function scopeWithAvailableStock(Builder $query): void
    {
        $query->withSum(
            ['movements as available_stock' => fn ($q) => self::availableMovements($q)],
            'quantity',
        );
    }

    /**
     * Part types whose issuable stock has fallen below their minimum.
     *
     * Expressed in SQL so the list can be filtered and sorted by the database
     * rather than loading every part type to compare in PHP.
     *
     * @param  Builder<PartType>  $query
     */
    public function scopeBelowMinimum(Builder $query): void
    {
        $query->whereNotNull('minimum_stock')
            ->whereRaw('COALESCE((
                SELECT SUM(m.quantity) FROM stock_movements m
                LEFT JOIN stock_lots l ON l.id = m.stock_lot_id
                WHERE m.part_type_id = part_types.id
                  AND (m.stock_lot_id IS NULL OR l.state = ?)
            ), 0) < part_types.minimum_stock', [LotState::Serviceable->value]);
    }

    public function isBelowMinimum(): bool
    {
        return $this->minimum_stock !== null
            && $this->availableStock() < $this->minimum_stock;
    }

    /**
     * When a lot received today would expire.
     */
    public function expiryFor(string $receivedAt): ?string
    {
        if ($this->shelf_life_days === null) {
            return null;
        }

        return Carbon::parse($receivedAt)
            ->addDays($this->shelf_life_days)
            ->toDateString();
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
            ->logOnly(['name', 'classification', 'requires_form_one', 'serial_tracked', 'shelf_life_days', 'minimum_stock', 'maximum_stock', 'storage_compartment_id', 'supplier_id', 'ipc_part_number'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges()
            ->useLogName('warehouse');
    }
}
