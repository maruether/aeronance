<?php

declare(strict_types=1);

namespace App\Modules\Fleet\Types;

use App\Modules\Fleet\Models\AircraftType;

/**
 * How another module asks "which of our types is this?".
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * THE SEAM, and it exists for the same reason ContributesOpenItems does.
 *
 * CLAUDE.md: "Kommunikation zwischen Modulen nur über definierte
 * Schnittstellen/Events -- nie direkt auf fremde Tabellen zugreifen." The
 * directives module may know the fleet -- it declares `requires: ['fleet']`,
 * because a directive without aircraft to apply it to is pointless. What it
 * should not need is Fleet's Eloquent model, which exposes everything about a
 * type when the only question is "do we fly this, and under which id".
 *
 * So the question moves to where the answer lives. The Kennblatt in particular
 * belongs here: how it is written, that one type can carry several, and that a
 * number nobody flies is a normal outcome rather than an error -- none of that
 * is knowledge the directives module should have to hold.
 * ─────────────────────────────────────────────────────────────────────────────
 */
final class TypeLookup
{
    /** The id of the type with this designation, exactly as catalogued. */
    public function byDesignation(string $designation): ?int
    {
        $designation = trim($designation);

        if ($designation === '') {
            return null;
        }

        return AircraftType::query()
            ->where('designation', $designation)
            ->value('id');
    }

    /**
     * The id of the type holding one of these type certificates.
     *
     * ─────────────────────────────────────────────────────────────────────────
     * A CELL CAN NAME SEVERAL. The gazette prints "EASA.R.008, EASA.R.146" when
     * a directive covers two data sheets, so each is tried and the first one
     * actually flown here decides.
     *
     * A number nobody flies finds nothing, and that is the CORRECT outcome: most
     * of what an authority publishes concerns aircraft this club does not own.
     * Treating it as a failure would turn every ordinary import into a wall of
     * warnings.
     *
     * Matched as the authority writes it -- "EASA.A.221", "A21CE", "322" -- and
     * deliberately not normalised: four authorities, four notations, and the
     * form somebody reads off the document is the form that is stored.
     */
    public function byCertificate(string $certificates): ?int
    {
        foreach (preg_split('/[,;]\s*/', $certificates) ?: [] as $certificate) {
            $certificate = trim($certificate);

            if ($certificate === '') {
                continue;
            }

            /*
             * ANY OF THE TYPE'S NUMBERS, not just the one on display.
             *
             * ─────────────────────────────────────────────────────────────────
             * The authorities quote each other. Germany publishes an EASA type's
             * directive under the EASA reference and an Annex-I type's under the
             * national Kennblatt, and the Blaues Buch prints both side by side
             * ("339/SP … EASA.A.221").
             *
             * Matching only aircraft_types.type_certificate meant a club that
             * had adopted the EASA number saw no national directives for that
             * type, and one that had adopted the Kennblatt saw no European ones.
             * Neither reported anything: the list was simply shorter.
             * ─────────────────────────────────────────────────────────────────
             */
            $id = AircraftType::query()
                ->whereHas('certificates', fn ($q) => $q->where('number', $certificate))
                ->value('id');

            /*
             * The column is asked as well, and deliberately so. A type saved
             * before this table existed is backfilled by the migration, but a
             * row deleted by hand -- or a test that writes the column directly
             * -- would otherwise stop matching a number the type visibly shows.
             */
            $id ??= AircraftType::query()
                ->where('type_certificate', $certificate)
                ->value('id');

            if ($id !== null) {
                return $id;
            }
        }

        return null;
    }
}
