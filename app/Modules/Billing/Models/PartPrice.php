<?php

declare(strict_types=1);

namespace App\Modules\Billing\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * Ein Verkaufspreis je Teilenummer, gültig ab einem Datum.
 *
 * Mehrere Zeilen je Nummer sind die Historie. Ein Preis wird nicht
 * überschrieben, sondern von einer neuen Zeile mit späterem valid_from
 * abgelöst -- so lässt sich jede alte Rechnung gegen die damals gültige
 * Liste nachrechnen.
 */
final class PartPrice extends Model
{
    use LogsActivity, SoftDeletes;

    protected $fillable = [
        'part_number',
        'description',
        'unit',
        'net_price',
        'valid_from',
        'note',
    ];

    protected $attributes = ['unit' => 'Stk'];

    protected function casts(): array
    {
        return [
            'net_price' => 'decimal:2',
            'valid_from' => 'date',
        ];
    }

    /**
     * Die Zeile, die an einem Tag für eine Teilenummer gilt.
     *
     * @param  Builder<self>  $query
     */
    public function scopeValidFor(Builder $query, string $partNumber, Carbon $on): void
    {
        $query->where('part_number', $partNumber)
            ->whereDate('valid_from', '<=', $on->toDateString())
            ->orderByDesc('valid_from')
            ->orderByDesc('id');
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['part_number', 'description', 'unit', 'net_price', 'valid_from'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges()
            ->useLogName('billing');
    }
}
