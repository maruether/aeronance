<?php

declare(strict_types=1);

namespace App\Core\Models;

use App\Core\Access\WorkSubject;
use App\Core\Enums\Part66Category;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\AsEnumCollection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Collection;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

/**
 * An external credential a person holds.
 *
 * This is NOT a role. A role is granted internally and says what someone may do
 * in the software; a qualification is evidence from outside that someone may
 * answer for a thing in the real world. It expires, it has categories, and one
 * kind of it is valid for a single aircraft rather than in general.
 *
 * That last property is why this cannot be modelled as another role: the same
 * person may be entered as pilot-owner for one aircraft and not for another,
 * and "person has role X" cannot express that. See decision E8.
 *
 * The scope column deliberately holds a plain identifier and no foreign key:
 * aircraft belong to the fleet module, which may not be installed. See D4.
 */
final class Qualification extends Model implements HasMedia
{
    use InteractsWithMedia, LogsActivity, SoftDeletes;

    /**
     * Die Urkunde selbst.
     *
     * Auf der privaten Dokumentenablage wie Form 1 und Freigaben — sie enthält
     * personenbezogene Daten und hat im Webroot nichts verloren.
     */
    public const DOCUMENTS = 'certificates';

    public const TYPE_PART66 = 'part66_licence';

    public const TYPE_PILOT_OWNER = 'pilot_owner_authorisation';

    /**
     * Ein Schulungsnachweis.
     *
     * ─────────────────────────────────────────────────────────────────────────
     * BEWUSST WEIT GEFASST. Was hier hineingehört, ist alles, was jemand
     * nachweisen kann und was keine Befugnis ist: Musterschulungen (Rotax,
     * Jabiru, Solo), Verfahren (Kleben und Faserverbund, Schweißen,
     * zerstörungsfreie Prüfung), Ausrüstung (Rettungsgeräte packen,
     * Sauerstoffanlagen, Lithiumbatterien), Avionikeinbau — und im
     * 145-Umfeld Human Factors, EWIS, Fuel Tank Safety.
     *
     * Deshalb gibt es weder eine Auswahlliste noch Unterarten: Jede Liste, die
     * ich hier schriebe, wäre nach dem dritten Verein unvollständig, und die
     * fehlende Zeile hieße dann „das können wir nicht führen".
     *
     * ─────────────────────────────────────────────────────────────────────────
     * ER VERLEIHT AUSDRÜCKLICH KEINE BEFUGNIS, und das ist der ganze Grund,
     * warum er hier stehen darf.
     *
     * `Authority` entscheidet über eine Positivliste je Berechtigung, in der
     * nur `TYPE_PART66` und `TYPE_PILOT_OWNER` vorkommen. Ein Zertifikat ist
     * damit von sich aus wirkungslos — es sagt „diese Person wurde geschult",
     * nicht „diese Person darf freigeben". Ein eigener Test hält das fest,
     * denn genau hier wäre die stille Rechteausweitung, wenn jemand die Liste
     * später um eine Zeile ergänzt.
     *
     * Am MENSCHEN und nicht am Gerät: Wer auf Rotax geschult ist, bleibt es,
     * auch wenn der Verein den Motor verkauft.
     *
     * Getragen wird der Nachweis von drei Feldern, die eine Lizenz nicht
     * braucht: `subject` (worum es ging), `issuer` (bei wem) und der
     * angehängten Urkunde. `reference` bleibt die Nummer — sie mit dem
     * Gegenstand zu füllen war der Fehler der ersten Fassung.
     */
    public const TYPE_TRAINING = 'training_certificate';

    protected $fillable = [
        'user_id',
        'type',
        'subject',
        'reference',
        'issuer',
        'category',
        'categories',
        'no_maintenance_exceeding_ma803b',
        'scope',
        'valid_from',
        'valid_until',
        'note',
    ];

