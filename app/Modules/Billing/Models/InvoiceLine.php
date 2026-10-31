<?php

declare(strict_types=1);

namespace App\Modules\Billing\Models;

use App\Modules\Billing\Enums\LineOrigin;
use App\Modules\Billing\Support\Money;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

/**
 * Eine Rechnungsposition -- nach dem Stellen ein Snapshot.
 *
 * Die Beträge auf der Zeile rechnet InvoiceCalculator; das Modell hält sie
 * nur. Was es selbst durchsetzt, ist das Einfrieren: Eine Zeile einer
 * gestellten Rechnung lässt sich weder ändern noch löschen.
 */
final class InvoiceLine extends Model
{
    protected $fillable = [
        'invoice_id',
        'position',
        'origin',
        'source_reference',
        'description',
        'quantity',
        'unit',
        'list_unit_price',
        'discount_percent',
        'discount_visible',
        'unit_price',
        'tax_rate',
        'net_amount',
        'tax_amount',
        'gross_amount',
        'needs_price',
    ];

    protected $attributes = [
        'origin' => 'manual',
        'quantity' => 1,
        'unit' => 'Stk',
        'discount_visible' => true,
        'needs_price' => false,
    ];

    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'origin' => LineOrigin::class,
            'quantity' => 'decimal:3',
            'list_unit_price' => 'decimal:2',
            'discount_percent' => 'decimal:2',
            'discount_visible' => 'boolean',
            'unit_price' => 'decimal:2',
            'tax_rate' => 'decimal:2',
            'net_amount' => 'decimal:2',
            'tax_amount' => 'decimal:2',
            'gross_amount' => 'decimal:2',
            'needs_price' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        $frozen = static function (self $line): void {
            $invoice = $line->invoice()->first();

            if ($invoice !== null && $invoice->isFrozen()) {
                throw new RuntimeException(
                    'This line belongs to an issued invoice and cannot change. A correction '
                    .'is a credit note.'
                );
            }
        };

        self::creating($frozen);
        self::updating($frozen);
        self::deleting($frozen);
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    /** Der Rabattbetrag je Einheit -- was zwischen Listen- und Nettopreis liegt. */
    public function discountPerUnit(): string
    {
        return Money::sub((string) $this->list_unit_price, (string) $this->unit_price);
    }

    public function hasDiscount(): bool
    {
        return ! Money::isZero($this->discountPerUnit());
    }
}
