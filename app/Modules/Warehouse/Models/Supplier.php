<?php

declare(strict_types=1);

namespace App\Modules\Warehouse\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * Where a part can be obtained.
 *
 * Master data and nothing more. There is no ordering, no purchase history, no
 * supplier assessment -- that is merchandise management, which this module
 * deliberately is not (decision E6). The supplier answers one question: where
 * do we get this from.
 */
final class Supplier extends Model
{
    use LogsActivity, SoftDeletes;

    protected $fillable = [
        'name',
        'approval_number',
        'approval_scope',
        'approval_expires_at',
        'address',
        'contact',
        'description',
    ];

    protected function casts(): array
    {
        return ['approval_expires_at' => 'date'];
    }

    /**
     * Ein zugelassener Betrieb — oder die Schraubenhandlung.
     *
     * Entschieden an der Nummer und nicht an einem Haken: Ein Haken ohne Nummer
     * wäre eine Behauptung, und eine Nummer ohne Haken wäre widersprüchlich.
     */
    public function isApprovedOrganisation(): bool
    {
        return trim((string) $this->approval_number) !== '';
    }

    /**
     * Zulassung abgelaufen?
     *
     * Ein leeres Ablaufdatum heißt ausdrücklich „unbefristet" und nicht
     * „unbekannt" — viele Zulassungen laufen, bis die Aufsicht sie entzieht.
     * Wer es nicht weiß, trägt gar keine Nummer ein.
     */
    public function approvalHasLapsed(?Carbon $on = null): bool
    {
        if (! $this->isApprovedOrganisation() || $this->approval_expires_at === null) {
            return false;
        }

        return $this->approval_expires_at->lt(($on ?? now())->startOfDay());
    }

    /** Läuft demnächst ab — die Vorwarnung, damit ein Termin planbar bleibt. */
    public function approvalExpiresSoon(int $days = 60): bool
    {
        if (! $this->isApprovedOrganisation() || $this->approval_expires_at === null) {
            return false;
        }

        return ! $this->approvalHasLapsed()
            && $this->approval_expires_at->lte(now()->startOfDay()->addDays($days));
    }

    /** Für Anzeige und Nachweis: „Lange Aviation (EASA.145.1234)". */
    public function labelWithApproval(): string
    {
        return $this->isApprovedOrganisation()
            ? $this->name.' ('.$this->approval_number.')'
            : $this->name;
    }

    /** @return HasMany<PartType, $this> */
    public function partTypes(): HasMany
    {
        return $this->hasMany(PartType::class);
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
            ->logOnly(['name', 'approval_number', 'approval_scope', 'approval_expires_at'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges()
            ->useLogName('warehouse');
    }
}
