<?php

declare(strict_types=1);

namespace App\Modules\TaskCards\Actions;

use App\Core\Access\Authority;
use App\Models\User;
use App\Modules\TaskCards\Models\Finding;
use App\Modules\TaskCards\Models\TaskCard;
use App\Modules\TaskCards\Models\WorkOrder;
use App\Modules\TaskCards\Permissions;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

/**
 * Der Befundbericht eines Vorgangs.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * Vorgabe: „Einem Vorgang sollte immer ein befundbericht zugeordnet sein, nach
 * dem neu anlegen eines vorgangs sollte ein befundbericht angelegt werden
 * können innerhalb des vorgangs, wobei JEDER PUNKT ZU EINER ARBEITSKARTE wird."
 *
 * EINE KARTE JE PUNKT -- und das ist der Unterschied zur Sammelaktion an der
 * Befundliste, die aus mehreren angehakten Befunden EINE Karte macht. Beide
 * sind richtig, weil sie verschiedene Fragen beantworten:
 *
 *   Die Sammelaktion bildet ab, wie gearbeitet wird: Man baut das Flugzeug
 *   einmal auseinander und arbeitet die Liste ab.
 *
 *   Der Befundbericht bildet ab, wie DOKUMENTIERT wird: Jede Zeile des Blatts
 *   trägt ihre eigenen drei Unterschriften -- erledigt, kontrolliert, geprüft.
 *   Eine gemeinsame Karte hätte für zehn Zeilen eine Unterschrift, und das
 *   Blatt könnte nicht mehr sagen, wer welchen Punkt behoben hat.
 *
 * WAS HIER NICHT NOCHMAL GEBAUT WIRD: das Anlegen des Befunds, die Nummer, die
 * Rechteprüfung, die Verknüpfung Befund → Karte. Das steht alles in
 * RecordFinding und wird von hier nur zweimal hintereinander aufgerufen. Diese
 * Klasse ist die Reihenfolge, nicht die Fachlogik.
 * ─────────────────────────────────────────────────────────────────────────────
 */
final readonly class ManageFindingReport
{
    public function __construct(
        private RecordFinding $findings,
        private Authority $authority,
    ) {}

    /**
     * Die Punkte des Blatts aufnehmen — jeder wird ein Befund und seine Karte.
     *
     * @param  list<array{title?: string, description?: string, ata_chapter?: string|null, critical?: bool, critical_reason?: string|null}>  $points
     * @return list<TaskCard>
     */
    public function record(
        WorkOrder $order,
        array $points,
        User $user,
        ?string $foundOn = null,
    ): array {
        if ($points === []) {
            throw new InvalidArgumentException(
                'Ein Befundbericht ohne Punkte ist ein leeres Blatt -- dafür braucht es keinen Eintrag.'
            );
        }

        if (! $order->isOpen()) {
            throw new RuntimeException(
                'Befunde werden in einen offenen Vorgang aufgenommen. Ein geschlossener '
                .'Vorgang bekommt keine neuen Karten mehr.'
            );
        }

        $aircraft = $order->aircraft;

        if ($aircraft === null) {
            throw new RuntimeException('Dieser Vorgang hat kein Luftfahrzeug -- ein Befund braucht eines.');
        }

        /*
         * EINE TRANSAKTION FÜR DAS GANZE BLATT. Ein Bericht, von dem die
         * ersten vier Punkte in der Datenbank stehen und der fünfte an einer
         * Pflichtangabe scheitert, wäre schlimmer als gar keiner: Wer ihn neu
         * abschickt, bekommt die ersten vier ein zweites Mal.
         */
        return DB::transaction(function () use ($order, $points, $user, $foundOn, $aircraft): array {
            $cards = [];

            foreach ($points as $point) {
                $finding = $this->findings->record(
                    aircraft: $aircraft,
                    title: (string) ($point['title'] ?? ''),
                    description: (string) ($point['description'] ?? ''),
                    user: $user,
                    foundOn: $foundOn,
                );

                $cards[] = $this->findings->schedule(
                    finding: $finding,
                    order: $order,
                    user: $user,
                    critical: (bool) ($point['critical'] ?? false),
                    criticalReason: $point['critical_reason'] ?? null,
                    ataChapter: $point['ata_chapter'] ?? null,
                );
            }

            return $cards;
        });
    }

    /**
     * Die vorgedruckte letzte Zeile: Fremdkörper- und Werkzeugkontrolle.
     *
     * ─────────────────────────────────────────────────────────────────────────
     * Sie steht auf dem Papier über der Unterschrift und meint den ganzen
     * Vorgang: Man räumt einmal auf, nicht je Befund. Ein vergessener
     * Schraubenschlüssel im Rumpf ist genau die Sorte Fund, für die diese Zeile
     * gedruckt wurde -- deshalb ist sie hier ein eigener, bestätigter Schritt
     * und kein Haken, den das Speichern nebenbei mitsetzt.
     *
     * Der Name wird mitgeschrieben, wie bei jeder Unterschrift in diesem
     * System: Das Konto kann später umbenannt oder pseudonymisiert werden; was
     * auf dem Blatt stand, bleibt.
     * ─────────────────────────────────────────────────────────────────────────
     */
    public function confirmForeignObjectCheck(WorkOrder $order, User $user): WorkOrder
    {
        if ($order->isReleased()) {
            throw new RuntimeException(
                'Dieser Vorgang ist freigegeben und eingefroren. Die Kontrolle nachträglich '
                .'einzutragen hiesse, eine bereits erteilte Freigabe zu ergänzen.'
            );
        }

        if (! $this->authority->permits($user, Permissions::CARDS_WORK)
            && ! $this->authority->permits($user, Permissions::WORK_ORDERS_MANAGE)) {
            throw new RuntimeException(sprintf(
                'Die Fremdkörper- und Werkzeugkontrolle bestätigt, wer an dem Vorgang '
                .'arbeitet -- dafür wird das Recht "%s" verlangt.',
                Permissions::CARDS_WORK,
            ));
        }

        $order->update([
            'foreign_object_check_at' => now(),
            'foreign_object_check_by' => $user->id,
            'foreign_object_check_by_name' => $user->name,
        ]);

        return $order->fresh();
    }

    /**
     * Die Zeilen des Blatts, fertig zum Anzeigen und Drucken.
     *
     * Eine Zeile ist ein Befund mit der Karte, die ihn behebt. Die drei
     * Unterschriftsspalten kommen aus dieser Karte und werden NICHT zweitgeführt
     * -- ein Blatt, das etwas anderes sagt als die Akte darunter, wäre die
     * schlimmste Sorte Dokument.
     *
     * @return list<array{position: int, finding: Finding, card: TaskCard|null}>
     */
    public function points(WorkOrder $order): array
    {
        $zeilen = [];
        $position = 1;

        foreach ($order->findingReportPoints() as $befund) {
            $zeilen[] = [
                'position' => $position++,
                'finding' => $befund,
                'card' => $befund->resolvingTaskCard,
            ];
        }

        return $zeilen;
    }
}
