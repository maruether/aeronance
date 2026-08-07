<?php

declare(strict_types=1);

namespace App\Modules\Vereinsflieger\Actions;

use App\Modules\Vereinsflieger\Models\MemberStatus;
use Illuminate\Support\Facades\DB;

/**
 * Die gefundenen Mitgliedsstatus festhalten.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * ZWEI REGELN, BEIDE ABSICHTLICH:
 *
 *  1. NUR 1 UND 2 WERDEN VORBELEGT. Alles andere kommt mit `handling = null`
 *     an und wartet auf eine Entscheidung. Ein geratener Vorschlag waere hier
 *     schlimmer als keiner: Wer eine Voreinstellung sieht, nickt sie ab.
 *
 *  2. EINE EINMAL GETROFFENE ENTSCHEIDUNG WIRD NIE UEBERSCHRIEBEN. Auch nicht
 *     bei 1 und 2 -- wer „aktiv" bewusst auf „ignorieren" gestellt hat, will
 *     das behalten, und der naechste Abruf darf es nicht zurueckdrehen.
 *     Fortgeschrieben werden nur Name und Anzahl.
 * ─────────────────────────────────────────────────────────────────────────────
 */
final class RememberMemberStatuses
{
    /**
     * @param  iterable<array{msid: string, label: string, count: int}>  $statuses
     * @return array{seen: int, new: int, undecided: int}
     */
    public function handle(iterable $statuses): array
    {
        $stand = now();
        $gesehen = 0;
        $neu = 0;

        DB::transaction(function () use ($statuses, $stand, &$gesehen, &$neu): void {
            foreach ($statuses as $status) {
                $msid = trim((string) $status['msid']);

                if ($msid === '') {
                    continue;
                }

                $satz = MemberStatus::firstOrNew(['msid' => $msid]);

                if (! $satz->exists) {
                    $satz->first_seen_at = $stand;
                    // Nur hier, nur beim ersten Mal.
                    $satz->handling = MemberStatus::SYSTEM_DEFAULTS[$msid] ?? null;
                    $neu++;
                }

                $satz->label = $status['label'] !== '' ? $status['label'] : null;
                $satz->member_count = $status['count'];
                $satz->last_seen_at = $stand;
                $satz->save();

                $gesehen++;
            }
        });

        return [
            'seen' => $gesehen,
            'new' => $neu,
            'undecided' => MemberStatus::query()->whereNull('handling')->count(),
        ];
    }
}
