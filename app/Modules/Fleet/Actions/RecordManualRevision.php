<?php

declare(strict_types=1);

namespace App\Modules\Fleet\Actions;

use App\Models\User;
use App\Modules\Fleet\Enums\ManualKind;
use App\Modules\Fleet\Models\Aircraft;
use App\Modules\Fleet\Models\AircraftType;
use App\Modules\Fleet\Models\MaintenanceManual;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Eine Unterlage aufnehmen oder eine neue Revision nachtragen.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * EINE NEUE REVISION ÜBERSCHREIBT NICHTS. Sie entsteht als neuer Datensatz und
 * markiert den alten als abgelöst.
 *
 * Der bequeme Entwurf wäre ein Feld „Revision" am Handbuch, das man ändert.
 * Damit wäre die Frage „nach welchem Stand wurde im Mai gearbeitet?" nicht mehr
 * zu beantworten — und sie ist die einzige, die im Ernstfall zählt. Aus
 * demselben Grund führt das Lager Lose und keine Mengenspalte.
 * ─────────────────────────────────────────────────────────────────────────────
 */
final readonly class RecordManualRevision
{
    /**
     * Eine Unterlage neu aufnehmen.
     *
     * Musterweit ODER für ein Luftfahrzeug — genau eines von beidem.
     */
    public function add(
        AircraftType|Aircraft $for,
        ManualKind $kind,
        string $title,
        string $revision,
        ?string $reference = null,
        ?string $revisionDate = null,
        ?string $effectiveFrom = null,
        ?User $user = null,
        ?string $note = null,
    ): MaintenanceManual {
        if (trim($title) === '') {
            throw new InvalidArgumentException(__('fleet.manual.refused.title_missing'));
        }

        if (trim($revision) === '') {
            /*
             * Ohne Revisionsstand ist der Eintrag wertlos: Genau die Angabe,
             * wegen der es diese Tabelle gibt, waere dann leer -- und ein
             * Handbuch ohne Stand sieht aus wie ein aktuelles.
             */
            throw new InvalidArgumentException(__('fleet.manual.refused.revision_missing'));
        }

        return MaintenanceManual::create([
            'aircraft_type_id' => $for instanceof AircraftType ? $for->getKey() : null,
            'aircraft_id' => $for instanceof Aircraft ? $for->getKey() : null,
            'kind' => $kind,
            'title' => trim($title),
            'reference' => $reference !== null ? trim($reference) : null,
            'revision' => trim($revision),
            'revision_date' => $revisionDate,
            'effective_from' => $effectiveFrom,
            'recorded_by_id' => $user?->getKey(),
            'note' => $note,
        ]);
    }

    /**
     * Eine neue Revision derselben Unterlage.
     *
     * Übernimmt Zuordnung, Art, Titel und Dokumentnummer vom Vorgänger — was
     * sich ändert, ist der Stand. Wer das von Hand wiederholen müsste, würde
     * beim dritten Mal etwas anders schreiben, und dann wären es zwei Handbücher.
     */
    public function supersede(
        MaintenanceManual $previous,
        string $revision,
        ?string $revisionDate = null,
        ?string $effectiveFrom = null,
        ?User $user = null,
        ?string $note = null,
    ): MaintenanceManual {
        if (! $previous->isCurrent()) {
            throw new InvalidArgumentException(__('fleet.manual.refused.not_current'));
        }

        if (trim($revision) === '') {
            throw new InvalidArgumentException(__('fleet.manual.refused.revision_missing'));
        }

        if (trim($revision) === trim($previous->revision)) {
            /*
             * Derselbe Stand zweimal ist keine Revision, sondern ein
             * Tippfehler -- und er wuerde die Kette um einen Eintrag
             * verlaengern, der nichts aussagt.
             */
            throw new InvalidArgumentException(__('fleet.manual.refused.same_revision', [
                'revision' => $previous->revision,
            ]));
        }

        return DB::transaction(function () use ($previous, $revision, $revisionDate, $effectiveFrom, $user, $note): MaintenanceManual {
            $neu = MaintenanceManual::create([
                'aircraft_type_id' => $previous->aircraft_type_id,
                'aircraft_id' => $previous->aircraft_id,
                'kind' => $previous->kind,
                'title' => $previous->title,
                'reference' => $previous->reference,
                'revision' => trim($revision),
                'revision_date' => $revisionDate,
                'effective_from' => $effectiveFrom,
                'recorded_by_id' => $user?->getKey(),
                'note' => $note,
            ]);

            $previous->update([
                'superseded_at' => now(),
                'superseded_by_id' => $neu->getKey(),
            ]);

            return $neu;
        });
    }

    /**
     * Zurückziehen — gilt nicht mehr, und es kommt nichts nach.
     *
     * Etwas anderes als abgelöst: Das Handbuch eines ausgebauten Geräts hat
     * keinen Nachfolger. Der Grund ist Pflicht, sonst steht in einem Jahr die
     * Frage im Raum, warum die Unterlage verschwunden ist.
     */
    public function withdraw(MaintenanceManual $manual, string $reason, ?string $on = null): MaintenanceManual
    {
        if (! $manual->isCurrent()) {
            throw new InvalidArgumentException(__('fleet.manual.refused.not_current'));
        }

        if (trim($reason) === '') {
            throw new InvalidArgumentException(__('fleet.manual.refused.withdraw_without_reason'));
        }

        $manual->update([
            'withdrawn_at' => $on ?? now()->toDateString(),
            'withdrawn_reason' => trim($reason),
        ]);

        return $manual->fresh();
    }
}
