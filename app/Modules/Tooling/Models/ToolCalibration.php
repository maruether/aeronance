<?php

declare(strict_types=1);

namespace App\Modules\Tooling\Models;

use App\Models\User;
use App\Modules\Tooling\Enums\CalibrationResult;
use App\Modules\Tooling\Enums\GapReason;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Collection;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

/**
 * Eine Kalibrierung — mit Befund, und dem Zeitraum, den sie in Frage stellt.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * DER NACHPRÜFZEITRAUM IST DER GRUND, WARUM DAS EIN EIGENER DATENSATZ IST und
 * nicht zwei Spalten am Werkzeug.
 *
 * Ein Kalibrierschein sagt: ab heute stimmt es wieder. Was er nicht sagt: dass
 * es vier Monate lang nicht belegt war — oder, schlimmer, dass es außer Toleranz
 * ankam und damit jede Arbeit seit der letzten guten Messung in Frage steht.
 * Stünde das nur als „letzte Kalibrierung" am Werkzeug, wäre es mit dem nächsten
 * Schein verschwunden, und mit ihm die Frage, an welchen Flugzeugen in dieser
 * Zeit mit dem Ding gearbeitet wurde.
 *
 * Welcher der beiden Fälle vorliegt, steht in `gap_reason`; wie weit zurück,
 * in `gap_started_at`. Siehe RecordCalibration::reviewPeriod().
 * ─────────────────────────────────────────────────────────────────────────────
 */
final class ToolCalibration extends Model implements HasMedia
{
    use InteractsWithMedia;

    /** Der Kalibrierschein selbst -- siehe registerMediaCollections(). */
    public const CERTIFICATES = 'certificates';

    protected $fillable = [
        'tool_id',
        'performed_at',
        'valid_until',
        'result',
        'provider',
        'certificate_reference',
        'gap_started_at',
        'gap_reason',
        'gap_reviewed_at',
        'gap_reviewed_by_id',
        'gap_review_note',
        'note',
        'recorded_by_id',
    ];

    protected function casts(): array
    {
        return [
            'performed_at' => 'date',
            'valid_until' => 'date',
            'result' => CalibrationResult::class,
            'gap_reason' => GapReason::class,
            'gap_started_at' => 'date',
            'gap_reviewed_at' => 'datetime',
        ];
    }

    public function tool(): BelongsTo
    {
        return $this->belongsTo(Tool::class);
    }

    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by_id');
    }

    public function reviewedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'gap_reviewed_by_id');
    }

    /** Es gab eine Zeit, die nachzupruefen ist. */
    public function hasGap(): bool
    {
        return $this->gap_started_at !== null;
    }

    /**
     * Das Werkzeug kam ausser Toleranz an.
     *
     * Der schwere Fall: Jede Arbeit seit der letzten guten Messung steht in
     * Frage, nicht erst die seit dem Faelligkeitsdatum.
     */
    public function isOutOfTolerance(): bool
    {
        return $this->result?->isFailure() ?? false;
    }

    /** Und die ist noch nicht bewertet. */
    public function gapNeedsReview(): bool
    {
        return $this->hasGap() && $this->gap_reviewed_at === null;
    }

    public function gapDays(): int
    {
        return $this->hasGap()
            ? (int) $this->gap_started_at->diffInDays($this->performed_at)
            : 0;
    }

    /**
     * Die Ausgaben, die in den Nachprüfzeitraum fallen.
     *
     * ─────────────────────────────────────────────────────────────────────────
     * HIER TREFFEN SICH DIE BEIDEN HÄLFTEN. Die Kalibrierung sagt, WELCHER
     * ZEITRAUM in Frage steht; die Ausgabeliste sagt, WORAN in dieser Zeit
     * damit gearbeitet wurde. Zusammen beantworten sie die Frage, die 145.A.40
     * stellt — und die vorher nur ein Mensch mit Aktenordner beantworten
     * konnte.
     *
     * Ohne Lücke gibt es nichts nachzuprüfen und die Liste ist leer.
     * ─────────────────────────────────────────────────────────────────────────
     *
     * @return Collection<int, ToolIssue>
     */
    public function issuesInReviewPeriod(): Collection
    {
        if (! $this->hasGap()) {
            return collect();
        }

        /*
         * BIS ZUM ENDE DES MESSTAGES, nicht bis Mitternacht davor.
         *
         * `performed_at` ist ein Datum und steht damit auf 00:00; `issued_at`
         * ist ein Zeitstempel. Ohne endOfDay() fiele eine Ausgabe vom Vormittag
         * des Messtages aus dem Fenster -- und das ist ausgerechnet der Tag,
         * an dem am ehesten noch damit gearbeitet wurde.
         */
        return ToolIssue::query()
            ->where('tool_id', $this->tool_id)
            ->overlapping($this->gap_started_at->copy()->startOfDay(), $this->performed_at->copy()->endOfDay())
            ->orderBy('issued_at')
            ->get();
    }

    /** Die betroffenen Vorgänge, ohne Wiederholungen. */
    public function affectedWorkOrders(): array
    {
        return $this->issuesInReviewPeriod()
            ->pluck('work_order_reference')
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    /** @param  Builder<self>  $query */
    public function scopeOpenGaps(Builder $query): void
    {
        $query->whereNotNull('gap_started_at')->whereNull('gap_reviewed_at');
    }

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection(self::CERTIFICATES)
            ->useDisk('documents')
            ->acceptsMimeTypes(['application/pdf', 'image/jpeg', 'image/png']);
    }
}
