<?php

declare(strict_types=1);

namespace App\Modules\Warehouse\Console;

use App\Core\Mail\Postman;
use App\Core\Modules\ModuleManager;
use App\Modules\Warehouse\Mail\OverdueOrdersMail;
use App\Modules\Warehouse\Models\PurchaseOrder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Erinnert an überfällige Lieferungen.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * DAS IST DER ZWECK DES GANZEN BESTELLTEILS. Vorgabe: „Der Hintergrund ist das
 * ich gerade erst mit einem Lieferanten auf die nase gefallen bin der sich
 * nicht gemeldet hatte. Das hätte mir fast einen Termin gerissen."
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * ER ERINNERT NICHT JEDEN TAG. `reminded_at` haelt fest, wann zuletzt -- ohne
 * das schriebe der taegliche Lauf jeden Morgen dieselbe Mail, bis die Lieferung
 * kommt. Eine Erinnerung, die man wegwischt, ohne sie zu lesen, ist keine, und
 * die vierte identische Mail wischt jeder weg.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * OHNE MAILWEG MELDET ER DAS LAUT, statt still nichts zu tun. Genau hier waere
 * ein stiller Fehlschlag am teuersten: Wer sich auf die Erinnerung verlaesst,
 * verlaesst sich darauf, dass sie kommt. Deshalb gibt es die Liste zusaetzlich
 * in der Oberflaeche -- sie braucht keinen Mailserver.
 * ─────────────────────────────────────────────────────────────────────────────
 */
final class RemindOverdueOrdersCommand extends Command
{
    protected $signature = 'aeronance:remind-orders {--force : Auch erinnern, wenn zuletzt erst kürzlich erinnert wurde}';

    protected $description = 'Erinnert per Mail an Bestellungen, deren zugesagtes Lieferdatum verstrichen ist.';

    public function handle(ModuleManager $modules): int
    {
        if (! $modules->isEnabled('warehouse')) {
            return self::SUCCESS;
        }

        $abstand = max(1, (int) config('aeronance.orders.reminder_interval_days', 3));

        $ueberfaellig = PurchaseOrder::query()
            ->overdue()
            ->when(! $this->option('force'), fn ($q) => $q->where(
                fn ($q) => $q->whereNull('reminded_at')
                    ->orWhere('reminded_at', '<', now()->subDays($abstand)),
            ))
            ->with(['supplier', 'lines', 'createdBy'])
            ->get();

        if ($ueberfaellig->isEmpty()) {
            $this->components->info(__('warehouse.order.reminder.nothing'));

            return self::SUCCESS;
        }

        if (! Postman::canSend()) {
            /*
             * Kein Fehlschlag im Sinne von "kaputt", sondern eine Ansage: Es
             * ist kein Mailversand eingerichtet. Der Rueckgabewert bleibt
             * trotzdem SUCCESS -- ein geplanter Lauf, der daran scheitert,
             * weckt jemanden wegen einer Einstellung.
             */
            $this->components->warn(__('warehouse.order.reminder.no_mailer', [
                'anzahl' => $ueberfaellig->count(),
            ]));

            return self::SUCCESS;
        }

        /*
         * EINE MAIL JE MENSCH, nicht je Bestellung: Wer drei Sachen bestellt
         * hat, soll eine Liste bekommen und nicht drei Nachrichten, von denen
         * er die dritte nicht mehr liest.
         */
        $jeEmpfaenger = $ueberfaellig->groupBy('created_by_id');
        $verschickt = 0;

        foreach ($jeEmpfaenger as $bestellungen) {
            $empfaenger = $bestellungen->first()->createdBy;

            if ($empfaenger === null || ! filter_var((string) $empfaenger->email, FILTER_VALIDATE_EMAIL)) {
                /*
                 * Wer die Bestellung eingetragen hat, ist ausgeschieden oder
                 * hat keine brauchbare Adresse. Die Bestellung bleibt
                 * ueberfaellig und in der Oberflaeche sichtbar -- nur erinnern
                 * kann sie niemanden.
                 */
                $this->components->warn(__('warehouse.order.reminder.no_recipient', [
                    'anzahl' => $bestellungen->count(),
                ]));

                continue;
            }

            try {
                Mail::to($empfaenger->email)->send(new OverdueOrdersMail($bestellungen));
            } catch (Throwable $e) {
                $this->components->error($e->getMessage());

                continue;
            }

            PurchaseOrder::query()
                ->whereIn('id', $bestellungen->pluck('id'))
                ->update(['reminded_at' => now()]);

            $verschickt += $bestellungen->count();
        }

        $this->components->info(__('warehouse.order.reminder.sent', ['anzahl' => $verschickt]));

        return self::SUCCESS;
    }
}
