<?php

declare(strict_types=1);

namespace App\Modules\Vereinsflieger\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Der Beleg, dass eine Arbeitszeit uebertragen wurde.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * GEMESSEN, und deshalb gibt es diese Tabelle: workhours/add legt bei
 * identischen Daten einen ZWEITEN Eintrag an. Vereinsflieger prueft nichts, und
 * geloescht werden kann drueben gar nichts -- die API kennt weder edit noch
 * delete. Eine Doppelbuchung ist damit DAUERHAFT.
 *
 * Der eindeutige Schluessel auf `task_card_time_id` ist deshalb die eigentliche
 * Sicherung: Der zweite Versuch laeuft in einen Datenbankfehler und nicht in
 * einen zweiten Eintrag. Eine Pruefung im Code allein wuerde bei zwei
 * gleichzeitigen Laeufen verlieren.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * DIE ZEILE BLEIBT AUCH BEI EINEM FEHLSCHLAG.
 *
 * Sonst wuerde bei jedem naechtlichen Lauf erneut versucht, was gestern schon
 * scheiterte -- Nacht fuer Nacht, gegen einen mengenbegrenzten Dienst, ohne
 * dass es jemand merkt. Mit Zeile und `last_error` steht der Fehlschlag da und
 * kann angesehen werden; `transferred_at` bleibt leer, solange nichts ankam.
 * ─────────────────────────────────────────────────────────────────────────────
 */
final class WorkHourTransfer extends Model
{
    protected $table = 'vereinsflieger_work_hour_transfers';

    protected $fillable = [
        'task_card_time_id',
        'connection_id',
        'whid',
        'job_text',
        'hours',
        'category',
        'status',
        'transferred_at',
        'verified_at',
        'attempts',
        'last_error',
    ];

    /** die Grenze: „max 3 versuche". */
    public const MAX_ATTEMPTS = 3;

    protected function casts(): array
    {
        return [
            'transferred_at' => 'datetime',
            'verified_at' => 'datetime',
            'attempts' => 'integer',
        ];
    }

    /** @return BelongsTo<Connection, $this> */
    public function connection(): BelongsTo
    {
        return $this->belongsTo(Connection::class, 'connection_id');
    }

    /**
     * Bestaetigt: Die Nummer ist da, und sie stammt aus einer Antwort.
     */
    public function succeeded(): bool
    {
        return $this->transferred_at !== null && filled($this->whid);
    }

    /**
     * Darf noch einmal versucht werden?
     *
     * Nach drei Versuchen nicht mehr. Loeschen kann Vereinsflieger nicht -- wer
     * ohne Grenze wiederholt, riskiert bei einem dauerhaft kaputten Zustand
     * jede Nacht einen neuen Eintrag, den niemand mehr wegbekommt.
     */
    public function mayRetry(): bool
    {
        return ! $this->succeeded() && (int) $this->attempts < self::MAX_ATTEMPTS;
    }

    /**
     * Aufgegeben -- drei Versuche, nichts angekommen.
     *
     * Bleibt als Zeile stehen, damit jemand nachsehen kann. Ein stiller
     * Verzicht waere schlimmer als ein sichtbarer.
     */
    public function gaveUp(): bool
    {
        return ! $this->succeeded() && (int) $this->attempts >= self::MAX_ATTEMPTS;
    }
}
