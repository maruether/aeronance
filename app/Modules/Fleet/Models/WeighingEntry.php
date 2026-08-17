<?php

declare(strict_types=1);

namespace App\Modules\Fleet\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

/**
 * One line of a weighing sheet.
 *
 * Three sections share the table because they are three parts of one form and
 * are always read together. Each row fills in the columns its section has, which
 * is exactly what the paper does.
 */
final class WeighingEntry extends Model
{
    public const SECTION_COMPONENT = 'component';

    public const SECTION_SUPPORT = 'support';

    public const SECTION_DEDUCTION = 'deduction';

    /** A seat, for the loading plan: a label and the arm it sits at. */
    public const SECTION_SEAT = 'seat';

    /**
     * Eine zugelassene Konfiguration -- einsitzig, zweisitzig, und was das
     * Kennblatt sonst noch kennt. Je Zeile eine eigene Zuladung, eine eigene
     * Hoechstmasse und ein eigener Schwerpunktbereich.
     */
    public const SECTION_CONFIGURATION = 'configuration';

    protected $fillable = [
        'weighing_id',
        'section',
        'label',
        'position',
        'mass_kg',
        'non_lifting_kg',
        'gross_kg',
        'tare_kg',
        'arm_mm',
        'max_mass_kg',
        'useful_load_kg',
        'cg_from_mm',
        'cg_to_mm',
        'volume_litres',
        'density_kg_per_litre',
        'note',
    ];

    protected function casts(): array
    {
        return [
            'mass_kg' => 'decimal:2',
            'non_lifting_kg' => 'decimal:2',
            'gross_kg' => 'decimal:2',
            'tare_kg' => 'decimal:2',
            'arm_mm' => 'decimal:2',
            'max_mass_kg' => 'decimal:2',
            'useful_load_kg' => 'decimal:2',
            'cg_from_mm' => 'decimal:2',
            'cg_to_mm' => 'decimal:2',
            'volume_litres' => 'decimal:2',
            'density_kg_per_litre' => 'decimal:3',
        ];
    }

    /**
     * The rows are frozen with the sheet.
     *
     * Locking only the header would have been decoration: the figures come from
     * these rows, and a report whose lines can still be edited is a report whose
     * result can still be changed.
     */
    protected static function booted(): void
    {
        $refuse = function (self $entry): void {
            if ($entry->weighing?->isSignedOff() ?? false) {
                throw new RuntimeException(
                    'This weighing has been signed off. Its rows cannot be changed.'
                );
            }
        };

        self::creating($refuse);
        self::updating($refuse);
        self::deleting($refuse);
    }

    /** @return BelongsTo<Weighing, $this> */
    public function weighing(): BelongsTo
    {
        return $this->belongsTo(Weighing::class);
    }

    /**
     * What the scale said, less what the cradle weighed.
     *
     * Never negative: a tare larger than the gross is a transcription error, and
     * letting it through as a negative would quietly pull down the total instead
     * of showing up as the mistake it is.
     */
    public function netto(): float
    {
        return max(0.0, (float) $this->gross_kg - (float) $this->tare_kg);
    }

    /**
     * The mass of what can be flown off this tank.
     *
     * Volume times density, because that is how the sheet is filled in -- one
     * reads litres off a gauge, not kilograms.
     */
    public function deductedMass(): float
    {
        return (float) $this->volume_litres * (float) $this->density_kg_per_litre;
    }

    /** @param  Builder<WeighingEntry>  $query */
    public function scopeSection(Builder $query, string $section): void
    {
        $query->where('section', $section)->orderBy('position')->orderBy('id');
    }
}
