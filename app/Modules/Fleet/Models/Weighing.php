<?php

declare(strict_types=1);

namespace App\Modules\Fleet\Models;

use App\Models\User;
use App\Modules\Fleet\Enums\WeighingKind;
use App\Modules\Fleet\Support\LoadingPlan;
use App\Modules\Fleet\Support\LoadingPlanCalculator;
use App\Modules\Fleet\Support\WeighingCalculator;
use App\Modules\Fleet\Support\WeighingResult;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Collection;
use RuntimeException;

/**
 * A weighing report.
 *
 * the correction stands at the top of this file because it shaped it: the
 * lever arms in the equipment list are the material one calculates WITH when
 * something is taken out. They are not the weighing. This is -- a signed
 * document with its own arithmetic, producing the empty mass and centre of
 * gravity that everything else refers back to.
 *
 * The results are stored as well as computable. They could be recalculated, and
 * for a while that looks tidier -- but a signed document's numbers are its
 * content (E7), and recomputing a 2019 report with 2027 code would republish
 * somebody's signature over an answer they never gave.
 */
final class Weighing extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'aircraft_id',
        'kind',
        'weighed_at',
        'place',
        'order_reference',
        'valid_until',
        'datum_reference',
        'reference_line',
        'front_support_arm_mm',
        'support_distance_mm',
        'empty_mass_kg',
        'empty_cg_mm',
        'non_lifting_mass_kg',
        'useful_load_kg',
        'cg_range_from_mm',
        'cg_range_to_mm',
        'flight_cg_from_mm',
        'flight_cg_to_mm',
        'max_mass_kg',
        'max_mass_water_kg',
        'max_non_lifting_kg',
        'cockpit_load_min_kg',
        'cockpit_load_max_kg',
        'user_id',
        'signed_by_name',
        'signed_by_approval',
        'equipment_list_dated',
        'remarks',
        'signed_off_at',
        'signed_off_by',
        'signed_off_by_name',
    ];

    protected function casts(): array
    {
        return [
            'kind' => WeighingKind::class,
            'weighed_at' => 'date',
            'valid_until' => 'date',
            'equipment_list_dated' => 'date',
            'signed_off_at' => 'datetime',
            'front_support_arm_mm' => 'integer',
            'support_distance_mm' => 'integer',
            'empty_mass_kg' => 'decimal:2',
            'empty_cg_mm' => 'decimal:2',
            'non_lifting_mass_kg' => 'decimal:2',
            'useful_load_kg' => 'decimal:2',
            'cg_range_from_mm' => 'decimal:2',
            'cg_range_to_mm' => 'decimal:2',
            'flight_cg_from_mm' => 'decimal:2',
            'flight_cg_to_mm' => 'decimal:2',
            'max_mass_kg' => 'decimal:2',
            'max_mass_water_kg' => 'decimal:2',
            'max_non_lifting_kg' => 'decimal:2',
            'cockpit_load_min_kg' => 'decimal:2',
            'cockpit_load_max_kg' => 'decimal:2',
        ];
    }

    /**
     * Once signed off, nothing changes.
     *
     * Enforced in the model rather than only in the interface, for the reason
     * this system keeps giving: a rule that lives in a form is a rule that an
     * import, a console command or the next screen does not know about.
     *
     * The one update that is allowed is the sign-off itself, which is why the
     * guard looks at the ORIGINAL value: null means it was open when this write
     * began, and that write may be the one closing it.
     */
    protected static function booted(): void
    {
        self::updating(function (self $weighing): void {
            if ($weighing->getOriginal('signed_off_at') !== null) {
                throw new RuntimeException(
                    'This weighing has been signed off and cannot be changed. A correction '
                    .'is a new weighing -- the old sheet carries somebody\'s signature.'
                );
            }
        });

        self::deleting(function (self $weighing): void {
            if ($weighing->signed_off_at !== null && ! $weighing->isForceDeleting()) {
                throw new RuntimeException('A signed-off weighing is not deleted.');
            }
        });
    }

    public function isSignedOff(): bool
    {
        return $this->signed_off_at !== null;
    }

    /** @return BelongsTo<Aircraft, $this> */
    public function aircraft(): BelongsTo
    {
        return $this->belongsTo(Aircraft::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return HasMany<WeighingEntry, $this> */
    public function entries(): HasMany
    {
        return $this->hasMany(WeighingEntry::class)->orderBy('position')->orderBy('id');
    }

    /** @return Collection<int, WeighingEntry> */
    public function entriesOf(string $section): Collection
    {
        return $this->entries->where('section', $section)->values();
    }

    public function result(): WeighingResult
    {
        return app(WeighingCalculator::class)->calculate($this);
    }

    /**
     * Works the sheet out and writes the answer down.
     *
     * Called when the form is saved, so what is stored is what the person saw
     * when they signed it.
     */
    public function recalculate(): WeighingResult
    {
        if ($this->isSignedOff()) {
            // Not an error worth raising -- a save on a frozen sheet simply has
            // nothing to write. Returning the stored answer keeps callers honest
            // about which numbers they are looking at.
            return $this->result();
        }

        $result = $this->result();

        $this->update([
            'empty_mass_kg' => $result->emptyMassKg,
            'empty_cg_mm' => $result->emptyCgMm,
            'non_lifting_mass_kg' => $result->nonLiftingMassKg,
            'useful_load_kg' => $result->usefulLoadKg,
        ]);

        return $result;
    }

    public function loadingPlan(): LoadingPlan
    {
        return app(LoadingPlanCalculator::class)->calculate($this);
    }

    /**
     * Whether the figures on file still match what the rows work out to.
     *
     * The other half of storing both. Keeping the result means a signed report
     * keeps its numbers -- but it also means the two can drift apart, and a
     * silent divergence would be the worst of both worlds. There are only two
     * ways it happens, and both are worth being told about: somebody edited the
     * rows after it was signed, or the calculation itself changed.
     *
     * Reported quietly rather than as an alarm. A recalculation is not
     * automatically right: the old number is the one somebody signed.
     */
    public function figuresMatchRows(): bool
    {
        if ($this->empty_mass_kg === null) {
            return true;
        }

        $result = $this->result();

        return abs((float) $this->empty_mass_kg - $result->emptyMassKg) < 0.005
            && $this->closeEnough($this->empty_cg_mm, $result->emptyCgMm)
            && $this->closeEnough($this->non_lifting_mass_kg, $result->nonLiftingMassKg);
    }

    private function closeEnough(mixed $stored, ?float $computed): bool
    {
        if ($stored === null && $computed === null) {
            return true;
        }

        if ($stored === null || $computed === null) {
            return false;
        }

        return abs((float) $stored - $computed) < 0.005;
    }

    public function isValid(?string $on = null): bool
    {
        return $this->valid_until === null
            || $this->valid_until->toDateString() >= ($on ?? now()->toDateString());
    }

    public function label(): string
    {
        return sprintf('%s — %s kg', $this->weighed_at->format('d.m.Y'), number_format((float) $this->empty_mass_kg, 1, ',', '.'));
    }
}
