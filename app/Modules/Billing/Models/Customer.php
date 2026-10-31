<?php

declare(strict_types=1);

namespace App\Modules\Billing\Models;

use App\Modules\Billing\Enums\TaxTreatment;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * Ein Kunde -- wer die Rechnung bekommt.
 *
 * Wird nie gelöscht, nur inaktiv gesetzt: Eine Rechnung muss ihren Empfänger
 * acht Jahre lang nennen, und der Snapshot auf der Rechnung ersetzt den
 * Stammdatensatz nicht, wenn jemand nachfragt, wer das eigentlich war.
 *
 * Die nullable-Felder (Zahlungsziel, Rabatt, Sichtbarkeit, Steuer) bedeuten
 * „Vorgabe der Organisation"; die effective*()-Methoden lösen das auf.
 */
final class Customer extends Model
{
    use LogsActivity, SoftDeletes;

    protected $fillable = [
        'customer_number',
        'name',
        'address',
        'country',
        'vat_id',
        'tax_number',
        'contact_name',
        'email',
        'phone',
        'payment_terms_days',
        'default_discount_percent',
        'discount_visible',
        'tax_treatment',
        'buyer_reference',
        'holder_id',
        'is_active',
        'note',
    ];

    protected $attributes = [
        'country' => 'DE',
        'is_active' => true,
    ];

    protected function casts(): array
    {
        return [
            'payment_terms_days' => 'integer',
            'default_discount_percent' => 'decimal:2',
            'discount_visible' => 'boolean',
            'tax_treatment' => TaxTreatment::class,
            'is_active' => 'boolean',
        ];
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class)->orderByDesc('issued_at')->orderByDesc('id');
    }

    public function effectiveTaxTreatment(): TaxTreatment
    {
        return $this->tax_treatment ?? TaxTreatment::organisationDefault();
    }

    public function effectivePaymentTermsDays(): int
    {
        return $this->payment_terms_days ?? (int) config('aeronance.billing.payment_terms_days', 14);
    }

    public function effectiveDiscountVisible(): bool
    {
        return $this->discount_visible ?? (bool) config('aeronance.billing.discount_visible_default', true);
    }

    /** @param  Builder<self>  $query */
    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }

    public function label(): string
    {
        return $this->customer_number !== null
            ? sprintf('%s — %s', $this->customer_number, $this->name)
            : $this->name;
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly([
                'customer_number', 'name', 'address', 'country', 'vat_id', 'tax_number',
                'email', 'payment_terms_days', 'default_discount_percent', 'discount_visible',
                'tax_treatment', 'buyer_reference', 'holder_id', 'is_active',
            ])
            ->logOnlyDirty()
            ->dontLogEmptyChanges()
            ->useLogName('billing');
    }
}
