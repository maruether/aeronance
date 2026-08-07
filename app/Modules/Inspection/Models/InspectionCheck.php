<?php

declare(strict_types=1);

namespace App\Modules\Inspection\Models;

use App\Modules\Inspection\Enums\CheckItem;
use App\Modules\Inspection\Enums\CheckResult;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One answered — or unanswered — question.
 */
final class InspectionCheck extends Model
{
    protected $table = 'incoming_inspection_checks';

    protected $fillable = [
        'incoming_inspection_id',
        'item',
        'result',
        'note',
    ];

    protected function casts(): array
    {
        return [
            'item' => CheckItem::class,
            'result' => CheckResult::class,
        ];
    }

    public function inspection(): BelongsTo
    {
        return $this->belongsTo(IncomingInspection::class, 'incoming_inspection_id');
    }

    /**
     * A note is owed for anything that is not a plain pass.
     *
     * "Failed" without a reason cannot be acted on, and "not applicable" without
     * one is indistinguishable from skipping the question.
     */
    public function needsNote(): bool
    {
        return $this->result !== null
            && $this->result->needsNote()
            && trim((string) $this->note) === '';
    }
}
