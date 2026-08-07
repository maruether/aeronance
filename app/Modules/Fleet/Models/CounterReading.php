<?php

declare(strict_types=1);

namespace App\Modules\Fleet\Models;

use App\Models\User;
use App\Modules\Fleet\Enums\CounterKind;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

/**
 * What an instrument read on a given day.
 *
 * Append-only, for the same reason as the stock ledger (E1): there is no
 * current-hours field to overwrite, so nothing can quietly replace the record of
 * what the aircraft had done a year ago. A wrong entry is corrected by a further
 * reading pointing at it, and both stay visible.
 *
 * ABSOLUTE values, not increments -- because that is what somebody reads off the
 * instrument and writes on the sheet. Asking for a difference would be asking
 * them to do arithmetic before typing, which is where mistakes come from.
 */
final class CounterReading extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'aircraft_id',
        'kind',
        'value',
        'read_at',
        'user_id',
        'corrects_reading_id',
        'note',
    ];

    protected function casts(): array
    {
        return [
            'kind' => CounterKind::class,
            'value' => 'decimal:2',
            'read_at' => 'date',
        ];
    }

    protected static function booted(): void
    {
        self::updating(function (): never {
            throw new RuntimeException(
                'A counter reading cannot be changed. Record a corrected reading instead.'
            );
        });

        self::deleting(function (): never {
            throw new RuntimeException(
                'Counter readings are not deleted -- they are the operating history.'
            );
        });
    }

    /** @return BelongsTo<Aircraft, $this> */
    public function aircraft(): BelongsTo
    {
        return $this->belongsTo(Aircraft::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<CounterReading, $this> */
    public function corrects(): BelongsTo
    {
        return $this->belongsTo(self::class, 'corrects_reading_id');
    }
}
