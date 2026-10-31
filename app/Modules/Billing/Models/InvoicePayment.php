<?php

declare(strict_types=1);

namespace App\Modules\Billing\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * Ein Zahlungseingang. Append-only, wie eine Bestandsbewegung (E1):
 * Eine falsch erfasste Zahlung wird durch eine Gegenbuchung mit negativem
 * Betrag berichtigt, nicht durch Editieren.
 */
final class InvoicePayment extends Model
{
    use LogsActivity;

    protected $fillable = [
        'invoice_id',
        'amount',
        'paid_on',
        'method',
        'reference',
        'note',
        'recorded_by_id',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'paid_on' => 'date',
        ];
    }

    protected static function booted(): void
    {
        self::updating(function (): never {
            throw new RuntimeException(
                'A payment is not edited. A wrong entry is corrected by a counter-entry '
                .'with a negative amount, so that both stay on record.'
            );
        });

        self::deleting(function (): never {
            throw new RuntimeException('A payment is not deleted; see updating.');
        });
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by_id');
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['invoice_id', 'amount', 'paid_on', 'method', 'reference'])
            ->useLogName('billing');
    }
}
