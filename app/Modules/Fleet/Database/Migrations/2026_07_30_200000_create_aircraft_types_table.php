<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Aircraft types, with their data sheet.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * WHY A TABLE AND NOT A FIELD ON THE AIRCRAFT.
 *
 * Vorgabe: "wir sollten da das kennblatt mitführen." A field per aircraft would
 * have been quicker and wrong in two ways.
 *
 * First, three ASK 21s in one hangar could then carry three different
 * Kennblatt numbers, and nothing would notice. A type certificate is a property
 * of the TYPE -- one document, one number, however many airframes.
 *
 * Second, and this is the part that pays for the migration: the LTA/TM module
 * currently decides applicability by comparing model strings, tolerating
 * "ASK 21" against "ASK 21 B" in both directions because a club's spelling
 * cannot be trusted. With a type record, a directive can point at the type and
 * the match becomes exact. The fuzzy comparison stays as the fallback for
 * aircraft that have no type assigned -- it must, since the requirement was for free
 * text to remain possible.
 *
 * `model` stays on the aircraft. It is not redundant: the type is optional, a
 * club may fly something nobody has catalogued, and typing a name must keep
 * working. The type is the better answer where it exists, not the only one.
 * ─────────────────────────────────────────────────────────────────────────────
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('aircraft_types', function (Blueprint $table): void {
            $table->id();

            // "ASK 21", "DG-300 Elan". What a person calls it.
            $table->string('designation', 96);
            $table->string('manufacturer', 160)->nullable();

            /*
             * The Kennblatt / TCDS number, as the issuing authority writes it:
             * "EASA.A.221", "A21CE", "322". Free text on purpose -- four
             * authorities, four notations, and normalising them would lose the
             * form somebody reads off a document.
             */
            $table->string('type_certificate', 64)->nullable()->index();

            // easa | faa | lba | other -- who issued it. Decides which adapter
            // can look it up, and how to read the number.
            $table->string('certificate_authority', 16)->nullable()->index();

            /*
             * Where the data sheet lives. Vorgabe: "das Datenblatt sollte dabei
             * verlinkt werden können." A URL always; the file itself optionally,
             * through medialibrary, for the club that wants it in its own hands.
             */
            $table->text('data_sheet_url')->nullable();
            $table->date('data_sheet_checked_at')->nullable();

            /*
             * The manufacturer's own TM/LTA overview for this type.
             *
             * Vorgabe: "es sollte jeweils übersichtslisten geben. die reichen und
             * können zum hersteller verlinken." Schleicher publishes exactly such
             * a PDF per type, and its header is where the Kennblatt number came
             * from in the first place.
             */
            $table->text('directive_overview_url')->nullable();

            // Which source found this, so a refresh can update its own rows and
            // leave hand-entered ones alone -- the same rule as the directives.
            $table->string('source', 64)->default('manual')->index();

            $table->text('note')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->unique('designation');
        });

        Schema::table('aircraft', function (Blueprint $table): void {
            $table->foreignId('aircraft_type_id')
                ->nullable()
                ->after('model')
                ->constrained('aircraft_types')
                ->nullOnDelete();
        });

        /*
         * Backfill: every distinct model already in the fleet becomes a type, and
         * its aircraft point at it.
         *
         * Done here rather than left to the user because the alternative is a
         * fleet where nothing has a type until somebody clicks through it, and
         * the exact directive matching would silently never engage. The types
         * arrive without a Kennblatt -- that is the one thing only a human or an
         * adapter can supply.
         */
        $models = DB::table('aircraft')
            ->whereNotNull('model')
            ->where('model', '<>', '')
            ->distinct()
            ->pluck('model');

        foreach ($models as $model) {
            $designation = trim((string) $model);

            if ($designation === '') {
                continue;
            }

            $id = DB::table('aircraft_types')->insertGetId([
                'designation' => $designation,
                'source' => 'manual',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            DB::table('aircraft')
                ->where('model', $model)
                ->update(['aircraft_type_id' => $id]);
        }
    }

    public function down(): void
    {
        Schema::table('aircraft', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('aircraft_type_id');
        });

        Schema::dropIfExists('aircraft_types');
    }
};
