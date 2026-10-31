<?php

declare(strict_types=1);

namespace App\Modules\Billing\Models;

use App\Models\User;
use App\Modules\Billing\Enums\InvoiceKind;
use App\Modules\Billing\Enums\InvoiceState;
use App\Modules\Billing\Enums\TaxTreatment;
use App\Modules\Billing\Support\Money;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use RuntimeException;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

/**
 * Eine Rechnung -- oder eine Gutschrift, siehe InvoiceKind.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * EINGEFROREN AB DEM STELLEN. Das Modell lässt an einer gestellten Rechnung
 * nur noch den Zahlungsstand und die Stornierung zu (MUTABLE_WHEN_FROZEN);
 * alles andere wirft. Das ist dieselbe Regel wie bei ReleaseToService und
 * WorkOrder, und sie sitzt im Modell, weil die Oberfläche nicht der einzige
 * Weg in die Datenbank ist -- ein Import oder eine Konsolensitzung fragt
 * nicht nach.
 *
 * Gelöscht wird nur ein Entwurf. Eine gestellte Rechnung hat eine Nummer aus
 * einem lückenlosen Kreis; sie verschwindet nicht, sie wird storniert.
 * ─────────────────────────────────────────────────────────────────────────────
 */
final class Invoice extends Model implements HasMedia
{
    use InteractsWithMedia, LogsActivity, SoftDeletes;

    /** Die archivierten Dokumente: PDF und XRechnung, beim Stellen erzeugt. */
    public const DOCUMENTS = 'documents';

    /** Was sich an einer gestellten Rechnung noch ändern darf. */
    private const MUTABLE_WHEN_FROZEN = [
        'state', 'paid_amount', 'paid_at', 'sent_at',
        'cancelled_at', 'cancellation_reason', 'cancelled_by_id',
        'updated_at',
    ];

    protected $fillable = [
        'kind',
        'state',
        'number',
        'customer_id',
        'customer_name',
        'customer_address',
        'customer_country',
        'customer_vat_id',
        'customer_number',
        'buyer_reference',
        'work_order_id',
        'work_order_number',
        'aircraft_registration',
        'service_from',
        'service_to',
        'issued_at',
        'due_at',
        'tax_treatment',
        'discount_percent',
        'discount_visible',
        'net_total',
        'tax_total',
        'gross_total',
        'paid_amount',
        'paid_at',
        'sent_at',
        'issued_by',
        'issued_by_name',
        'corrects_invoice_id',
        'cancelled_at',
        'cancellation_reason',
        'cancelled_by_id',
        'intro',
        'note',
        'internal_note',
    ];

    protected $attributes = [
        'kind' => 'invoice',
        'state' => 'draft',
        'customer_country' => 'DE',
        'tax_treatment' => 'standard',
        'discount_visible' => true,
        'net_total' => 0,
        'tax_total' => 0,
        'gross_total' => 0,
        'paid_amount' => 0,
    ];

    protected function casts(): array
    {
        return [
            'kind' => InvoiceKind::class,
            'state' => InvoiceState::class,
            'tax_treatment' => TaxTreatment::class,
            'service_from' => 'date',
            'service_to' => 'date',
            'issued_at' => 'date',
            'due_at' => 'date',
            'paid_at' => 'date',
            'sent_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'discount_percent' => 'decimal:2',
            'discount_visible' => 'boolean',
            'net_total' => 'decimal:2',
            'tax_total' => 'decimal:2',
            'gross_total' => 'decimal:2',
            'paid_amount' => 'decimal:2',
        ];
    }

