<?php

declare(strict_types=1);

namespace App\Modules\TaskCards\Models;

use App\Models\User;
use App\Modules\TaskCards\Enums\ParticipationKind;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * One person's hours on one card.
 *
 * Minutes rather than decimal hours, because everybody writes "1:45" on a sheet
 * and nobody writes 1.75 -- and a system that asks for the second gets the first
 * typed into it.
 */
final class TaskCardTime extends Model
{
    use LogsActivity;

    protected $attributes = ['participation' => 'executed'];

    protected $fillable = [
        'task_card_id',
        'user_id',
        'person_name',
        'participation',
        'minutes',
        'worked_on',
        'note',
    ];

    protected function casts(): array
    {
        return [
            'participation' => ParticipationKind::class,
            'minutes' => 'integer',
            'worked_on' => 'date',
        ];
    }

    /**
     * Frozen with the visit too.
     *
     * These are the hours the experience log is built from and the release
     * certifies. Leaving them editable afterwards would let somebody's licence
     * record change under a signature that has already been given.
     */
    protected static function booted(): void
    {
        // withTrashed on both hops -- see TaskCard: a trashed parent is not an
        // absent one, and hours are what the experience log is built from.
        $frozen = function (?int $cardId): bool {
            if ($cardId === null) {
                return false;
            }

            $card = TaskCard::withTrashed()->find($cardId);

            return $card !== null
                && (WorkOrder::withTrashed()->find($card->work_order_id)?->isReleased() ?? false);
        };

        self::creating(function (self $time) use ($frozen): void {
            if ($frozen($time->task_card_id)) {
                throw new RuntimeException(
                    'The visit these hours belong to has been released to service. They '
                    .'are frozen.'
                );
            }
        });

        self::updating(function (self $time) use ($frozen): void {
            // Hours never change cards, for the same reason a card never
            // changes visits: they are somebody's licence evidence.
            if ($time->isDirty('task_card_id')) {
                throw new RuntimeException(
                    'A time entry stays on the card it was recorded for.'
                );
            }

            if ($frozen((int) $time->getOriginal('task_card_id'))) {
                throw new RuntimeException(
                    'The visit these hours belong to has been released to service. They '
                    .'are frozen.'
                );
            }
        });

        self::deleting(function (self $time) use ($frozen): void {
            if ($frozen($time->task_card_id)) {
                throw new RuntimeException(
                    'The visit these hours belong to has been released to service. They '
                    .'are frozen.'
                );
            }
        });
    }

    /** @return BelongsTo<TaskCard, $this> */
    public function taskCard(): BelongsTo
    {
        return $this->belongsTo(TaskCard::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function hours(): float
    {
        return round($this->minutes / 60, 2);
    }

    public function describe(): string
    {
        return sprintf('%d:%02d h', intdiv($this->minutes, 60), $this->minutes % 60);
    }

    // Audit-Trail fuer die editierbare Phase vor der Freigabe -- Begruendung
    // am WorkOrder. Stunden speisen das Part-66-Logbuch und entscheiden bei
    // kritischen Karten, wer NICHT kontrollieren darf; eine still geaenderte
    // Buchung muss eine Spur hinterlassen.
    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['task_card_id', 'user_id', 'person_name', 'participation', 'minutes', 'worked_on'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges()
            ->useLogName('workorders');
    }
}
