<?php

declare(strict_types=1);

namespace App\Modules\Vereinsflieger\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Eine Arbeitsstunden-Kategorie aus Vereinsflieger.
 *
 * Reine Anzeige-Daten fuer die Auswahlliste der Einstellung "Kategorie" --
 * die Wahrheit lebt drueben, hier steht der letzte gesehene Stand. Nicht
 * protokolliert: Es ist ein Spiegel, keine Entscheidung.
 */
final class WorkHourCategory extends Model
{
    protected $table = 'vereinsflieger_work_hour_categories';

    protected $fillable = [
        'category',
        'name',
        'enabled',
        'first_seen_at',
        'last_seen_at',
    ];

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'first_seen_at' => 'datetime',
            'last_seen_at' => 'datetime',
        ];
    }

    /**
     * Die Auswahlliste: Nummer => Anzeige, abgeschaltete gekennzeichnet.
     *
     * @return array<string, string>
     */
    public static function selectOptions(): array
    {
        return self::query()
            ->orderBy('name')
            ->get()
            ->mapWithKeys(fn (self $kategorie): array => [
                $kategorie->category => sprintf(
                    '%s (%s)%s',
                    $kategorie->name ?? $kategorie->category,
                    $kategorie->category,
                    $kategorie->enabled ? '' : ' '.__('settings.catalogue.vereinsflieger.workhours.category.disabled_suffix'),
                ),
            ])
            ->all();
    }
}