    protected function casts(): array
    {
        return [
            'valid_from' => 'date',
            'valid_until' => 'date',
            'categories' => AsEnumCollection::of(Part66Category::class),
            'no_maintenance_exceeding_ma803b' => 'boolean',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Valid on a given day -- today unless asked otherwise.
     *
     * An open valid_until means "no end date known", which is how a licence
     * without an expiry is recorded. An open valid_from means it has always
     * applied as far as this system is concerned.
     *
     * @param  Builder<Qualification>  $query
     */
    public function scopeValid(Builder $query, ?string $on = null): void
    {
        $date = $on ?? now()->toDateString();

        $query->whereDate('valid_from', '<=', $date)
            ->where(function (Builder $q) use ($date): void {
                $q->whereNull('valid_until')->orWhereDate('valid_until', '>=', $date);
            });
    }

    /**
     * @param  Builder<Qualification>  $query
     */
    public function scopeOfType(Builder $query, string $type): void
    {
        $query->where('type', $type);
    }

    /**
     * Pilot-owner authorisations for one aircraft.
     *
     * @param  Builder<Qualification>  $query
     */
    public function scopeForScope(Builder $query, string $scope): void
    {
        $query->where('scope', $scope);
    }

    public function isValidOn(?string $date = null): bool
    {
        $date = $date ?? now()->toDateString();

        if ($this->valid_from !== null && $this->valid_from->toDateString() > $date) {
            return false;
        }

        return $this->valid_until === null || $this->valid_until->toDateString() >= $date;
    }

    /**
     * The exclusions endorsed on this licence.
     *
     * @return HasMany<QualificationLimitation, $this>
     */
    public function limitations(): HasMany
    {
        return $this->hasMany(QualificationLimitation::class);
    }

    /**
     * The categories, as a plain list.
     *
     * @return Collection<int, Part66Category>
     */
    public function categoryList(): Collection
    {
        /** @var Collection<int, Part66Category>|null $categories */
        $categories = $this->categories;

        return $categories ?? collect();
    }

    public function hasCategory(Part66Category $category): bool
    {
        return $this->categoryList()->contains($category);
    }

    /**
     * The categories in one line, for a certificate and for a list.
     *
     * Falls back to whatever was typed into the old free-text field, which is
     * still the right answer for anything the enum does not know -- a B2L system
     * rating, a licence from outside the EU. Capped at the width of the snapshot
     * columns, because a truncated certificate field is a corrupted one.
     */
    public function categoryLabel(): ?string
    {
        $categories = $this->categoryList();

        if ($categories->isEmpty()) {
            return $this->category;
        }

        $label = $categories->map(fn (Part66Category $c): string => $c->value)->implode(', ');

        return mb_substr($label, 0, 64);
    }

    /**
     * The exclusions in one line, for the certificate.
     *
     * Frozen along with the rest (E7) so a record shows the limitation the
     * signature was given under -- including the ones nothing can check
     * automatically. Point 66.A.50 lets limitations be lifted later; without
     * this, an old release would afterwards read as if it never had one.
     */
    public function limitationLabel(): ?string
    {
        $limitations = $this->relationLoaded('limitations')
            ? $this->limitations
            : $this->limitations()->get();

        if ($limitations->isEmpty() && ! $this->no_maintenance_exceeding_ma803b) {
            return null;
        }

        $parts = $limitations
            ->map(fn (QualificationLimitation $l): string => $l->label())
            ->filter()
            ->values()
            ->all();

        if ($this->no_maintenance_exceeding_ma803b) {
            // The licence wording, not a translation of it: this is certificate
            // content and it has to read the way the document reads.
            array_unshift($parts, 'no maintenance exceeding MA.803(b)');
        }

        return mb_substr(implode('; ', $parts), 0, 255);
    }

    /**
     * The exclusion that stands in the way of this work, if any.
     *
     * Licence-wide, deliberately: it is asked of the qualification and never of
     * one of its categories. Vorgabe: "Die Zellentypen können eingeschränkt
     * werden und zählen über die gesamte Lizenz."
     */
    public function blockedBy(WorkSubject $subject): ?QualificationLimitation
    {
        foreach ($this->limitations()->get() as $limitation) {
            if ($limitation->blocks($subject)) {
                return $limitation;
            }
        }

        return null;
    }

    /**
     * Whether the licence carries the "no maintenance exceeding MA.803(b)"
     * entry -- the cap that leaves the privilege to sign for other people's work
     * intact but reduces its SCOPE to pilot-owner maintenance.
     */
    public function isCappedToPilotOwnerScope(): bool
    {
        return (bool) $this->no_maintenance_exceeding_ma803b;
    }

    /**
     * What gets frozen into a record when this qualification is relied upon.
     *
     * Certificate content is copied, never referenced: a release must still say
     * who signed it and under which licence, even after the account has been
     * pseudonymised or the licence renewed under a new number. See E3a and E7.
     *
     * @return array<string, string|null>
     */
    public function toSnapshot(): array
    {
        return [
            'qualification_type' => $this->type,
            'qualification_reference' => $this->reference,
            'qualification_category' => $this->categoryLabel(),
            'qualification_limitations' => $this->limitationLabel(),
            'qualification_valid_until' => $this->valid_until?->toDateString(),
        ];
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
            ->logOnly([
                'type', 'reference', 'category', 'categories',
                'no_maintenance_exceeding_ma803b', 'scope', 'valid_from', 'valid_until',
            ])
            ->logOnlyDirty()
            ->dontLogEmptyChanges()
            ->useLogName('core');
    }

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection(self::DOCUMENTS)
            ->useDisk('documents')
            ->acceptsMimeTypes(['application/pdf', 'image/jpeg', 'image/png']);
    }
}
