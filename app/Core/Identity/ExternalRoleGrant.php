<?php

declare(strict_types=1);

namespace App\Core\Identity;

use Illuminate\Database\Eloquent\Model;

/**
 * Welche Rolle ein Provider tatsächlich vergeben hat.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * DIE TABELLE, DIE MAN BEIM ERSTEN ENTWURF VERGISST -- und ohne die der
 * Abgleich Schaden anrichtet.
 *
 * Ohne sie lässt sich nicht unterscheiden, ob jemand die Rolle "mechaniker" aus
 * einer AD-Gruppe hat oder weil der Werkstattleiter sie ihm gestern von Hand
 * gegeben hat. Ein Abgleich, der stumpf neu setzt, nimmt die zweite wieder weg
 * -- lautlos, irgendwann nachts, und auffallen würde es dem Betroffenen am
 * Samstag vor dem Hangar.
 *
 * Mit ihr ist die Regel scharf: Der Provider darf genau das zurücknehmen, was
 * er selbst vergeben hat. Alles andere fasst er nicht an.
 * ─────────────────────────────────────────────────────────────────────────────
 */
final class ExternalRoleGrant extends Model
{
    protected $fillable = ['user_id', 'role_id', 'provider'];
}
