<?php

declare(strict_types=1);

namespace App\Modules\Tooling\Actions;

use App\Models\User;
use App\Modules\Tooling\Models\Tool;
use App\Modules\Tooling\Models\ToolIssue;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

/**
 * Werkzeug ausgeben und zurücknehmen.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * DIE ZÄHNE SITZEN BEI DER AUSGABE, nicht bei der Rückgabe.
 *
 * Ein Werkzeug mit abgelaufener Kalibrierung wird gar nicht erst
 * herausgegeben. Das ist der einzige Zeitpunkt, an dem die Sperre noch etwas
 * nützt — hinterher ist damit gearbeitet worden, und dann hilft nur noch die
 * Nachprüfung des Zeitraums.
 *
 * Zurückgenommen wird dagegen IMMER, ohne jede Bedingung. Ein Werkzeug, das
 * nicht zurückgebucht werden kann, weil inzwischen seine Frist abgelaufen ist,
 * bleibt sonst für immer als „draußen" in der Liste — und eine Liste, in der
 * Karteileichen stehen, liest nach kurzer Zeit niemand mehr.
 * ─────────────────────────────────────────────────────────────────────────────
 */
final readonly class IssueTool
{
    public function handle(
        Tool $tool,
        User $to,
        ?User $by = null,
        ?string $workOrderReference = null,
        ?string $dueBackAt = null,
        ?string $note = null,
    ): ToolIssue {
        if ($tool->isIssued()) {
            $laufend = $tool->currentIssue();

            throw new RuntimeException(__('tooling.issue.refused.already_out', [
                'name' => $laufend?->issued_to_name ?? '—',
            ]));
        }

        if (! $tool->state->isUsable()) {
            throw new RuntimeException(__('tooling.issue.refused.not_usable', [
                'state' => $tool->state->label(),
            ]));
        }

        /*
         * DIE SPERRE. Ein Werkzeug ohne gueltige Kalibrierung darf nicht in
         * Gebrauch -- und "kalibrierpflichtig, aber noch nie kalibriert" faellt
         * ausdruecklich darunter, siehe Tool::isCalibrationOverdue.
         */
        if ($tool->isCalibrationOverdue()) {
            throw new RuntimeException(__('tooling.issue.refused.calibration_overdue', [
                'date' => $tool->calibration_due_at?->format('d.m.Y') ?? __('tooling.due.never'),
            ]));
        }

        $faellig = $dueBackAt !== null ? Carbon::parse($dueBackAt)->startOfDay() : null;

        if ($faellig !== null && $faellig->lt(now()->startOfDay())) {
            throw new InvalidArgumentException(__('tooling.issue.refused.due_in_the_past'));
        }

        return DB::transaction(fn (): ToolIssue => ToolIssue::create([
            'tool_id' => $tool->getKey(),
            'issued_to_id' => $to->getKey(),
            // Name mitgeschrieben: Wer es hatte, muss lesbar bleiben, auch wenn
            // das Konto spaeter umbenannt wird oder der Mensch ausscheidet.
            'issued_to_name' => $to->name,
            'issued_at' => now(),
            'issued_by_id' => $by?->getKey(),
            'due_back_at' => $faellig?->toDateString(),
            'work_order_reference' => $workOrderReference !== null ? trim($workOrderReference) : null,
            'note' => $note,
        ]));
    }

    /**
     * Zurück ins Regal.
     *
     * Ohne Bedingungen, mit Absicht — siehe Kopf.
     */
    public function returnIt(ToolIssue $issue, ?User $by = null, ?string $note = null): ToolIssue
    {
        if (! $issue->isOutstanding()) {
            throw new RuntimeException(__('tooling.issue.refused.already_back'));
        }

        $issue->update([
            'returned_at' => now(),
            'returned_by_id' => $by?->getKey(),
            'note' => $note !== null && trim($note) !== ''
                ? trim(($issue->note ? $issue->note."\n" : '').trim($note))
                : $issue->note,
        ]);

        return $issue->fresh();
    }
}
