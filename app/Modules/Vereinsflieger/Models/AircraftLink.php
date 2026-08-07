<?php

declare(strict_types=1);

namespace App\Modules\Vereinsflieger\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * Ein Luftfahrzeug und die Anbindung, aus der seine Zeiten kommen.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * OHNE FREMDSCHLUESSEL AUF `aircraft`, und das ist keine Nachlaessigkeit: Die
 * Flotte ist ein eigenes Modul und kann abgeschaltet sein. Ein Fremdschluessel
 * waere eine harte Abhaengigkeit auf Datenbankebene -- genau das, was die
 * Modulgrenze verbietet.
 *
 * Der Preis ist, dass eine geloeschte Maschine ihre Zeile hier zuruecklaesst.
 * Das ist verkraftbar: Luftfahrzeuge werden nicht hart geloescht (Soft Deletes
 * ueberall), und eine verwaiste Zeile richtet keinen Schaden an -- sie findet
 * beim naechsten Lauf schlicht kein Luftfahrzeug.
 * ─────────────────────────────────────────────────────────────────────────────
 */
final class AircraftLink extends Model
{
    use LogsActivity;

    protected $table = 'vereinsflieger_aircraft_links';

    protected $fillable = [
        'connection_id',
        'aircraft_id',
        'callsign',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'last_read_at' => 'datetime',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['connection_id', 'aircraft_id', 'callsign', 'is_active'])
            ->logOnlyDirty()
            ->useLogName('vereinsflieger');
    }

    /** @return BelongsTo<Connection, $this> */
    public function connection(): BelongsTo
    {
        return $this->belongsTo(Connection::class, 'connection_id');
    }

    /** @param  Builder<self>  $query */
    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }

    public function recordRead(?string $fehler = null): void
    {
        $this->forceFill([
            'last_read_at' => now(),
            'last_error' => $fehler,
        ])->saveQuietly();
    }
}
