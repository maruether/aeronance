<?php

declare(strict_types=1);

namespace App\Modules\Warehouse\Actions;

use App\Models\User;
use App\Modules\Warehouse\Enums\LotState;
use App\Modules\Warehouse\Models\LotStateChange;
use App\Modules\Warehouse\Models\PartType;
use App\Modules\Warehouse\Models\StockLot;
use Illuminate\Support\Facades\DB;

/**
 * Die Form-1-Pflicht nachträglich setzen -- und den Bestand mitnehmen.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * WER DIE PFLICHT EINSCHALTET, MEINT AUCH DAS, WAS SCHON DA IST.
 *
 * Aus dem Review der Nachweis-Naht: Der Haken am Bauteiltyp fasste bestehende
 * Lose nicht an. Ein Verein, der nachträglich feststellt „das ist ein
 * Form-1-Teil", hatte danach Bestand, der weiter als „verwendbar" im Regal
 * stand -- ausgeben ließ er sich zwar nicht mehr (IssueStock), aber die
 * Zustandsspalte behauptete das Gegenteil, und das ist genau die Sorte
 * Widerspruch, die man erst im Audit bemerkt.
 *
 * Gesperrt statt gelöscht, und mit Begründung: Die Ware ist ja da. Sobald
 * jemand den Nachweis nachträgt („Nachweis eintragen" am Los), lässt sie sich
 * regulär wieder freigeben.
 *
 * AUSGENOMMEN bleibt, was seinen Nachweis anders führt: Ausbau-Lose (die
 * Feststellung beim Ausbau trägt, begrenzt auf ihr Luftfahrzeug) und leere
 * Lose (da ist nichts mehr, das jemand einbauen könnte).
 * ─────────────────────────────────────────────────────────────────────────────
 */
final readonly class ApplyFormOneDutyToStock
{
    /**
     * @return list<string> die Losnummern, die gesperrt wurden
     */
    public function handle(PartType $partType, ?User $user = null): array
    {
        if (! $partType->requires_form_one) {
            return [];
        }

        $betroffen = $partType->lots()
            ->where('state', LotState::Serviceable->value)
            ->withRemainingQuantity()
            ->get()
            ->filter(fn (StockLot $lot): bool => $lot->remainingQuantity() > 0
                && ! $lot->hasRequiredDocument()
                && ! $lot->isRestrictedToItsAircraft());

        if ($betroffen->isEmpty()) {
            return [];
        }

        return DB::transaction(function () use ($betroffen, $user): array {
            $nummern = [];

            foreach ($betroffen as $lot) {
                /*
                 * Über das Modell, nicht über ChangeLotState: Diese Sperre ist
                 * keine Feststellung über den Zustand des Teils -- niemand hat
                 * es angesehen. Sie hält nur fest, dass der Nachweis fehlt,
                 * und braucht deshalb weder Qualifikation noch Recht des
                 * Auslösenden über die Stammdatenpflege hinaus.
                 */
                LotStateChange::create([
                    'stock_lot_id' => $lot->id,
                    'from_state' => LotState::Serviceable,
                    'to_state' => LotState::Quarantined,
                    'reason' => __('warehouse.lot.form_one_duty_reason'),
                    'user_id' => $user?->id,
                    'occurred_at' => now(),
                ]);

                $lot->update(['state' => LotState::Quarantined]);
                $nummern[] = $lot->lot_number;
            }

            activity('warehouse')
                ->causedBy($user)
                ->withProperties(['lots' => $nummern])
                ->log(sprintf(
                    'Form-1-Pflicht gesetzt: %d Los(e) ohne Nachweis gesperrt.',
                    count($nummern),
                ));

            return $nummern;
        });
    }
}
