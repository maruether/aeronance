<?php

declare(strict_types=1);

namespace App\Modules\Tooling\Actions;

use App\Models\User;
use App\Modules\Tooling\Enums\CalibrationResult;
use App\Modules\Tooling\Enums\GapReason;
use App\Modules\Tooling\Models\Tool;
use App\Modules\Tooling\Models\ToolCalibration;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Einen Kalibrierschein eintragen.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * DER NACHPRÜFZEITRAUM ENTSTEHT HIER, und nur hier liegen die Zahlen dafür noch
 * beieinander.
 *
 * In dem Moment, in dem die neue Kalibrierung gespeichert wird, ist das Werkzeug
 * wieder in Ordnung. Was danach verloren wäre: das alte Fälligkeitsdatum und die
 * letzte Messung mit gutem Befund. Beides wird deshalb GELESEN, bevor das Neue
 * geschrieben wird — siehe reviewPeriod().
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * DER BEFUND IST PFLICHT, und das ist eine Korrektur an der ersten Fassung.
 *
 * Die knüpfte an die Verspätung an. Die Vorschrift knüpft am Durchfaller an —
 * EASA-FAQ 116318: „If the tool / equipment fails during next regular
 * calibration / inspection, the completed tasks may require to be verified /
 * performed again." Ein Befund mit Vorgabewert „in Ordnung" hätte genau den
 * Fall verschluckt, um den es geht; deshalb steht `$result` ohne Vorgabe in der
 * Signatur und muss von jedem Aufrufer benannt werden.
 * ─────────────────────────────────────────────────────────────────────────────
 */
final readonly class RecordCalibration
{
    public function handle(
        Tool $tool,
        string $performedAt,
        CalibrationResult $result,
        ?string $validUntil = null,
        ?string $provider = null,
        ?string $certificateReference = null,
        ?User $user = null,
        ?string $note = null,
    ): ToolCalibration {
        $gemessen = Carbon::parse($performedAt)->startOfDay();

        if ($gemessen->gt(now()->endOfDay())) {
            // Eine Messung aus der Zukunft ist ein Tippfehler, und zwar einer,
            // der die Faelligkeit um Jahre verschiebt.
            throw new InvalidArgumentException(__('tooling.refused.future_calibration'));
        }

        /*
         * Ohne zugesagtes Datum rechnet das Intervall des Werkzeugs. Fehlt auch
         * das, gibt es kein Faelligkeitsdatum -- und das Werkzeug gilt weiter
         * als ueberfaellig, weil "kalibrierpflichtig ohne Faelligkeit" genau
         * das ist, was niemand uebersehen soll.
         */
        $gueltigBis = $validUntil !== null
            ? Carbon::parse($validUntil)->startOfDay()
            : ($tool->calibration_interval_months !== null
                ? $gemessen->copy()->addMonths($tool->calibration_interval_months)
                : null);

        if ($gueltigBis !== null && $gueltigBis->lte($gemessen)) {
            throw new InvalidArgumentException(__('tooling.refused.validity_backwards'));
        }

        return DB::transaction(function () use ($tool, $gemessen, $result, $gueltigBis, $provider, $certificateReference, $user, $note): ToolCalibration {
            // Vor dem Schreiben lesen -- danach ist der alte Stand weg.
            [$luecke, $grund] = $this->reviewPeriod($tool, $gemessen, $result);

            $kalibrierung = ToolCalibration::create([
                'tool_id' => $tool->getKey(),
                'performed_at' => $gemessen->toDateString(),
                'valid_until' => $gueltigBis?->toDateString(),
                'result' => $result,
                'provider' => $provider,
                'certificate_reference' => $certificateReference,
                'gap_started_at' => $luecke?->toDateString(),
                'gap_reason' => $grund,
                'note' => $note,
                'recorded_by_id' => $user?->getKey(),
            ]);

            $tool->update(['calibration_due_at' => $gueltigBis?->toDateString()]);

            return $kalibrierung;
        });
    }

    /**
     * Ab wann ist nachzuprüfen — und warum.
     *
     * ─────────────────────────────────────────────────────────────────────────
     * ZWEI FÄLLE, ZWEI ZEITRÄUME, UND DER SCHWERERE GEWINNT.
     *
     * AUSSER TOLERANZ zählt zurück bis zur letzten Messung mit Befund „in
     * Toleranz". Wann das Werkzeug angefangen hat abzuweichen, weiß niemand —
     * belegt ist nur, dass es beim letzten guten Befund stimmte. Alles
     * dazwischen steht in Frage. Das ist der Fall, den EASA meint.
     *
     * ZU SPÄT zählt ab dem abgelaufenen Fälligkeitsdatum. Das Werkzeug war
     * dabei womöglich einwandfrei; nachgewiesen war es nur nicht. Deutlich
     * schwächer — EASA lässt dafür sogar befristete Verlängerungen zu.
     *
     * Gibt es keine frühere gute Messung, beginnt der Zeitraum bei der ersten
     * Kalibrierung dieses Werkzeugs überhaupt. Fehlt auch die, bleibt er offen:
     * Ein erfundener Anfang wäre schlimmer als ein ehrliches „unbekannt", und
     * die Bemerkung der Bewertung ist der Ort, an dem das steht.
     * ─────────────────────────────────────────────────────────────────────────
     *
     * @return array{0: ?Carbon, 1: ?GapReason}
     */
    private function reviewPeriod(Tool $tool, Carbon $gemessen, CalibrationResult $result): array
    {
        if ($result->isFailure()) {
            $letzteGute = $tool->calibrations()
                ->where('result', CalibrationResult::InTolerance->value)
                ->where('performed_at', '<', $gemessen->toDateString())
                ->orderByDesc('performed_at')
                ->first();

            $start = $letzteGute?->performed_at
                ?? $tool->calibrations()->orderBy('performed_at')->first()?->performed_at;

            return [$start?->copy(), GapReason::OutOfTolerance];
        }

        /*
         * In Ordnung, aber zu spaet gemessen. Bei einem Werkzeug, das noch nie
         * kalibriert wurde, gibt es keine Luecke: Da ist nicht bekannt, dass
         * etwas falsch war, sondern die Historie faengt hier an.
         */
        if ($tool->calibration_due_at !== null && $tool->calibration_due_at->lt($gemessen)) {
            return [$tool->calibration_due_at->copy(), GapReason::Overdue];
        }

        return [null, null];
    }

    /**
     * Die Bewertung der Lücke festhalten.
     *
     * Was in der Zeit ohne belegte Genauigkeit gearbeitet wurde, muss jemand
     * ansehen — 145.A.40. Das Ergebnis gehört hierher und nicht in eine
     * Aktennotiz, weil die Frage sonst beim nächsten Audit wieder von vorn
     * beginnt.
     */
    public function reviewGap(ToolCalibration $calibration, User $user, string $note): ToolCalibration
    {
        if (! $calibration->hasGap()) {
            throw new InvalidArgumentException(__('tooling.refused.no_gap'));
        }

        if (trim($note) === '') {
            throw new InvalidArgumentException(__('tooling.refused.review_without_note'));
        }

        $calibration->update([
            'gap_reviewed_at' => now(),
            'gap_reviewed_by_id' => $user->getKey(),
            'gap_review_note' => trim($note),
        ]);

        return $calibration->fresh();
    }
}
