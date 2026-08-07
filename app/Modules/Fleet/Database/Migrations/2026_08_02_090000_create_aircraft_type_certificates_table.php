<?php

declare(strict_types=1);

use App\Modules\Fleet\Models\AircraftType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * One type, several Kennblatt numbers.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * WHY THE SINGLE COLUMN WAS NOT ENOUGH -- and it was not a matter of tidiness.
 *
 * The same aircraft is on file under different numbers at different
 * authorities. The LBA's Blaues Buch prints both in one row:
 *
 *     339/SP   ASK 21   Schleicher   ASK 21   6 (2/90)   EASA.A.221
 *     ^^^^^^                                             ^^^^^^^^^^
 *     das deutsche Kennblatt                             die EASA-Nummer
 *
 * aircraft_types.type_certificate holds ONE of those. Whichever a club adopted,
 * the other number matched nothing -- and the gazette quotes both: an EASA
 * reference for EASA-certified types, the German Kennblatt for Annex-I ones.
 * So a club that adopted "EASA.A.221" saw no national directives for the type,
 * and a club that adopted "339/SP" saw no European ones. Neither showed an
 * error; both showed a shorter list.
 *
 * Vorgabe: "die kennblattnummer ist im kfz typ im flottenmodul hinterlegt." That
 * is the design -- it just has to hold ALL of them.
 *
 * RELATIONAL RATHER THAN A SECOND COLUMN, and rather than JSON: a type can
 * carry more than two (EASA plus a national plus a UK.TC after Brexit), and
 * CLAUDE.md is explicit -- "Schwere JSON-Pfad-Queries vermeiden, lieber sauber
 * relationale Spalten."
 *
 * type_certificate STAYS on the type as the primary number. Every screen, every
 * export and the activity log refer to it, and a directive list is read by the
 * number a person quotes. The table holds that one too, flagged, so a lookup
 * has one place to ask.
 * ─────────────────────────────────────────────────────────────────────────────
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('aircraft_type_certificates', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('aircraft_type_id')
                ->constrained()
                ->cascadeOnDelete();

            /*
             * As the issuing authority writes it -- "EASA.A.221", "339/SP",
             * "A21CE". Deliberately not normalised, for the same reason the
             * column on the type is not: four authorities, four notations, and
             * the form on the document is the form somebody searches for.
             */
            $table->string('number', 64)->index();

            // easa | faa | lba | other
            $table->string('authority', 16)->nullable()->index();

            // Where this authority's sheet lives, where it has one. The Blaues
            // Buch has none -- it is a list, not a set of sheets.
            $table->text('data_sheet_url')->nullable();

            /*
             * The one mirrored into aircraft_types.type_certificate. Exactly one
             * per type, kept in step by the model.
             */
            $table->boolean('is_primary')->default(false)->index();

            $table->timestamps();
            $table->softDeletes();

            /*
             * The same number twice on one type is not a second certificate, it
             * is a double entry -- and it would make a directive appear twice on
             * the list. Soft deletes are why the index carries deleted_at: a
             * number withdrawn and later re-entered has to be possible.
             */
            $table->unique(['aircraft_type_id', 'number', 'deleted_at'], 'aircraft_type_certificates_unique');
        });

        /*
         * WHAT EVERY EXISTING TYPE ALREADY KNOWS. Without this the table starts
         * empty and every lookup that now asks it would answer "nothing" for
         * types that have had a number on file for months -- a migration that
         * silently empties a list is precisely the failure this module fears.
         */
        DB::table('aircraft_types')
            ->whereNotNull('type_certificate')
            ->where('type_certificate', '!=', '')
            ->orderBy('id')
            ->chunkById(200, function (iterable $types): void {
                $now = now();
                $rows = [];

                foreach ($types as $type) {
                    $rows[] = [
                        'aircraft_type_id' => $type->id,
                        'number' => $type->type_certificate,
                        'authority' => $type->certificate_authority ?: AircraftType::AUTHORITY_OTHER,
                        'data_sheet_url' => $type->data_sheet_url,
                        'is_primary' => true,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                }

                if ($rows !== []) {
                    DB::table('aircraft_type_certificates')->insert($rows);
                }
            });
    }

    public function down(): void
    {
        // The primary number lives on the type as well, so dropping this loses
        // only the additional ones -- which is what "rolling back this change"
        // means.
        Schema::dropIfExists('aircraft_type_certificates');
    }
};
