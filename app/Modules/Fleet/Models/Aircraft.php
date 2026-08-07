<?php

declare(strict_types=1);

namespace App\Modules\Fleet\Models;

use App\Core\Access\WorkSubject;
use App\Core\Enums\MaintenanceSubject;
use App\Modules\Fleet\Enums\AirframeConstruction;
use App\Modules\Fleet\Enums\CounterKind;
use App\Modules\Fleet\Enums\Propulsion;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\AsEnumCollection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Collection;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * One aircraft.
 *
 * The registration is the identity everybody uses, so it is unique and it is
 * what the warehouse has been writing down as free text all along. Its FORMAT is
 * instance configuration and never hardcoded -- D-KABC, HB-, OE- and F- all
 * exist, and a club abroad is not a special case in the code.
 */
final class Aircraft extends Model
{
    use LogsActivity, SoftDeletes;

    protected $table = 'aircraft';

    protected $attributes = ['is_active' => true];

    protected $fillable = [
        'registration',
        'model',
        'manufacturer',
        'serial_number',
        'year_built',
        'airframe_constructions',
        'propulsion',
        'holder_id',
        'aircraft_type_id',
        'optional_counters',
        'is_active',
        'in_service_since',
        'onboarded_at',
        'note',
    ];

    protected function casts(): array
    {
        return [
            'optional_counters' => 'array',
            'airframe_constructions' => AsEnumCollection::of(AirframeConstruction::class),
            'propulsion' => Propulsion::class,
            'is_active' => 'boolean',
            'in_service_since' => 'date',
            'onboarded_at' => 'date',
        ];
    }

    /**
     * This aircraft in the words a licence limitation uses.
     *
     * The bridge between "was ist das für ein Flugzeug" and "wofür ist diese
     * Lizenz eingeschränkt". Built here rather than in the core, which never
     * sees an aircraft, and handed to Authority by whoever is doing the
     * certifying. See App\Core\Access\WorkSubject.
     *
     * An unrecorded airframe stays unrecorded -- it is NOT reported as "made of
     * nothing", because the difference decides whether a restricted licence may
     * sign.
     */
    public function workSubject(): WorkSubject
    {
        $constructions = $this->airframe_constructions;

        $airframe = $constructions === null || $constructions->isEmpty()
            ? null
            : $constructions
                ->map(fn (AirframeConstruction $c): MaintenanceSubject => $c->subject())
                ->values()
                ->all();

        $propulsion = match (true) {
            $this->propulsion === null => null,
            $this->propulsion->subject() === null => [],
            default => [$this->propulsion->subject()],
        };

        return new WorkSubject($airframe, $propulsion);
    }

    /**
     * The catalogued type, if this aircraft has one.
     *
     * Optional on purpose: `model` stays as free text, because a club may fly
     * something nobody has catalogued and typing a name has to keep working. The
     * type is the better answer where it exists, not the only one.
     */
    public function aircraftType(): BelongsTo
    {
        return $this->belongsTo(AircraftType::class, 'aircraft_type_id');
    }

    /** @return BelongsTo<Holder, $this> */
    public function holder(): BelongsTo
    {
        return $this->belongsTo(Holder::class);
    }

    /** @return HasMany<CounterReading, $this> */
    public function readings(): HasMany
    {
        return $this->hasMany(CounterReading::class);
    }

    /** @return HasMany<Installation, $this> */
    public function installations(): HasMany
    {
        return $this->hasMany(Installation::class);
    }

    /** @return HasMany<PilotOwnerAuthorisation, $this> */
    public function pilotOwnerAuthorisations(): HasMany
    {
        return $this->hasMany(PilotOwnerAuthorisation::class);
    }

    /** @return HasMany<AirworthinessReview, $this> */
    public function airworthinessReviews(): HasMany
    {
        return $this->hasMany(AirworthinessReview::class)->orderByDesc('valid_until');
    }

    /** @return HasMany<Weighing, $this> */
    public function weighings(): HasMany
    {
        return $this->hasMany(Weighing::class)->orderByDesc('weighed_at');
    }

    public function currentWeighing(): ?Weighing
    {
        return $this->weighings()->first();
    }

    /** @return HasMany<ExternalWorkOrder, $this> */
    public function externalWorkOrders(): HasMany
    {
        return $this->hasMany(ExternalWorkOrder::class)->orderByDesc('sent_at');
    }

    /** @return HasMany<AircraftDocument, $this> */
    public function documents(): HasMany
    {
        return $this->hasMany(AircraftDocument::class)->orderByDesc('issued_at');
    }

    /**
     * Every counter this aircraft keeps.
     *
     * The two mandatory ones are added here rather than stored, so no migration,
     * no import and no hand-edited row can produce an aircraft that has stopped
     * keeping a record the law requires.
     *
     * @return list<CounterKind>
     */
    public function counters(): array
    {
        $optional = array_filter(array_map(
            fn (string $value): ?CounterKind => CounterKind::tryFrom($value),
            $this->optional_counters ?? [],
        ));

        return array_values(array_unique(
            array_merge(CounterKind::mandatory(), array_values($optional)),
            SORT_REGULAR,
        ));
    }

    public function keeps(CounterKind $kind): bool
    {
        return in_array($kind, $this->counters(), strict: true);
    }

    /**
     * The latest reading of a counter, or zero if none was ever taken.
     *
     * Zero rather than null on purpose: a counter with no reading has not been
     * read, and for every calculation here that is the same as nothing having
     * happened yet. Callers that need the difference can ask readingFor().
     */
    public function currentValue(CounterKind $kind): float
    {
        return (float) ($this->latestReading($kind)?->value ?? 0.0);
    }

    public function latestReading(CounterKind $kind): ?CounterReading
    {
        return $this->readings()
            ->where('kind', $kind->value)
            ->orderByDesc('read_at')
            ->orderByDesc('id')
            ->first();
    }

    /**
     * Every counter's present value, keyed by kind.
     *
     * @return array<string, float>
     */
    public function currentValues(): array
    {
        $values = [];

        foreach ($this->counters() as $kind) {
            $values[$kind->value] = $this->currentValue($kind);
        }

        return $values;
    }

    /** @return Collection<int, Installation> */
    public function fittedComponents(): Collection
    {
        return $this->installations()
            ->whereNull('removed_at')
            ->orderBy('part_name')
            ->get();
    }

    public function currentReview(): ?AirworthinessReview
    {
        return $this->airworthinessReviews()->first();
    }

    /** @param  Builder<Aircraft>  $query */
    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }

    public function label(): string
    {
        return sprintf('%s (%s)', $this->registration, $this->model);
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['registration', 'model', 'serial_number', 'holder_id', 'optional_counters', 'is_active'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges()
            ->useLogName('fleet');
    }
}
