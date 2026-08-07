<?php

declare(strict_types=1);

namespace App\Modules\Warehouse\Models;

use App\Modules\Warehouse\Enums\LotOrigin;
use App\Modules\Warehouse\Enums\LotState;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Collection;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * A lot -- the traceable unit.
 *
 * The central insight from the analysis (4.5): traceability hangs on the LOT,
 * not on the individual item. An EASA Form 1 covers a quantity, identified
 * either by a serial number or by a batch number -- blocks 9 and 10 of the form
 * itself. Four oil filters from one delivery are one lot; a tow release with a
 * serial number is a lot of one. Serialised parts are not a third mechanism,
 * they are the special case.
 *
 * The certificate's DETAILS are held here permanently. The file itself follows
 * the part into the aircraft records once the lot is used up (4.7 f) -- and
 * since one lot can end up in several aircraft, the hand-over is a reference
 * rather than a move. Keeping the details here costs a few columns and keeps
 * "this filter came from lot X with Form 1 number Y" readable in every one of
 * those chains.
 */
final class StockLot extends Model implements HasMedia
{
    use InteractsWithMedia, LogsActivity, SoftDeletes;

    /** Nachweisdokumente am Los -- siehe registerMediaCollections(). */
    public const DOCUMENTS = 'certificates';

    public const DOCUMENT_FORM_ONE = 'form_one';

    public const DOCUMENT_CERTIFICATE_OF_CONFORMITY = 'certificate_of_conformity';

    public const DOCUMENT_NONE = 'none';

    /**
     * Defaults the model carries itself, rather than only the table.
     *
     * Without this a freshly created lot reads back null for its origin until
     * it is reloaded -- the column default applies at insert, not to the object
     * in hand. Anything asking "where did this come from" right after creating
     * it would get no answer, which is the sort of trap that holds until the one
     * time it matters.
     */
    protected $attributes = [
        'origin' => 'supplier',
        'document_type' => self::DOCUMENT_NONE,
        'state' => 'serviceable',
    ];

    protected $fillable = [
        'part_type_id',
        'origin',
        'removed_from_aircraft',
        'removed_from_aircraft_type',
        'removed_at',
        'removal_reason',
        'repair_dispatch_id',
        'lot_number',
        'serial_number',
        'batch_number',
        'document_type',
        'document_reference',
        'document_issuer',
        'document_issuer_approval',
        'document_issued_at',
        'document_signatory',
        'supplier_id',
        'storage_compartment_id',
        'received_at',
        'expires_at',
        'state',
    ];

    protected function casts(): array
    {
        return [
            'state' => LotState::class,
            'origin' => LotOrigin::class,
            'received_at' => 'date',
            'removed_at' => 'date',
            'expires_at' => 'date',
            'document_issued_at' => 'date',
        ];
    }

