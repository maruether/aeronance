<?php

declare(strict_types=1);

namespace App\Modules\Warehouse\Models;

use App\Models\User;
use App\Modules\Warehouse\Enums\LotState;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

/**
 * One change in the condition of a lot -- append-only.
 *
 * Two kinds of entry, and the difference is decision E7:
 *
 *  - Precautionary. A lot set aside because its paperwork has not arrived.
 *    Anyone may do it, it is reversible, and a reference to the person suffices.
 *  - Determined. Unserviceable, unsalvageable, or fit for service again. A
 *    qualified act under E8, so the name and the credential relied upon are
 *    COPIED here. A determination has to stay readable years later, after the
 *    account has been pseudonymised or the licence renewed under a new number --
 *    which is exactly what a foreign key would not survive.
 */
final class LotStateChange extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'stock_lot_id',
        'from_state',
        'to_state',
        'reason',
        'quarantine_tag',
        'tag_printed_at',
        'aircraft_reference',
        'aircraft_type',
        'user_id',
        'determined_by_name',
        'qualification_type',
        'qualification_reference',
        'qualification_category',
        'qualification_valid_until',
        'occurred_at',
    ];

    protected function casts(): array
    {
        return [
            'from_state' => LotState::class,
            'to_state' => LotState::class,
            'qualification_valid_until' => 'date',
            'occurred_at' => 'datetime',
            'tag_printed_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        self::updating(function (): never {
            throw new RuntimeException(
                'A recorded determination cannot be changed. Record a new one instead.'
            );
        });

        self::deleting(function (): never {
            throw new RuntimeException('A recorded determination cannot be deleted.');
        });
    }

    /** @return BelongsTo<StockLot, $this> */
    public function lot(): BelongsTo
    {
        return $this->belongsTo(StockLot::class, 'stock_lot_id');
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Whether this entry records a qualified determination.
     */
    public function isDetermination(): bool
    {
        return $this->qualification_type !== null;
    }

    /**
     * Who answered for this, as recorded at the time.
     *
     * Reads from the copy, never from the account: the point of keeping it is
     * that it stays true when the account no longer does.
     */
    /**
     * The colour the printed tag carries.
     *
     * Driven by the record rather than chosen by the person at the printer, so
     * the wrong colour cannot end up on a part by mistake. Which colour belongs
     * to which state is configuration, not code -- no rule prescribes it, and a
     * club used to a different scheme should not have to patch a model.
     */
    public function tagColour(): string
    {
        return config('aeronance.quarantine_tag.colours.'.$this->to_state->value, '#5a5a5a');
    }

    public function needsTag(): bool
    {
        return $this->quarantine_tag !== null;
    }

    public function certifierDescription(): ?string
    {
        if (! $this->isDetermination()) {
            return null;
        }

        return sprintf(
            '%s (%s %s)',
            $this->determined_by_name,
            __('qualifications.type.'.$this->qualification_type),
            $this->qualification_reference ?? '—',
        );
    }
}
