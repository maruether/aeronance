<?php

declare(strict_types=1);

namespace App\Modules\Fleet\Models;

use App\Modules\Fleet\Enums\ComponentKind;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

/**
 * One component type and its paperwork.
 *
 * Vorgabe: "auch die haben tms." The Tost coupling is the case that proves it --
 * its own Kennblatt, its own technical notes, and "2 Jahre oder 500 Starts".
 */
final class ComponentType extends Model implements HasMedia
{
    use InteractsWithMedia, LogsActivity, SoftDeletes;

    public const DATA_SHEET = 'data_sheet';

    protected $fillable = [
        'designation',
        'manufacturer',
        'kind',
        'type_certificate',
        'certificate_authority',
        'data_sheet_url',
        'directive_overview_url',
        'part_number',
        // Lose Referenz auf den Bauteiltyp des Lagers -- die Kopplung aus dem
        // Feldtest ("eine schleppkupplung kann beides sein"). Nullable und
        // ohne Fremdschluessel: Modulgrenze, siehe Migration.
        'part_type_id',
        'source',
        'note',
    ];

    protected function casts(): array
    {
        return [
            'kind' => ComponentKind::class,
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['designation', 'manufacturer', 'kind', 'type_certificate', 'part_number', 'part_type_id'])
            ->logOnlyDirty()
            ->useLogName('fleet');
    }

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection(self::DATA_SHEET)
            ->acceptsMimeTypes(['application/pdf']);
    }

    /** @return HasMany<Installation, $this> */
    public function installations(): HasMany
    {
        return $this->hasMany(Installation::class, 'component_type_id');
    }

    /**
     * Die Muster-Laufzeiten -- Vorlagen, die der Einbau aus dem Lager KOPIERT.
     *
     * @return HasMany<ComponentTypeLimit, $this>
     */
    public function limits(): HasMany
    {
        return $this->hasMany(ComponentTypeLimit::class);
    }

    /**
     * How many are fitted right now.
     *
     * Removed installations are excluded: the interesting number is what is on
     * aircraft today, and a type fitted twenty times over thirty years would
     * otherwise read as if it were everywhere.
     */
    public function fittedCount(): int
    {
        return $this->installations()->whereNull('removed_at')->count();
    }

    public function hasDataSheet(): bool
    {
        return filled($this->data_sheet_url) || $this->getMedia(self::DATA_SHEET)->isNotEmpty();
    }

    public function label(): string
    {
        $parts = array_filter([
            $this->designation,
            filled($this->part_number) ? 'P/N '.$this->part_number : null,
            filled($this->type_certificate) ? $this->type_certificate : null,
        ]);

        return implode(' · ', $parts);
    }

    /** @param  Builder<ComponentType>  $query */
    public function scopeOfKind(Builder $query, ComponentKind $kind): void
    {
        $query->where('kind', $kind->value);
    }

    /**
     * Whether a free-text part name means this component.
     *
     * The loose comparison, kept here for the same reason AircraftType keeps its
     * own: it is a statement about component NAMES, which is this module's
     * business. Matched on designation OR part number, because a directive names
     * one or the other and a parts list and a person use different words.
     */
    public function matchesName(?string $name, ?string $partNumber = null): bool
    {
        if (filled($partNumber) && filled($this->part_number)
            && strcasecmp(trim($partNumber), trim($this->part_number)) === 0) {
            return true;
        }

        if ($name === null || trim($name) === '') {
            return false;
        }

        $a = mb_strtolower(trim($this->designation));
        $b = mb_strtolower(trim($name));

        return $a === $b || str_contains($b, $a) || str_contains($a, $b);
    }
}
