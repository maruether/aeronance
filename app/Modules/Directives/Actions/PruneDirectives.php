<?php

declare(strict_types=1);

namespace App\Modules\Directives\Actions;

use App\Core\Access\Authority;
use App\Models\User;
use App\Modules\Directives\Models\Directive;
use App\Modules\Directives\Permissions;
use App\Modules\Fleet\Models\Aircraft;
use Illuminate\Support\Collection;
use RuntimeException;

/**
 * Die Liste aufräumen: weg, wozu kein Luftfahrzeug existiert.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * Feldtest: "zum speicher sparen möchte ich gerne noch die LTA/TM Liste
 * aufräumen können, also alles löschen zu dem es kein Flugzeug gibt." Die
 * Herstellerquellen liefern ganze Paletten (C.E.A.P.R. alle Robin-Muster auf
 * einmal); was davon keinen Bezug zur eigenen Flotte hat, füllt nur die Liste.
 *
 * DREI GRENZEN, alle mit Absicht:
 *
 * - Beurteilte Zeilen bleiben IMMER. Eine Beurteilung ist ein Nachweis, und
 *   Nachweise räumt man nicht weg -- auch nicht, wenn das Luftfahrzeug den
 *   Verein verlassen hat.
 * - Weiches Löschen, kein hartes. Der nächste Import stellt eine Zeile
 *   automatisch wieder her, sobald ein passendes Luftfahrzeug in die Flotte
 *   kommt (siehe ImportDirectives) -- hart gelöscht wäre sie für immer weg
 *   und käme doch beim nächsten Sonntagslauf als Neue wieder.
 * - Eine LEERE Flotte räumt nichts auf. Mitten in der Einrichtung, bevor das
 *   erste Luftfahrzeug angelegt ist, passte "kein Flugzeug passt" auf jede
 *   Zeile -- und der Knopf leerte die ganze Liste.
 * ─────────────────────────────────────────────────────────────────────────────
 */
final readonly class PruneDirectives
{
    public function __construct(private Authority $authority) {}

    /**
     * Was der Aufräumlauf treffen würde -- für die Vorschau und den Lauf selbst.
     *
     * @return Collection<int, Directive>
     */
    public function prunable(): Collection
    {
        $fleet = Aircraft::query()->with('installations')->get();

        if ($fleet->isEmpty()) {
            return new Collection;
        }

        return Directive::query()
            ->doesntHave('applications')
            ->get()
            ->filter(fn (Directive $d): bool => ! $fleet->contains(
                fn (Aircraft $a): bool => $d->mayApplyTo($a),
            ))
            ->values();
    }

    public function handle(User $user): int
    {
        if (! $this->authority->permits($user, Permissions::DIRECTIVES_MANAGE)) {
            throw new RuntimeException(sprintf(
                'Pruning the list requires the "%s" permission.',
                Permissions::DIRECTIVES_MANAGE,
            ));
        }

        $rows = $this->prunable();

        foreach ($rows as $row) {
            $row->delete();
        }

        if ($rows->isNotEmpty()) {
            activity('directives')
                ->causedBy($user)
                ->withProperties(['count' => $rows->count()])
                ->log(sprintf(
                    'LTA/TM-Liste aufgeräumt: %d Zeilen ohne passendes Luftfahrzeug entfernt.',
                    $rows->count(),
                ));
        }

        return $rows->count();
    }
}
