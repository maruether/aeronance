<?php

declare(strict_types=1);

namespace App\Modules\Fleet\Actions;

use App\Models\User;
use App\Modules\Fleet\Models\Weighing;
use App\Modules\Fleet\Support\WeighingResult;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Signing a weighing off, which freezes it.
 *
 * The last calculation and the last write in one act: the figures are worked out
 * and stored, and the sheet is closed in the same transaction. Doing it in two
 * steps would leave a gap in which the rows could change between the arithmetic
 * and the signature -- small, but exactly the sort of gap that turns up in an
 * audit years later with nobody able to say what happened.
 *
 * Note what is NOT refused: a result out of range. Vorgabe: "ist ein Ergebnis,
 * verhindert halt die Freigabe, aber das ist im echten leben so." A weighing
 * that comes out of limits is a real measurement of a real aircraft, and it
 * still gets signed and filed -- what it prevents is the aircraft flying, which
 * is a different decision made by a different person.
 */
final class SignOffWeighing
{
    public function handle(Weighing $weighing, User $user): WeighingResult
    {
        if ($weighing->isSignedOff()) {
            throw new RuntimeException('This weighing has already been signed off.');
        }

        return DB::transaction(function () use ($weighing, $user): WeighingResult {
            $result = $weighing->load('entries')->result();

            $weighing->update([
                'empty_mass_kg' => $result->emptyMassKg,
                'empty_cg_mm' => $result->emptyCgMm,
                'non_lifting_mass_kg' => $result->nonLiftingMassKg,
                'useful_load_kg' => $result->usefulLoadKg,

                'signed_off_at' => now(),
                'signed_off_by' => $user->id,

                // Copied, so it stays readable after the account is renamed or
                // pseudonymised (E7/E3a).
                'signed_off_by_name' => $user->name,
            ]);

            return $result;
        });
    }
}
