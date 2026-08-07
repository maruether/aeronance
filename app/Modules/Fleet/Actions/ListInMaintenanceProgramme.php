<?php

declare(strict_types=1);

namespace App\Modules\Fleet\Actions;

use App\Core\Models\Qualification;
use App\Models\User;
use App\Modules\Fleet\Models\Aircraft;
use App\Modules\Fleet\Models\PilotOwnerAuthorisation;
use Illuminate\Support\Facades\DB;

/**
 * Naming somebody in an aircraft's maintenance programme for pilot-owner work.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * The direction of this matters more than the code in it.
 *
 * Authority already knows how to ask "may this person certify FOR THIS
 * AIRCRAFT", and answers it from a core Qualification whose scope is a
 * registration. The obvious next step would have been to make Authority consult
 * the fleet's table -- and that would point the CORE at a MODULE, which is the
 * one direction the architecture does not allow. The core has to run with no
 * modules at all.
 *
 * So it runs the other way. The AMP listing is the fleet's fact; the authority
 * that follows from it is the core's. Listing somebody writes BOTH: the row that
 * records what the programme says, and the scoped qualification the core already
 * understands. Nothing in the core learns that a fleet exists, and Authority
 * needs no change whatever.
 *
 * That also settles the point -- "ich darf auch an Privatflugzeugen nach
 * Pilot-Owner freigeben, solange ich im AMP aufgeführt bin" -- in the data
 * rather than in a comment: the authority follows the NAMING, not the ownership.
 * Somebody named on an aircraft they do not own has it; a holder who is not
 * named does not.
 * ─────────────────────────────────────────────────────────────────────────────
 */
final class ListInMaintenanceProgramme
{
    public function add(
        Aircraft $aircraft,
        User $person,
        ?string $validUntil = null,
        ?string $note = null,
    ): PilotOwnerAuthorisation {
        return DB::transaction(function () use ($aircraft, $person, $validUntil, $note): PilotOwnerAuthorisation {
            $listing = PilotOwnerAuthorisation::updateOrCreate(
                ['aircraft_id' => $aircraft->id, 'user_id' => $person->id],
                [
                    // Copied at the moment of listing -- the programme named a
                    // person, and that naming stays readable after the account
                    // is renamed or pseudonymised (E7/E3a).
                    'listed_name' => $person->name,
                    'listed_at' => now()->toDateString(),
                    'valid_until' => $validUntil,
                    'note' => $note,
                ],
            );

            Qualification::updateOrCreate(
                [
                    'user_id' => $person->id,
                    'type' => Qualification::TYPE_PILOT_OWNER,
                    'scope' => $aircraft->registration,
                ],
                [
                    'reference' => $aircraft->registration,
                    'valid_from' => now()->toDateString(),
                    'valid_until' => $validUntil,
                ],
            );

            return $listing;
        });
    }

    /**
     * Taking somebody off the programme.
     *
     * The qualification is ended rather than deleted: it was true until today,
     * and a record that simply vanishes cannot answer whether an act performed
     * last spring was covered. Same reasoning as everywhere else here --
     * corrections are new facts, not erasures.
     */
    public function remove(Aircraft $aircraft, User $person): void
    {
        DB::transaction(function () use ($aircraft, $person): void {
            PilotOwnerAuthorisation::where('aircraft_id', $aircraft->id)
                ->where('user_id', $person->id)
                ->delete();

            Qualification::where('user_id', $person->id)
                ->where('type', Qualification::TYPE_PILOT_OWNER)
                ->where('scope', $aircraft->registration)
                ->get()
                ->each(function (Qualification $qualification): void {
                    $qualification->update(['valid_until' => now()->subDay()->toDateString()]);
                });
        });
    }
}
