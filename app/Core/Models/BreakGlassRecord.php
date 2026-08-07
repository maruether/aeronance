<?php

declare(strict_types=1);

namespace App\Core\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * One use of the emergency access -- decision E2.
 *
 * Write-once by intention: there is no update path and no delete path. A record
 * that could be tidied up afterwards would prove nothing, and this is precisely
 * the record an audit would ask for.
 */
final class BreakGlassRecord extends Model
{
    protected $fillable = [
        'target_email',
        'target_user_id',
        'shell_user',
        'origin_ip',
        'hostname',
        'reason',
        'granted_at',
        'expires_at',
        'revoked_at',
    ];

    protected function casts(): array
    {
        return [
            'granted_at' => 'datetime',
            'expires_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    /**
     * Grants that are still in force.
     *
     * @param  Builder<BreakGlassRecord>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->whereNull('revoked_at')
            ->where(function (Builder $q): void {
                $q->whereNull('expires_at')->orWhere('expires_at', '>', now());
            });
    }

    public function isActive(): bool
    {
        if ($this->revoked_at !== null) {
            return false;
        }

        return $this->expires_at === null || $this->expires_at->isFuture();
    }
}
