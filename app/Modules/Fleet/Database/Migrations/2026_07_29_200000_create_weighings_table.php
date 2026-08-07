<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The weighing report.
 *
 * the correction, and it was needed: the lever arms in the equipment list
 * are only the material one calculates WITH when something comes out. They are
 * not the weighing. The weighing is its own signed document with its own
 * arithmetic, and the figure it produces -- empty mass and where its centre of
 * gravity sits -- is what everything else refers back to.
 *
 * TWO SHAPES, from the BWLV forms:
 *
 *   Gliders are weighed COMPONENT BY COMPONENT -- each wing inner and outer,
 *   fuselage, tailplane, trim weights -- and each row carries two figures,
 *   because the mass of the non-lifting parts has a limit of its own.
 *
 *   Aeroplanes and motor gliders are weighed ON SUPPORTS and reduced by what
 *   can be flown off: usable fuel and oil, per tank, at a fixed density.
 *
 * The forms for aeroplane and motor glider turn out to be the same document with
 * a different heading, so there are two kinds here and not three.
 *
 * BOTH INPUTS AND RESULTS ARE STORED. The results could be recomputed, and for a
 * while that seems tidier -- but this is a signed document, and its numbers are
 * its content (E7). Recomputing a 2019 report with 2027 code would quietly
 * republish somebody else's signature over an answer they never gave.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('weighings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('aircraft_id')->constrained()->cascadeOnDelete();

            // glider | powered
            $table->string('kind', 16)->index();

            $table->date('weighed_at')->index();
            $table->string('place', 120)->nullable();
            $table->string('order_reference', 64)->nullable();

            /*
             * A weighing does not last for ever, and how long depends on the
             * type and on what has been done to the aircraft since. Entered
             * rather than derived, and it feeds the due list like any other
             * deadline.
             */
            $table->date('valid_until')->nullable()->index();

            /* Datum definitions, copied from the type certificate data sheet. */
            $table->string('datum_reference', 160)->nullable();
            $table->string('reference_line', 160)->nullable();

            /*
             * The distance from the datum to the front support, SIGNED.
             *
             * The BWLV sheet draws two formulas -- (G2*b)/G minus a, and the
             * same plus a -- which look like two cases and are one. They differ
             * only in whether the datum sits ahead of the front support or
             * behind it, so a signed arm collapses them into a single equation.
             * Two boxes on the paper, one calculation here.
             */
            $table->integer('front_support_arm_mm')->nullable();

            /** Distance between the supports. */
            $table->integer('support_distance_mm')->nullable();

            /* ---- results, computed at save and then frozen ---- */
            $table->decimal('empty_mass_kg', 10, 2)->nullable();
            $table->decimal('empty_cg_mm', 10, 2)->nullable();
            $table->decimal('non_lifting_mass_kg', 10, 2)->nullable();
            $table->decimal('useful_load_kg', 10, 2)->nullable();

            /* ---- limits from the type certificate, for the verdict ---- */
            $table->decimal('cg_range_from_mm', 10, 2)->nullable();
            $table->decimal('cg_range_to_mm', 10, 2)->nullable();
            $table->decimal('max_mass_kg', 10, 2)->nullable();
            $table->decimal('max_mass_water_kg', 10, 2)->nullable();
            $table->decimal('max_non_lifting_kg', 10, 2)->nullable();
            $table->decimal('cockpit_load_min_kg', 10, 2)->nullable();
            $table->decimal('cockpit_load_max_kg', 10, 2)->nullable();

            /* ---- who signed ---- */
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('signed_by_name', 160)->nullable();
            $table->string('signed_by_approval', 64)->nullable();

            /** Which equipment list the aircraft was carrying when it was weighed. */
            $table->date('equipment_list_dated')->nullable();

            $table->text('remarks')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        /*
         * The rows of the sheet.
         *
         * One table for three shapes rather than three tables, because they are
         * three sections of one form and are always read together. Each row
         * fills in the columns its section has and leaves the rest empty, which
         * is exactly what the paper does.
         */
        Schema::create('weighing_entries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('weighing_id')->constrained()->cascadeOnDelete();

            // component | support | deduction
            $table->string('section', 16)->index();

            $table->string('label', 160);
            $table->unsignedSmallInteger('position')->default(0);

            /* component: the two figures a glider row carries */
            $table->decimal('mass_kg', 10, 2)->nullable();
            $table->decimal('non_lifting_kg', 10, 2)->nullable();

            /* support: what the scale said, and where it stood */
            $table->decimal('gross_kg', 10, 2)->nullable();
            $table->decimal('tare_kg', 10, 2)->nullable();
            $table->decimal('arm_mm', 10, 2)->nullable();

            /* deduction: usable fuel and oil, by volume at a fixed density */
            $table->decimal('volume_litres', 10, 2)->nullable();
            $table->decimal('density_kg_per_litre', 6, 3)->nullable();

            $table->text('note')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('weighing_entries');
        Schema::dropIfExists('weighings');
    }
};
