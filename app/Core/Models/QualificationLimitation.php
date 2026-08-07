<?php

declare(strict_types=1);

namespace App\Core\Models;

use App\Core\Access\WorkSubject;
use App\Core\Enums\MaintenanceSubject;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * One exclusion on a licence.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * AN EXCLUSION, NOT A PERMISSION, and the direction is the whole design. Point
 * 66.A.50 of Part-66 puts limitations on a licence as exclusions from the
 * certifying privileges, and the paper says "ausgenommen Zellen in
 * Metallbauweise" -- so that is what is stored.
 *
 * The tempting alternative is to record what somebody MAY do ("Holz und FVK").
 * It reads better and it is wrong: it is an inference from the exclusion, and
 * the day a club buys something built in a way nobody listed, the positive list
 * silently forbids it while the licence does not.
 *
 * AND IT HANGS OFF THE LICENCE, NOT OFF A CATEGORY. Vorgabe: "da ist egal ob das
 * L1 oder L2 ist." A holder of L1 and L2 with a metal exclusion has it on both.
 * ─────────────────────────────────────────────────────────────────────────────
 */
final class QualificationLimitation extends Model
{
    use LogsActivity, SoftDeletes;

    protected $fillable = [
        'qualification_id',
        'subject',
        'text',
    ];

    protected function casts(): array
    {
        return [
            'subject' => MaintenanceSubject::class,
        ];
    }

    /** @return BelongsTo<Qualification, $this> */
    public function qualification(): BelongsTo
    {
        return $this->belongsTo(Qualification::class);
    }

    /**
     * Whether this exclusion stands in the way of work on this subject.
     *
     * THE UNKNOWN CASE BLOCKS. If a licence excludes metal airframes and nobody
     * recorded what the aircraft is built of, the honest answer is that it
     * cannot be established that the work is covered -- and an act that cannot
     * be established as covered must not be certified. The refusal names the
     * missing data, so it is fixed by entering it rather than by ignoring it.
     *
     * Two cases never block, both for the same reason -- there is nothing to
     * compare against, and inventing a comparison would produce refusals nobody
     * can act on:
     *
     *   - a text-only limitation, which this system cannot reason about at all;
     *   - an avionics limitation, because whether a job touched avionics is a
     *     property of the job and no field records it (see WorkSubject).
     *
     * Both are still recorded, shown, and frozen into the certificate, so an
     * auditor reading the record afterwards sees the limitation the signature
     * was given under.
     */
    public function blocks(WorkSubject $subject): bool
    {
        if ($this->subject === null || $this->subject->area() === 'avionics') {
            return false;
        }

        return $subject->touches($this->subject) !== false;
    }

    /** Whether this one can gate anything at all. */
    public function isEnforceable(): bool
    {
        return $this->subject !== null && $this->subject->area() !== 'avionics';
    }

    /**
     * How it reads. The typed wording wins -- it is what the licence says.
     */
    public function label(): string
    {
        if (filled($this->text)) {
            return (string) $this->text;
        }

        return $this->subject?->exclusionLabel() ?? '';
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['qualification_id', 'subject', 'text'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges()
            ->useLogName('core');
    }
}
