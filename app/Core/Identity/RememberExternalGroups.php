<?php

declare(strict_types=1);

namespace App\Core\Identity;

use Illuminate\Support\Facades\DB;

/**
 * Was ein Provider an Gruppen gemeldet hat, festhalten.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * ZWEI REGELN, BEIDE ABSICHTLICH:
 *
 *  1. NICHTS WIRD GELOESCHT. Eine Funktion, die beim Provider verschwindet,
 *     behaelt ihre Zeile -- siehe Migration. Der Zeitstempel sagt, was fehlte.
 *
 *  2. EIN GEMEINSAMER ZEITSTEMPEL FUER DEN GANZEN LAUF. Wuerde jede Zeile ihr
 *     eigenes now() bekommen, laege die zuletzt geschriebene Millisekunden
 *     hinter der ersten -- und „war im letzten Abruf dabei" liesse sich nicht
 *     mehr durch einen Vergleich beantworten.
 * ─────────────────────────────────────────────────────────────────────────────
 */
final class RememberExternalGroups
{
    /**
     * @param  iterable<DiscoveredGroup>  $groups
     * @return array{seen: int, new: int}
     */
    public function handle(string $provider, iterable $groups): array
    {
        $stand = now();
        $gesehen = 0;
        $neu = 0;

        DB::transaction(function () use ($provider, $groups, $stand, &$gesehen, &$neu): void {
            foreach ($groups as $gruppe) {
                if (trim($gruppe->value) === '') {
                    continue;
                }

                $satz = ExternalGroup::firstOrNew([
                    'provider' => $provider,
                    'value' => $gruppe->value,
                ]);

                if (! $satz->exists) {
                    $satz->first_seen_at = $stand;
                    $neu++;
                }

                $satz->label = $gruppe->label;
                $satz->member_count = $gruppe->memberCount;
                $satz->last_seen_at = $stand;
                $satz->save();

                $gesehen++;
            }
        });

        return ['seen' => $gesehen, 'new' => $neu];
    }
}
