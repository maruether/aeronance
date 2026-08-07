<?php

declare(strict_types=1);

namespace App\Modules\Tooling\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Eine Ausgabe — vom Regal in die Hand und zurück.
 */
final class ToolIssue extends Model
{
    protected $fillable = [
        'tool_id',
        'issued_to_id',
        'issued_to_name',
        'issued_at',
        'issued_by_id',
        'due_back_at',
        'work_order_reference',
        'returned_at',
        'returned_by_id',
        'note',
    ];

    protected function casts(): array
    {
        return [
            'issued_at' => 'datetime',
            'due_back_at' => 'date',
            'returned_at' => 'datetime',
        ];
    }

    public function tool(): BelongsTo
    {
        return $this->belongsTo(Tool::class);
    }

    public function issuedTo(): BelongsTo
    {
        return $this->belongsTo(User::class, 'issued_to_id');
    }

    public function issuedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'issued_by_id');
    }

    public function isOutstanding(): bool
    {
        return $this->returned_at === null;
    }

    /** Überfällig — das Werkzeug sollte längst zurück sein. */
    public function isOverdue(): bool
    {
        return $this->isOutstanding()
            && $this->due_back_at !== null
            && $this->due_back_at->lt(now()->startOfDay());
    }

    public function daysOut(): int
    {
        return (int) $this->issued_at->diffInDays($this->returned_at ?? now());
    }

    /** @param  Builder<self>  $query */
    public function scopeOutstanding(Builder $query): void
    {
        $query->whereNull('returned_at');
    }

    /**
     * Ausgaben, die sich mit einem Zeitraum überschneiden.
     *
     * ─────────────────────────────────────────────────────────────────────────
     * DAS IST DIE ANTWORT AUF F42, und sie kommt ohne Erfassung bei jedem
     * Handgriff aus.
     *
     * Fällt ein Werkzeug bei der Kalibrierung durch, liefert der
     * Nachprüfzeitraum das Fenster — und diese Abfrage die Vorgänge, an denen
     * in dieser Zeit damit gearbeitet wurde. Nicht handgriffgenau, aber
     * vorgangsgenau, und genau das verlangt 145.A.40: die Bewertung der
     * ausgeführten Arbeit, keine lückenlose Werkzeugzuordnung.
     *
     * Eine Ausgabe, die noch läuft, zählt mit: Sie überschneidet jeden
     * Zeitraum, der bis heute reicht.
     * ─────────────────────────────────────────────────────────────────────────
     *
     * @param  Builder<self>  $query
     */
    public function scopeOverlapping(Builder $query, Carbon $from, ?Carbon $until = null): void
    {
        $bis = $until ?? now();

        $query->where('issued_at', '<=', $bis)
            ->where(function (Builder $q) use ($from): void {
                $q->whereNull('returned_at')->orWhere('returned_at', '>=', $from);
            });
    }
}
