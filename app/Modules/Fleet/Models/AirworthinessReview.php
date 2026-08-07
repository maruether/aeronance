<?php

declare(strict_types=1);

namespace App\Modules\Fleet\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An airworthiness review certificate (ARC) and when it runs out.
 *
 * The single most asked question of any fleet list -- "wann ist die Nachprüfung
 * fällig" -- and the reason the requirement was deadlines in this slice rather than
 * the next. Without it the module is a database that cannot answer the thing it
 * exists for.
 *
 * Issuer and approval number are copied, not referenced: they are certificate
 * content (E7).
 */
final class AirworthinessReview extends Model
{
    protected $fillable = [
        'aircraft_id',
        'certificate_reference',
        'issued_at',
        'valid_until',
        'issued_by_name',
        'issued_by_approval',
        'user_id',
        'note',
    ];

    protected function casts(): array
    {
        return [
            'issued_at' => 'date',
            'valid_until' => 'date',
        ];
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

    public function isValid(?string $on = null): bool
    {
        return $this->valid_until->toDateString() >= ($on ?? now()->toDateString());
    }

    public function daysRemaining(): int
    {
        return (int) now()->startOfDay()->diffInDays($this->valid_until->startOfDay(), false);
    }
}