    protected static function booted(): void
    {
        self::updating(function (self $invoice): void {
            $wasFrozen = InvoiceState::tryFrom((string) $invoice->getRawOriginal('state'))?->isFrozen() ?? false;

            if (! $wasFrozen) {
                return;
            }

            $changed = array_diff(array_keys($invoice->getDirty()), self::MUTABLE_WHEN_FROZEN);

            if ($changed !== []) {
                throw new RuntimeException(sprintf(
                    'Invoice %s has been issued and is frozen; %s cannot change. A correction '
                    .'is a credit note referencing this invoice.',
                    $invoice->number ?? '#'.$invoice->getKey(),
                    implode(', ', $changed),
                ));
            }
        });

        self::deleting(function (self $invoice): void {
            if (InvoiceState::tryFrom((string) $invoice->getRawOriginal('state'))?->isFrozen() ?? false) {
                throw new RuntimeException(
                    'An issued invoice is not deleted. Its number is part of an unbroken '
                    .'sequence; it is cancelled with a credit note instead.'
                );
            }
        });
    }

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection(self::DOCUMENTS)->useDisk('documents');
    }

    // ── Beziehungen ──────────────────────────────────────────────────────────

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class)->withTrashed();
    }

    public function lines(): HasMany
    {
        return $this->hasMany(InvoiceLine::class)->orderBy('position')->orderBy('id');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(InvoicePayment::class)->orderBy('paid_on')->orderBy('id');
    }

    public function issuedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'issued_by');
    }

    /** Das Original, wenn dies eine Gutschrift ist. */
    public function corrects(): BelongsTo
    {
        return $this->belongsTo(self::class, 'corrects_invoice_id');
    }

    /** Die Gutschrift, die dieses Original storniert hat. */
    public function correctedBy(): HasOne
    {
        return $this->hasOne(self::class, 'corrects_invoice_id');
    }

    // ── Zustand ──────────────────────────────────────────────────────────────

    public function isDraft(): bool
    {
        return $this->state === InvoiceState::Draft;
    }

    public function isFrozen(): bool
    {
        return $this->state->isFrozen();
    }

    public function isIssued(): bool
    {
        return $this->state === InvoiceState::Issued;
    }

    public function isPaid(): bool
    {
        return $this->state === InvoiceState::Paid;
    }

    public function isCancelled(): bool
    {
        return $this->state === InvoiceState::Cancelled;
    }

    public function isCreditNote(): bool
    {
        return $this->kind === InvoiceKind::CreditNote;
    }

    /** Was noch offen ist -- brutto minus Zahlungen. */
    public function outstandingAmount(): string
    {
        return Money::sub((string) $this->gross_total, (string) $this->paid_amount);
    }

    public function isOverdue(): bool
    {
        return $this->isIssued()
            && $this->due_at !== null
            && $this->due_at->lt(now()->startOfDay())
            && ! Money::isZero($this->outstandingAmount());
    }

    /** Die Summe der Positionen vor dem Gesamtrabatt. */
    public function subtotal(): string
    {
        return $this->lines->reduce(
            static fn (string $sum, InvoiceLine $line): string => Money::add($sum, (string) $line->net_amount),
            '0.00',
        );
    }

    public function discountAmount(): string
    {
        return Money::sub($this->subtotal(), (string) $this->net_total);
    }

    public function hasPriceGaps(): bool
    {
        return $this->lines->contains(static fn (InvoiceLine $line): bool => $line->needs_price);
    }

    // ── Scopes ───────────────────────────────────────────────────────────────

    /** @param  Builder<self>  $query */
    public function scopeOpen(Builder $query): void
    {
        $query->where('state', InvoiceState::Issued->value);
    }

    /** @param  Builder<self>  $query */
    public function scopeOverdue(Builder $query): void
    {
        $query->open()->whereDate('due_at', '<', now()->toDateString());
    }

    public function label(): string
    {
        return $this->number ?? __('billing.invoice.draft_label', ['id' => $this->getKey()]);
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly([
                'kind', 'state', 'number', 'customer_id', 'customer_name', 'work_order_number',
                'issued_at', 'due_at', 'tax_treatment', 'discount_percent', 'discount_visible',
                'net_total', 'tax_total', 'gross_total', 'paid_amount', 'paid_at', 'sent_at',
                'corrects_invoice_id', 'cancelled_at', 'cancellation_reason',
            ])
            ->logOnlyDirty()
            ->dontLogEmptyChanges()
            ->useLogName('billing');
    }
}
