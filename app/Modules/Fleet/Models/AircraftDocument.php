<?php

declare(strict_types=1);

namespace App\Modules\Fleet\Models;

use App\Models\User;
use App\Modules\Fleet\Enums\DocumentType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

/**
 * A paper that hangs on an aircraft.
 *
 * The validity is optional and that is the design, not a gap: some aircraft owe
 * a weighing every four years and others only when something changes. A document
 * with no expiry does not expire -- it is not a document whose expiry somebody
 * forgot to type.
 */
final class AircraftDocument extends Model implements HasMedia
{
    use InteractsWithMedia;
    use SoftDeletes;

    /** Die Datei selbst -- Feldtest: "Dokumente können nicht hochgeladen werden". */
    public const FILE = 'file';

    public function registerMediaCollections(): void
    {
        /*
         * Bis hierher war der "Dokumenten-Upload" nur ein Metadatensatz --
         * Typ, Titel, Fristen -- ohne jede Datei. Fuer Fristen reicht das,
         * aber ein Waegebericht, den man nicht oeffnen kann, ist keiner.
         * Private Disk, Auslieferung nur ueber die auth-gepruefte Route
         * (fleet.document.file), wie bei jedem Nachweis.
         */
        $this->addMediaCollection(self::FILE)
            ->useDisk('documents')
            ->singleFile();
    }

    protected $fillable = [
        'aircraft_id',
        'type',
        'title',
        'reference',
        'issued_at',
        'valid_until',
        'issued_by',
        'note',
        'user_id',
    ];

    protected function casts(): array
    {
        return [
            'type' => DocumentType::class,
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

    /** Whether this one has a deadline at all. */
    public function expires(): bool
    {
        return $this->valid_until !== null;
    }

    public function isValid(?string $on = null): bool
    {
        return ! $this->expires()
            || $this->valid_until->toDateString() >= ($on ?? now()->toDateString());
    }

    public function daysRemaining(): ?int
    {
        return $this->expires()
            ? (int) now()->startOfDay()->diffInDays($this->valid_until->startOfDay(), false)
            : null;
    }

    /**
     * Documents that actually have a deadline.
     *
     * @param  Builder<AircraftDocument>  $query
     */
    public function scopeExpiring(Builder $query): void
    {
        $query->whereNotNull('valid_until');
    }
}
