<?php

declare(strict_types=1);

namespace App\Modules\Warehouse\Models;

use App\Models\User;
use App\Modules\Warehouse\Enums\PurchaseOrderState;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * Eine Bestellung — was unterwegs ist, und ob es noch kommt.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * BESTELLT IST NICHT VORRAETIG. Die Mengen hier sind kein Bestand: Sie liegen
 * nicht im Regal, sie sind nicht verfügbar, und sie tauchen in keiner
 * Bestandsauswertung auf. Erst das Einbuchen erzeugt eine Bewegung — über
 * dieselbe Lageraktion wie jeder andere Wareneingang, also mit Los, Form 1
 * und Etikett.
 * ─────────────────────────────────────────────────────────────────────────────
 */
final class PurchaseOrder extends Model
{
    use HasFactory, LogsActivity, SoftDeletes;

    protected $fillable = [
        'order_number',
        'supplier_id',
        'ordered_at',
        'expected_at',
        'state',
        'cancelled_at',
        'cancel_reason',
        'note',
        'created_by_id',
        'reminded_at',
    ];

    protected $attributes = ['state' => 'open'];

    protected function casts(): array
    {
        return [
            'state' => PurchaseOrderState::class,
            'ordered_at' => 'date',
            'expected_at' => 'date',
            'cancelled_at' => 'date',
            'reminded_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Supplier, $this> */
    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    /** @return BelongsTo<User, $this> */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_id');
    }

    /** @return HasMany<PurchaseOrderLine, $this> */
    public function lines(): HasMany
    {
        return $this->hasMany(PurchaseOrderLine::class);
    }

    /**
     * Ist alles angekommen?
     *
     * „Erst wenn alles abgehakt ist, ist die Bestellung erledigt" -- und eine
     * Bestellung ganz ohne Positionen ist NICHT erledigt, sondern unfertig.
     * Sonst gaelte sie in dem Moment als abgeschlossen, in dem jemand sie
     * anlegt und beim Eintragen der Teile unterbrochen wird.
     */
    public function isComplete(): bool
    {
        $positionen = $this->lines()->get();

        if ($positionen->isEmpty()) {
            return false;
        }

        return $positionen->every(fn (PurchaseOrderLine $p): bool => $p->isComplete());
    }

    /**
     * Den Zustand aus den Positionen ableiten.
     *
     * ─────────────────────────────────────────────────────────────────────────
     * ABGELEITET UND NICHT VON HAND GESETZT. Ein Zustand, den jemand
     * unabhaengig von den Mengen umstellen kann, laeuft irgendwann auseinander
     * -- und dann steht "erledigt" an einer Bestellung, auf die noch jemand
     * wartet.
     *
     * Storniert bleibt storniert: Das ist die einzige Entscheidung, die ein
     * Mensch trifft, und keine Rechnung darf sie ueberschreiben.
     * ─────────────────────────────────────────────────────────────────────────
     */
    public function refreshState(): void
    {
        if ($this->state === PurchaseOrderState::Cancelled) {
            return;
        }

        $eingegangen = $this->lines()->get()
            ->contains(fn (PurchaseOrderLine $p): bool => $p->quantity_received > 0);

        $neu = match (true) {
            $this->isComplete() => PurchaseOrderState::Received,
            $eingegangen => PurchaseOrderState::PartiallyReceived,
            default => PurchaseOrderState::Open,
        };

        if ($neu !== $this->state) {
            $this->state = $neu;
            $this->save();
        }
    }

    /** @param  Builder<self>  $query */
    public function scopeOutstanding(Builder $query): void
    {
        $query->whereIn('state', [
            PurchaseOrderState::Open->value,
            PurchaseOrderState::PartiallyReceived->value,
        ]);
    }

    /**
     * Überfällig: zugesagt war früher, da ist aber noch nicht alles.
     *
     * Ohne zugesagtes Datum gibt es keine Überfälligkeit -- man kann nicht zu
     * spät sein, wenn nie ein Termin genannt wurde.
     *
     * @param  Builder<self>  $query
     */
    public function scopeOverdue(Builder $query): void
    {
        $query->outstanding()
            ->whereNotNull('expected_at')
            ->whereDate('expected_at', '<', now()->toDateString());
    }

    public function label(): string
    {
        return $this->order_number !== null && $this->order_number !== ''
            ? $this->order_number
            : __('warehouse.order.without_number', ['id' => $this->getKey()]);
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['order_number', 'supplier_id', 'ordered_at', 'expected_at', 'state', 'cancel_reason'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges()
            ->useLogName('warehouse');
    }
}
