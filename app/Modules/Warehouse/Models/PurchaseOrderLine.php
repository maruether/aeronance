<?php

declare(strict_types=1);

namespace App\Modules\Warehouse\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Eine Position einer Bestellung: dieses Teil, diese Menge.
 */
final class PurchaseOrderLine extends Model
{
    use HasFactory;

    protected $fillable = [
        'purchase_order_id',
        'part_type_id',
        'quantity_ordered',
        'quantity_received',
        'note',
    ];

    protected function casts(): array
    {
        return [
            'quantity_ordered' => 'float',
            'quantity_received' => 'float',
        ];
    }

    /** @return BelongsTo<PurchaseOrder, $this> */
    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    /** @return BelongsTo<PartType, $this> */
    public function partType(): BelongsTo
    {
        return $this->belongsTo(PartType::class);
    }

    /**
     * Was noch fehlt.
     *
     * Nie negativ: Wer mehr liefert als bestellt, hat mehr geliefert -- das
     * ist kein Fehler, sondern kommt vor. Eine negative Restmenge waere
     * dagegen eine Zahl, die niemand lesen kann.
     */
    public function outstanding(): float
    {
        return max(0.0, $this->quantity_ordered - $this->quantity_received);
    }

    public function isComplete(): bool
    {
        return $this->quantity_received >= $this->quantity_ordered;
    }
}
