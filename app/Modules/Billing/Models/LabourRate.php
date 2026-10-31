<?php

declare(strict_types=1);

namespace App\Modules\Billing\Models;

use App\Modules\TaskCards\Enums\ActivityKind;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * Ein Stundensatz je Tätigkeitsart, gültig ab einem Datum.
 *
 * Je Tätigkeitsart und nicht je Person (Entscheidung 2026-10-30): Die Karte
 * trägt ihre Art ohnehin, und der Kunde zahlt für Prüfung, Wartung oder
 * Reparatur -- nicht dafür, wer gerade Dienst hatte.
 */
final class LabourRate extends Model
{
    use LogsActivity;

    protected $fillable = [
        'activity_kind',
        'net_rate_per_hour',
        'valid_from',
        'note',
    ];

    protected function casts(): array
    {
        return [
            'activity_kind' => ActivityKind::class,
            'net_rate_per_hour' => 'decimal:2',
            'valid_from' => 'date',
        ];
    }

    /** @param  Builder<self>  $query */
    public function scopeValidFor(Builder $query, ActivityKind $kind, Carbon $on): void
    {
        $query->where('activity_kind', $kind->value)
            ->whereDate('valid_from', '<=', $on->toDateString())
            ->orderByDesc('valid_from')
            ->orderByDesc('id');
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['activity_kind', 'net_rate_per_hour', 'valid_from'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges()
            ->useLogName('billing');
    }
}
