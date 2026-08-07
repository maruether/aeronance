<?php

declare(strict_types=1);

namespace App\Modules\Inspection\Models;

use App\Models\User;
use App\Modules\Inspection\Enums\CheckResult;
use App\Modules\Inspection\Enums\InspectionState;
use App\Modules\Warehouse\Models\PartType;
use App\Modules\Warehouse\Models\StockLot;
use App\Modules\Warehouse\Models\StockMovement;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * One arrival, checked.
 *
 * The record is the point. Nobody remembers in March whether the certificate
 * for the batch of hoses that came in October was actually looked at -- and
 * "we always check" is not an answer an auditor, or an accident investigator,
 * can do anything with.
 */
final class IncomingInspection extends Model
{
    use LogsActivity, SoftDeletes;

    protected $fillable = [
        'stock_movement_id',
        'part_type_id',
        'stock_lot_id',
        'state',
        'arrived_at',
        'decided_by_id',
        'decided_by_name',
        'decided_at',
        'decision_note',
    ];

    protected function casts(): array
    {
        return [
            'state' => InspectionState::class,
            'arrived_at' => 'datetime',
            'decided_at' => 'datetime',
        ];
    }

    public function movement(): BelongsTo
    {
        return $this->belongsTo(StockMovement::class, 'stock_movement_id');
    }

    public function partType(): BelongsTo
    {
        return $this->belongsTo(PartType::class);
    }

    public function lot(): BelongsTo
    {
        return $this->belongsTo(StockLot::class, 'stock_lot_id');
    }

    public function decidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by_id');
    }

    public function checks(): HasMany
    {
        return $this->hasMany(InspectionCheck::class);
    }

    /** @param  Builder<self>  $query */
    public function scopeOpen(Builder $query): void
    {
        $query->where('state', InspectionState::Open);
    }

    /**
     * Every question answered?
     *
     * Not "any answers given" -- ALL of them. The half-filled checklist is the
     * failure mode this module exists to prevent, so it is the one thing that
     * blocks the signature.
     */
    public function isAnswered(): bool
    {
        return $this->checks->every(fn (InspectionCheck $check): bool => $check->result !== null);
    }

    /** @return list<InspectionCheck> */
    public function failedChecks(): array
    {
        return $this->checks
            ->filter(fn (InspectionCheck $check): bool => $check->result === CheckResult::Fail)
            ->values()
            ->all();
    }

    /**
     * Anything failed?
     *
     * Deliberately NOT a hard block on acceptance. There are real deliveries
     * that arrive with a dented box and a perfectly good part inside, and a
     * system that refuses those teaches people to tick "pass" on the box. What
     * it does instead: a failed item forces an explicit note on the decision,
     * so accepting despite a failure is on the record as a considered act.
     */
    public function hasFailures(): bool
    {
        return $this->failedChecks() !== [];
    }

    public function label(): string
    {
        return $this->partType?->name ?? __('inspection.unknown_part');
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['state', 'decided_by_name', 'decided_at', 'decision_note'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges()
            ->useLogName('inspection');
    }
}
