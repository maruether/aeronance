<?php

declare(strict_types=1);

namespace App\Modules\Warehouse\Actions;

use App\Models\User;
use App\Modules\Warehouse\Models\PurchaseOrderLine;
use App\Modules\Warehouse\Models\StockMovement;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Eine Bestellposition einbuchen.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * Vorgabe: „nach ankunft gehe ich auf ‚offene bestellungen' und buche von da aus
 * alles ein inklusive lagertags."
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * ES BUCHT DURCH DIE LAGERAKTION, UND DAS IST DER GANZE PUNKT.
 *
 * Jede Regel, die das Lager beim Wareneingang durchsetzt, gilt hier unveraendert
 * weiter: die Form-1-Pflicht des Bauteiltyps, die Regel „ein serialisiertes
 * Teil ist ein Los von eins", die Losbildung samt Losnummer aus der
 * Form-1-Nummer, das Verfallsdatum aus der Lagerzeit. Sie hier zu wiederholen
 * hiesse, sie innerhalb eines Jahres falsch zu wiederholen -- und dies ist
 * genau der Weg, auf dem jemand versucht waere, es „schnell selbst" zu machen.
 *
 * Die Bestellung steuert nur bei, was das Lager nicht wissen kann: dass diese
 * Lieferung zu dieser Bestellung gehoert.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * BESTELLT IST NICHT VORRAETIG -- deshalb entsteht erst HIER eine Bewegung.
 * Bis zum Einbuchen ist eine bestellte Menge eine Erwartung und kein Bestand;
 * sie taucht in keiner Auswertung auf, und niemand rechnet sich mit Teilen
 * reich, die noch beim Lieferanten stehen.
 * ─────────────────────────────────────────────────────────────────────────────
 */
final readonly class ReceivePurchaseOrderLine
{
    public function __construct(private ReceiveStock $receiveStock) {}

    /**
     * @param  array<string, mixed>  $lotData  Form 1, Charge, Seriennummer …
     */
    public function handle(
        PurchaseOrderLine $line,
        float $quantity,
        string $receivedAt,
        ?User $user = null,
        array $lotData = [],
    ): StockMovement {
        if ($quantity <= 0) {
            throw new InvalidArgumentException('Eine Lieferung hat eine positive Menge.');
        }

        return DB::transaction(function () use ($line, $quantity, $receivedAt, $user, $lotData): StockMovement {
            /*
             * Beide Zeilen gesperrt, und zwar bevor irgendetwas gelesen wird.
             * `quantity_received += x` auf einem vorher geladenen Model ist ein
             * Read-Modify-Write: Buchen zwei Personen dieselbe Position im
             * selben Moment ein, addieren beide auf denselben alten Stand --
             * zwei Teillieferungen zu je 5 ergeben 5 statt 10, und die
             * Bestellung gilt weiter als offen. Unter der Zeilensperre wartet
             * die zweite Buchung, bis die erste geschrieben hat.
             */
            $bestellung = $line->purchaseOrder()->lockForUpdate()->firstOrFail();
            $line = PurchaseOrderLine::query()->lockForUpdate()->findOrFail($line->id);

            /*
             * Auf eine stornierte Bestellung wird nicht gebucht. Nicht aus
             * Prinzip: Wer storniert hat, hat entschieden, dass nichts mehr
             * kommt -- kommt doch etwas, ist das eine neue Lage und gehoert
             * als regulaerer Wareneingang gebucht, nicht still an eine
             * abgeschlossene Bestellung geheftet.
             */
            if ($bestellung->state->isOutstanding() === false) {
                throw new InvalidArgumentException(
                    __('warehouse.order.refused.not_outstanding', [
                        'state' => $bestellung->state->label(),
                    ]),
                );
            }

            /*
             * Der Lieferant der Bestellung wird mitgegeben, wenn das Los
             * keinen eigenen traegt -- er steht schon fest, und ihn erneut
             * auswaehlen zu lassen ist eine Frage, deren Antwort das System
             * kennt.
             */
            $lotData['supplier_id'] ??= $bestellung->supplier_id;

            $bewegung = $this->receiveStock->handle(
                $line->partType,
                $quantity,
                $receivedAt,
                $user,
                $lotData,
            );

            $line->quantity_received += $quantity;
            $line->save();

            $bestellung->refreshState();

            return $bewegung;
        });
    }
}
