<?php

declare(strict_types=1);

namespace App\Modules\Vereinsflieger\Actions;

use App\Modules\Vereinsflieger\Models\WorkHourCategory;
use App\Modules\Vereinsflieger\VereinsfliegerProvider;
use Illuminate\Support\Facades\DB;

/**
 * Die gefundenen Arbeitsstunden-Kategorien festhalten.
 *
 * Wie RememberMemberStatuses, nur ohne Entscheidung: Eine Kategorie ist keine
 * Frage an den Admin, sondern eine Auswahloption. Name und Schalter werden
 * fortgeschrieben; was Vereinsflieger nicht mehr nennt, bleibt stehen --
 * eine konfigurierte Kategorie soll nicht aus der Liste fallen, nur weil sie
 * drueben aufgeraeumt wurde. Die Einstellungsseite kennzeichnet so etwas.
 */
final class RememberWorkHourCategories
{
    /**
     * @param  iterable<int|string, mixed>  $antwort  die rohe Antwort von workhourcategories/list
     * @return array{seen: int, new: int}
     */
    public function handle(iterable $antwort): array
    {
        $stand = now();
        $gesehen = 0;
        $neu = 0;

        DB::transaction(function () use ($antwort, $stand, &$gesehen, &$neu): void {
            foreach ($antwort as $schluessel => $eintrag) {
                // Wie bei user/list: Die Antwort traegt neben den Saetzen auch
                // httpstatuscode und Aehnliches -- nur numerisch geschluesselte
                // Arrays sind Kategorien.
                if (! is_array($eintrag) || ! is_numeric($schluessel)) {
                    continue;
                }

                $nummer = trim((string) ($eintrag['category'] ?? ''));

                if ($nummer === '') {
                    continue;
                }

                $satz = WorkHourCategory::firstOrNew(['category' => $nummer]);

                if (! $satz->exists) {
                    $satz->first_seen_at = $stand;
                    $neu++;
                }

                $name = trim(VereinsfliegerProvider::decode((string) ($eintrag['name'] ?? '')));

                $satz->name = $name !== '' ? $name : null;
                // Gemessen: '1'/'0' als Text.
                $satz->enabled = filter_var($eintrag['enabled'] ?? true, FILTER_VALIDATE_BOOLEAN);
                $satz->last_seen_at = $stand;
                $satz->save();

                $gesehen++;
            }
        });

        return ['seen' => $gesehen, 'new' => $neu];
    }
}
