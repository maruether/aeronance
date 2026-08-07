<?php

declare(strict_types=1);

namespace App\Modules\Tooling\Models;

use App\Modules\Tooling\Enums\ToolState;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * Ein Werkzeug.
 *
 * Die interessante Frage an diesem Modell ist nicht „welche Werkzeuge haben
 * wir", sondern „welches darf ich heute anfassen".
 */
final class Tool extends Model
{
    use LogsActivity, SoftDeletes;

    protected $fillable = [
        'inventory_number',
        'name',
        'manufacturer',
        'model',
        'serial_number',
        'location',
        'state',
        'calibration_required',
        'calibration_interval_months',
        'calibration_basis',
        'calibration_due_at',
        'note',
    ];

    protected $attributes = [
        'state' => 'in_service',
        'calibration_required' => false,
    ];

    protected function casts(): array
    {
        return [
            'state' => ToolState::class,
            'calibration_required' => 'boolean',
            'calibration_due_at' => 'date',
        ];
    }

    public function calibrations(): HasMany
    {
        return $this->hasMany(ToolCalibration::class)->orderByDesc('performed_at');
    }

    public function issues(): HasMany
    {
        return $this->hasMany(ToolIssue::class)->orderByDesc('issued_at');
    }

    /** Ist es gerade draußen? */
    public function isIssued(): bool
    {
        return $this->issues()->outstanding()->exists();
    }

    public function currentIssue(): ?ToolIssue
    {
        return $this->issues()->outstanding()->first();
    }

    public function lastCalibration(): ?ToolCalibration
    {
        return $this->calibrations()->first();
    }

    /**
     * Kalibrierung abgelaufen?
     *
     * Ein Werkzeug, das kalibriert werden muss und noch nie kalibriert wurde,
     * zählt als überfällig. Alles andere hieße, dass ein neu angelegter
     * Drehmomentschlüssel unbegrenzt gültig ist, bis jemand ihn zum ersten Mal
     * zur Kalibrierung gibt — die Reihenfolge, in der man es sicher falsch
     * herum macht.
     */
    public function isCalibrationOverdue(?Carbon $on = null): bool
    {
        if (! $this->calibration_required) {
            return false;
        }

        if ($this->calibration_due_at === null) {
            return true;
        }

        return $this->calibration_due_at->lt(($on ?? now())->startOfDay());
    }

    /** Läuft demnächst ab -- die Vorwarnung, damit ein Termin planbar bleibt. */
    public function isCalibrationDueSoon(int $days = 30, ?Carbon $on = null): bool
    {
        if (! $this->calibration_required || $this->calibration_due_at === null) {
            return false;
        }

        $stichtag = ($on ?? now())->startOfDay();

        return ! $this->isCalibrationOverdue($on)
            && $this->calibration_due_at->lte($stichtag->copy()->addDays($days));
    }

    /**
     * Darf damit heute gearbeitet werden?
     *
     * Die eine Frage, die das Modul beantworten muss. Sie fasst beides
     * zusammen: den Zustand und die Kalibrierung — ein einwandfrei
     * kalibrierter Schlüssel, der als defekt gemeldet ist, ist genauso wenig
     * benutzbar wie ein tadelloser mit abgelaufener Kalibrierung.
     */
    public function isAvailable(?Carbon $on = null): bool
    {
        return $this->state->isUsable()
            && ! $this->isCalibrationOverdue($on)
            && ! $this->isIssued();
    }

    /** @param  Builder<self>  $query */
    public function scopeOverdue(Builder $query): void
    {
        $query->where('calibration_required', true)
            ->whereIn('state', [ToolState::InService->value, ToolState::OutOfService->value])
            ->where(function (Builder $q): void {
                $q->whereNull('calibration_due_at')
                    ->orWhere('calibration_due_at', '<', now()->startOfDay());
            });
    }

    /** @param  Builder<self>  $query */
    public function scopeDueWithin(Builder $query, int $days = 30): void
    {
        $query->where('calibration_required', true)
            ->whereIn('state', [ToolState::InService->value, ToolState::OutOfService->value])
            ->whereNotNull('calibration_due_at')
            ->whereBetween('calibration_due_at', [now()->startOfDay(), now()->startOfDay()->addDays($days)]);
    }

    public function label(): string
    {
        return $this->inventory_number.' — '.$this->name;
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly([
                'inventory_number', 'name', 'state', 'location',
                'calibration_required', 'calibration_interval_months', 'calibration_basis', 'calibration_due_at',
            ])
            ->logOnlyDirty()
            ->dontLogEmptyChanges()
            ->useLogName('tooling');
    }
}