    /** @return BelongsTo<PartType, $this> */
    public function partType(): BelongsTo
    {
        return $this->belongsTo(PartType::class);
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

    /** @return HasMany<StockMovement, $this> */
    public function movements(): HasMany
    {
        return $this->hasMany(StockMovement::class);
    }

    /** @return HasMany<LotStateChange, $this> */
    public function stateChanges(): HasMany
    {
        return $this->hasMany(LotStateChange::class)->orderByDesc('occurred_at');
    }

    /**
     * What is left of this lot.
     */
    public function remainingQuantity(): float
    {
        return (float) $this->movements()->sum('quantity');
    }

    /**
     * What was left of this lot on a given day. See PartType::stockAsOf().
     */
    public function remainingQuantityAsOf(string $date): float
    {
        return (float) $this->movements()
            ->whereDate('occurred_at', '<=', $date)
            ->sum('quantity');
    }

    public function isEmpty(): bool
    {
        return $this->remainingQuantity() <= 0;
    }

    /**
     * Whether stock may be taken from this lot.
     *
     * Two conditions, and both matter: the lot has to be in a usable state, and
     * it must not have expired. An expired lot is still on the shelf and still
     * on the books -- it simply must not be fitted.
     */
    public function isIssuable(): bool
    {
        return $this->state->allowsIssue()
            && ! $this->hasExpired()
            && ! $this->isEmpty();
    }

    public function hasExpired(?string $on = null): bool
    {
        if ($this->expires_at === null) {
            return false;
        }

        return $this->expires_at->toDateString() < ($on ?? now()->toDateString());
    }

    /**
     * Whether the certificate this lot needs is actually on file.
     *
     * A part whose evidence is missing counts as unserviceable under ML.A.504 --
     * the information needed to establish its airworthiness status is not there.
     * The interface uses this to explain why a lot cannot be released.
     */
    public function hasRequiredDocument(): bool
    {
        if (! $this->partType?->requires_form_one) {
            return true;
        }

        return $this->document_type === self::DOCUMENT_FORM_ONE
            && filled($this->document_reference);
    }

    /**
     * Lots stock may be taken from.
     *
     * Note the OR: a lot qualifies if it has no expiry at all, or if its expiry
     * has not passed. An AND here would ask for a date that is both absent and
     * in the future, which no lot can satisfy -- a test caught exactly that.
     *
     * @param  Builder<StockLot>  $query
     */
    public function scopeIssuable(Builder $query): void
    {
        $query->where('state', LotState::Serviceable->value)
            ->where(function (Builder $q): void {
                $q->whereNull('expires_at')
                    ->orWhereDate('expires_at', '>=', now()->toDateString());
            });
    }

    /**
     * First expired, first out.
     *
     * The order the interface suggests when something is taken from stock: the
     * lot that expires soonest goes first, so nothing quietly ages out on the
     * shelf. For quantity-tracked parts the storeman may deviate without giving
     * a reason -- traceability hangs on the certificate number recorded against
     * the lot, not on which lot was picked. For serialised parts nothing is
     * suggested at all: the serial number is asked for outright, because there
     * the choice IS the identification. See F26.
     *
     * @param  Builder<StockLot>  $query
     */
    public function scopeFefo(Builder $query): void
    {
        $query->orderByRaw('expires_at IS NULL')  // dated lots first
            ->orderBy('expires_at')
            ->orderBy('received_at');
    }

    /**
     * Whether this lot may be fitted to a given aircraft.
     *
     * The rule that follows from what a removal record actually proves. A part
     * taken out of an aircraft is backed by a determination that it was
     * serviceable when it came out -- and nothing more. Moving it to a DIFFERENT
     * aircraft needs a Form 1 from an organisation holding a component rating,
     * which most clubs do not have.
     *
     * So a removal lot without a Form 1 goes back where it came from, and
     * nowhere else. The software asserts nothing about legality here; it stops a
     * booking from making a claim its evidence does not support -- the same
     * shape of safeguard as refusing a stocktake surplus onto a certified lot.
     *
     * See docs/AUSGEBAUTE-TEILE.md.
     */
    public function mayBeFittedTo(?string $aircraft): bool
    {
        if (! ($this->origin?->mayCarryAircraftRestriction() ?? false)) {
            return true;
        }

        // Somebody with the standing to do so issued a certificate for it, so it
        // travels like any other certified part.
        if ($this->document_type === self::DOCUMENT_FORM_ONE && filled($this->document_reference)) {
            return true;
        }

        if ($this->removed_from_aircraft === null) {
            return true;
        }

        return $aircraft !== null
            && strcasecmp(trim($aircraft), trim($this->removed_from_aircraft)) === 0;
    }

    public function isRestrictedToItsAircraft(): bool
    {
        return ($this->origin?->mayCarryAircraftRestriction() ?? false)
            && $this->removed_from_aircraft !== null
            && ! ($this->document_type === self::DOCUMENT_FORM_ONE && filled($this->document_reference));
    }

    /** @return BelongsTo<RepairDispatch, $this> */
    public function repairDispatch(): BelongsTo
    {
        return $this->belongsTo(RepairDispatch::class);
    }

    /**
     * Repairs this lot is away at.
     *
     * @return HasMany<RepairDispatch, $this>
     */
    public function repairDispatches(): HasMany
    {
        return $this->hasMany(RepairDispatch::class)->orderByDesc('dispatched_at');
    }

    /** @param  Builder<StockLot>  $query */
    public function scopeExpiringWithin(Builder $query, int $days): void
    {
        $query->whereNotNull('expires_at')
            ->whereDate('expires_at', '<=', now()->addDays($days)->toDateString())
            ->whereDate('expires_at', '>=', now()->toDateString());
    }

    public function label(): string
    {
        if (filled($this->serial_number)) {
            return sprintf('%s (S/N %s)', $this->lot_number, $this->serial_number);
        }

        return $this->lot_number;
    }

    /**
     * The certificate documents that belong to this lot.
     *
     * On a private disk outside the web root, delivered only through an
     * authenticated route -- a file that can be fetched by guessing its address
     * is not a protected document. PDFs and scans only, and a size limit,
     * because an upload field that accepts anything is an upload field that
     * will eventually be handed something else.
     *
     * The certificate DETAILS live in columns of their own and stay here for
     * good. The file may leave when the lot is used up: it follows the part into
     * the aircraft records, and since one lot can end up in several aircraft
     * that hand-over is a reference rather than a move. See 4.7 f.
     */
    public function registerMediaCollections(): void
    {
        $this->addMediaCollection(self::DOCUMENTS)
            ->useDisk('documents')
            ->acceptsMimeTypes(['application/pdf', 'image/jpeg', 'image/png']);
    }

    /**
     * Whether the certificate is not merely recorded but actually on file.
     *
     * Distinct from hasRequiredDocument(), which asks whether the NUMBER was
     * entered. A number without the scan behind it is enough to work with but
     * not enough for an audit, and the difference is worth being able to see.
     */
    public function hasDocumentFile(): bool
    {
        return $this->getMedia(self::DOCUMENTS)->isNotEmpty();
    }

    /** @return Collection<int, Media> */
    public function documents(): Collection
    {
        return $this->getMedia(self::DOCUMENTS);
    }

    /**
     * Only where the lot is kept.
     *
     * Everything else about a lot already has a record of its own and a better
     * one: quantities are the ledger, conditions are lot_state_changes with the
     * credential frozen in. The compartment had neither, so a lot could be
     * moved across the store and nothing anywhere would say by whom -- while
     * the same change on a part type has been logged since the beginning.
     */
    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['storage_compartment_id'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges()
            ->useLogName('warehouse');
    }
}
