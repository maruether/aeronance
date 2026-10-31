<?php

declare(strict_types=1);

namespace App\Modules\Billing\Enums;

/**
 * Entwurf → gestellt → bezahlt, oder gestellt → storniert.
 *
 * Nur der Entwurf ist frei. Alles ab „gestellt" hat eine Nummer und ist
 * eingefroren; was sich noch ändert, ist der Zahlungsstand -- und der ist
 * eine Tatsache über Geld, das ankam, keine Änderung am Beleg.
 */
enum InvoiceState: string
{
    case Draft = 'draft';
    case Issued = 'issued';
    case Paid = 'paid';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return __('billing.state.'.$this->value);
    }

    public function color(): string
    {
        return match ($this) {
            self::Draft => 'gray',
            self::Issued => 'warning',
            self::Paid => 'success',
            self::Cancelled => 'danger',
        };
    }

    public function isFrozen(): bool
    {
        return $this !== self::Draft;
    }

    /** Offen heißt: gestellt und noch nicht vollständig bezahlt. */
    public function isOpen(): bool
    {
        return $this === self::Issued;
    }
}
