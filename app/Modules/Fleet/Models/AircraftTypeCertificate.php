<?php

declare(strict_types=1);

namespace App\Modules\Fleet\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * One Kennblatt number of one type.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * The same aircraft is on file under different numbers at different
 * authorities, and the authorities quote each other's: the German gazette
 * prints "EASA.A.221" for a European type and "339/SP" for an Annex-I one. A
 * type that holds only one of its numbers matches only half of what is
 * published about it -- and shows a shorter list without saying so.
 *
 * See the migration for the measurement behind that.
 * ─────────────────────────────────────────────────────────────────────────────
 */
final class AircraftTypeCertificate extends Model
{
    use LogsActivity, SoftDeletes;

    protected $fillable = [
        'aircraft_type_id',
        'number',
        'authority',
        'data_sheet_url',
        'is_primary',
    ];

    protected function casts(): array
    {
        return [
            'is_primary' => 'boolean',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        /*
         * Logged, because a number is what decides whether a directive reaches
         * an aircraft. Somebody removing one makes a list shorter, and a year
         * later the question is who did that and when.
         */
        return LogOptions::defaults()
            ->logOnly(['number', 'authority', 'is_primary'])
            ->logOnlyDirty()
            ->useLogName('fleet');
    }

    public function aircraftType(): BelongsTo
    {
        return $this->belongsTo(AircraftType::class);
    }

    /**
     * How a person reads it: the number, and who issued it.
     */
    public function label(): string
    {
        $authority = $this->authority !== null && $this->authority !== ''
            ? __('fleet.type.authority.'.$this->authority)
            : null;

        return $authority !== null && ! str_starts_with($authority, 'fleet.')
            ? sprintf('%s (%s)', $this->number, $authority)
            : $this->number;
    }
}
