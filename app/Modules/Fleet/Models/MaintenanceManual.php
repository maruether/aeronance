<?php

declare(strict_types=1);

namespace App\Modules\Fleet\Models;

use App\Models\User;
use App\Modules\Fleet\Enums\ManualKind;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

/**
 * Eine Wartungsunterlage in einem bestimmten Revisionsstand.
 *
 * Jede Zeile ist eine REVISION, kein Handbuch — siehe Migration. Wer wissen
 * will, was im Mai galt, folgt der Kette rückwärts, statt in ein
 * überschriebenes Feld zu sehen.
 */
final class MaintenanceManual extends Model implements HasMedia
{
    use InteractsWithMedia, LogsActivity, SoftDeletes;

    /** Die Datei selbst — siehe registerMediaCollections(). */
    public const DOCUMENTS = 'manuals';

    protected $fillable = [
        'aircraft_type_id',
        'aircraft_id',
        'kind',
        'title',
        'reference',
        'revision',
        'revision_date',
        'effective_from',
        'superseded_at',
        'superseded_by_id',
        'withdrawn_at',
        'withdrawn_reason',
        'note',
        'recorded_by_id',
    ];

    protected function casts(): array
    {
        return [
            'kind' => ManualKind::class,
            'revision_date' => 'date',
            'effective_from' => 'date',
            'superseded_at' => 'datetime',
            'withdrawn_at' => 'date',
        ];
    }

    public function aircraftType(): BelongsTo
    {
        return $this->belongsTo(AircraftType::class);
    }

    public function aircraft(): BelongsTo
    {
        return $this->belongsTo(Aircraft::class);
    }

    public function supersededBy(): BelongsTo
    {
        return $this->belongsTo(self::class, 'superseded_by_id');
    }

    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by_id');
    }

    /**
     * Gilt diese Unterlage heute?
     *
     * Abgelöst und zurückgezogen sind zwei verschiedene Enden — das eine hat
     * einen Nachfolger, das andere nicht. Für die Frage „gilt es" laufen beide
     * aufs selbe hinaus.
     */
    public function isCurrent(): bool
    {
        return $this->superseded_at === null && $this->withdrawn_at === null;
    }

    /**
     * Noch nicht anzuwenden — die Revision liegt vor, gilt aber erst später.
     */
    public function isNotYetEffective(): bool
    {
        return $this->effective_from !== null
            && $this->effective_from->gt(now()->startOfDay());
    }

    /** „Wartungshandbuch ASK 21, Rev. 12" — für Nachweis und Anzeige. */
    public function label(): string
    {
        return trim($this->title.' — '.__('fleet.manual.revision_short', ['revision' => $this->revision]));
    }

    /**
     * Der Abdruck, der auf eine Arbeitskarte geschrieben wird.
     *
     * Kopiert und nicht verwiesen: Nach welchem Stand gearbeitet wurde, muss
     * lesbar bleiben, auch wenn die Unterlage später abgelöst oder das Muster
     * umbenannt wird. Dieselbe Regel wie beim Bescheinigungsinhalt am Los (E7).
     */
    public function snapshot(): string
    {
        $teile = array_filter([
            $this->title,
            $this->reference,
            __('fleet.manual.revision_short', ['revision' => $this->revision]),
            $this->revision_date?->format('d.m.Y'),
        ]);

        return implode(', ', $teile);
    }

    /** @param  Builder<self>  $query */
    public function scopeCurrent(Builder $query): void
    {
        $query->whereNull('superseded_at')->whereNull('withdrawn_at');
    }

    /**
     * Die geltenden Unterlagen für ein Luftfahrzeug — eigene UND die seines
     * Musters.
     *
     * Beides zusammen, weil der Mensch an der Karte nicht wissen muss, ob das
     * Handbuch am Muster oder am einzelnen Flugzeug hängt. Ihn danach zu
     * fragen hieße, ihm eine Verwaltungsfrage zu stellen, deren Antwort das
     * System kennt.
     *
     * @param  Builder<self>  $query
     */
    public function scopeFor(Builder $query, Aircraft $aircraft): void
    {
        $query->current()->where(function (Builder $q) use ($aircraft): void {
            $q->where('aircraft_id', $aircraft->getKey());

            if ($aircraft->aircraft_type_id !== null) {
                $q->orWhere('aircraft_type_id', $aircraft->aircraft_type_id);
            }
        });
    }

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection(self::DOCUMENTS)
            ->useDisk('documents')
            ->acceptsMimeTypes(['application/pdf', 'image/jpeg', 'image/png']);
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['title', 'reference', 'revision', 'revision_date', 'superseded_at', 'withdrawn_at'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges()
            ->useLogName('fleet');
    }
}
