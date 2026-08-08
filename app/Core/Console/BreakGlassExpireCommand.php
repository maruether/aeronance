<?php

declare(strict_types=1);

namespace App\Core\Console;

use App\Core\Access\CoreRoles;
use App\Core\Models\BreakGlassRecord;
use App\Models\User;
use Illuminate\Console\Command;

/**
 * Withdraws emergency grants whose time has run out.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * DIE STUNDENANGABE WAR EINE ZEIT LANG NUR EINE NOTIZ. `--hours` schrieb ein
 * "gültig bis" in den Datensatz, aber nichts las es je wieder: Der Zugang
 * blieb bestehen, bis jemand von Hand widerrief -- eine Frist, die nicht
 * abläuft, ist ein Versprechen, das keiner hält. Dieser Lauf löst es ein.
 *
 * Er läuft über den Scheduler, alle fünf Minuten -- bei einer Vorgabe von vier
 * Stunden ist das genau genug. Bewusst NICHT im Berechtigungspfad selbst
 * erzwungen: Der Notzugang existiert für kaputte Systeme, und eine Prüfung,
 * die bei jeder Rechteabfrage in die Grant-Tabelle greift, wäre ein weiterer
 * beweglicher Teil an der Stelle, die einfach bleiben muss. Der Preis steht
 * hier ehrlich: Steht der Scheduler, läuft auch keine Frist ab -- der Handweg
 * (aeronance:break-glass-revoke) bleibt deshalb der verlässliche.
 *
 * Die Rolle wird nur entzogen, wenn kein ANDERER Grant für dasselbe Konto mehr
 * in Kraft ist -- zwei überlappende Gewährungen sollen sich nicht gegenseitig
 * den Boden wegziehen.
 * ─────────────────────────────────────────────────────────────────────────────
 */
final class BreakGlassExpireCommand extends Command
{
    protected $signature = 'aeronance:break-glass-expire';

    protected $description = 'Withdraw emergency access grants whose time has run out';

    public function handle(): int
    {
        $abgelaufen = BreakGlassRecord::query()
            ->whereNull('revoked_at')
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', now())
            ->get();

        if ($abgelaufen->isEmpty()) {
            $this->info('No grants have expired.');

            return self::SUCCESS;
        }

        foreach ($abgelaufen as $record) {
            // Wie beim Handweg: Der Datensatz bleibt und wird nur als beendet
            // markiert -- was passiert ist, steht weiter auf dem Papier.
            $record->update(['revoked_at' => now()]);

            $user = User::find($record->target_user_id);

            if ($user === null) {
                $this->warn(sprintf('Record #%d: the account no longer exists.', $record->id));

                continue;
            }

            $nochGedeckt = BreakGlassRecord::query()
                ->active()
                ->where('target_user_id', $user->id)
                ->exists();

            if ($nochGedeckt) {
                $this->info(sprintf(
                    'Record #%d expired; %s keeps the role under another active grant.',
                    $record->id,
                    $user->email,
                ));

                continue;
            }

            $user->removeRole(CoreRoles::ADMIN);

            $this->info(sprintf(
                'Record #%d expired -- administrator role withdrawn from %s.',
                $record->id,
                $user->email,
            ));
        }

        return self::SUCCESS;
    }
}
