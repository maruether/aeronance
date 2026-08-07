<?php

declare(strict_types=1);

namespace App\Modules\TaskCards\Actions;

use App\Core\Access\Authority;
use App\Core\Models\Qualification;
use App\Models\User;
use App\Modules\TaskCards\Enums\TaskCardState;
use App\Modules\TaskCards\Models\TaskCard;
use App\Modules\TaskCards\Permissions;
use InvalidArgumentException;
use RuntimeException;

/**
 * Die unabhängige Kontrolle einer kritischen Arbeit.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * DIE EINE REGEL, DIE DAS GANZE TRÄGT: Wer die Arbeit gemacht hat, kontrolliert
 * sie nicht.
 *
 * Alles andere an diesem Modul ist Verwaltung drumherum. Wer eine Steuerung
 * angeschlossen hat, sieht seinen eigenen Fehler nicht — nicht aus
 * Nachlässigkeit, sondern weil er beim Nachsehen dieselbe Erwartung mitbringt,
 * die ihn beim Anschließen geleitet hat. Deshalb ist die Prüfung hier keine
 * Formalie, sondern der Zweck.
 *
 * STRENG GELESEN, wie beim Pilot-Owner (siehe OwnWorkOnly): Ausgeschlossen ist
 * nicht nur, wer die Karte fertiggemeldet hat, sondern JEDER, der Stunden
 * darauf gebucht hat. Die weiche Lesart — „er hat ja nur zehn Minuten daran
 * gemacht" — ist genau die Konstruktion, gegen die es die Regel gibt.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * KEINE LIZENZ VERLANGT, und das ist bewusst.
 *
 * Verlangte die Kontrolle eine Part-66-Lizenz, wäre sie in genau den Vereinen
 * unmöglich, in denen es einen einzigen Lizenzinhaber gibt — und der ist
 * meistens derjenige, der gearbeitet hat. Die Kontrolle fiele dann nicht
 * strenger aus, sondern aus. Wer eine Lizenz hat, dessen Nummer wird
 * mitgeschrieben; verlangt wird sie nicht.
 * ─────────────────────────────────────────────────────────────────────────────
 */
final readonly class InspectCriticalTask
{
    public function __construct(private Authority $authority) {}

    public function handle(TaskCard $card, User $user, string $note): TaskCard
    {
        if (! $card->critical) {
            throw new InvalidArgumentException(__('taskcards.inspection.refused.not_critical'));
        }

        /*
         * Erst fertig, dann kontrolliert. Vorher gibt es nichts zu sehen -- und
         * eine Kontrolle, die vor der Arbeit unterschrieben wird, ist genau die
         * Sorte Nachweis, die im Ernstfall nichts wert ist.
         */
        if ($card->state === TaskCardState::Open) {
            throw new RuntimeException(__('taskcards.inspection.refused.not_completed'));
        }

        if ($card->state !== TaskCardState::Completed) {
            throw new RuntimeException(__('taskcards.inspection.refused.card_closed', [
                'state' => $card->state->label(),
            ]));
        }

        if ($card->inspected_at !== null) {
            throw new RuntimeException(__('taskcards.inspection.refused.already_inspected', [
                'name' => $card->inspected_by_name ?? '—',
            ]));
        }

        if (! $this->authority->permits($user, Permissions::CARDS_INSPECT)) {
            throw new RuntimeException(__('taskcards.inspection.refused.no_permission', [
                'permission' => Permissions::CARDS_INSPECT,
            ]));
        }

        // DIE REGEL.
        if ($this->workedOnIt($card, $user)) {
            throw new RuntimeException(__('taskcards.inspection.refused.own_work'));
        }

        if (trim($note) === '') {
            /*
             * Was getan wurde, nicht was zu tun war. "Anlenkung beidseitig
             * gezogen, Sicherung sichtbar" ist ein Nachweis; "kontrolliert" ist
             * eine Behauptung, und die haette man auch ohne Hinsehen tippen
             * koennen.
             */
            throw new InvalidArgumentException(__('taskcards.inspection.refused.note_missing'));
        }

        $qualifikation = $this->qualificationOf($user);

        $card->update([
            'inspected_at' => now(),
            'inspected_by' => $user->getKey(),
            // Name mitgeschrieben, nicht nur verwiesen: Der Nachweis muss
            // lesbar bleiben, wenn das Konto umbenannt wird oder der Mensch
            // den Verein verlaesst.
            'inspected_by_name' => $user->name,
            'inspection_note' => trim($note),
            'inspection_qualification_type' => $qualifikation?->type,
            'inspection_qualification_reference' => $qualifikation?->reference,
        ]);

        return $card->fresh();
    }

    /**
     * Hat diese Person an der Karte gearbeitet?
     *
     * Fertigmeldung ODER gebuchte Stunden. Beides zählt, denn beides heißt: Sie
     * hat das Werkstück in der Hand gehabt.
     */
    public function workedOnIt(TaskCard $card, User $user): bool
    {
        if ($card->completed_by === $user->getKey()) {
            return true;
        }

        return $card->times()->where('user_id', $user->getKey())->exists();
    }

    /**
     * Ob jemand die Kontrolle übernehmen darf.
     *
     * Für die Oberfläche, damit der Knopf gar nicht erst erscheint, statt beim
     * Drücken zu erklären, warum es nicht geht.
     */
    public function mayInspect(TaskCard $card, User $user): bool
    {
        return $card->critical
            && $card->state === TaskCardState::Completed
            && $card->inspected_at === null
            && $this->authority->permits($user, Permissions::CARDS_INSPECT)
            && ! $this->workedOnIt($card, $user);
    }

    /**
     * Die Qualifikation, falls es eine gibt — freiwillig, nicht verlangt.
     */
    private function qualificationOf(User $user): ?Qualification
    {
        return $user->qualifications()
            ->where('type', Qualification::TYPE_PART66)
            ->get()
            ->first(fn (Qualification $q): bool => $q->isValidOn());
    }
}
