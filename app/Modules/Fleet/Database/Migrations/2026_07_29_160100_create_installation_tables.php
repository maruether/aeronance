<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What is fitted to an aircraft, and what it is limited by.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * NO FOREIGN KEY TO THE WAREHOUSE, and that is not an oversight.
 *
 * A module may not reach into another module's tables. The fleet has to work in
 * a club that never installed the warehouse, and the warehouse already keeps its
 * side of the same chain the same way: it records an aircraft registration as a
 * plain string with no key, because the fleet need not exist either (D4).
 *
 * So both sides hold a loose reference and the chain is walkable from either
 * end -- warehouse movement carries the registration, installation carries the
 * lot number -- without either owning the other.
 *
 * The certificate is COPIED here rather than looked up, which is the same rule
 * as E7 and for the same reason: the analysis settled that a Form 1 ending up in
 * several aircraft is "keine Verschiebung, sondern Vervielfältigung durch
 * Referenz". Four oil filters from one lot go to four aircraft; the document
 * stays one record, and each life record carries its content.
 * ─────────────────────────────────────────────────────────────────────────────
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('installations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('aircraft_id')->constrained()->cascadeOnDelete();

            /*
             * What was fitted. The name is copied because a part type may be
             * renamed, and a life record that changes wording years later is a
             * life record an auditor cannot rely on.
             */
            $table->string('part_name', 160);
            $table->string('part_number', 128)->nullable();

            // Loose references across the module boundary -- no constraint.
            $table->unsignedBigInteger('stock_lot_id')->nullable()->index();
            $table->string('stock_lot_number', 32)->nullable()->index();
            $table->unsignedBigInteger('part_type_id')->nullable()->index();

            $table->string('serial_number', 128)->nullable()->index();
            $table->decimal('quantity', 12, 3)->default(1);

            /*
             * The paper that came with it. Vorgabe: "wenn ein Form 1 oder CoC
             * dranhängt geht das papier mit aufs flugzeug über. das muss erfasst
             * sein." Copied, so it survives the lot being emptied and the file
             * being handed on.
             */
            $table->string('document_type', 32)->default('none');
            $table->string('document_reference', 128)->nullable();
            $table->string('document_issuer', 160)->nullable();
            $table->string('document_issuer_approval', 64)->nullable();
            $table->date('document_issued_at')->nullable();

            /** Where on the aircraft -- "Schleppkupplung Rumpf", "ASI links". */
            $table->string('position', 160)->nullable();

            $table->date('installed_at')->index();
            $table->foreignId('installed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('installed_by_name', 160)->nullable();
            $table->string('work_order_reference', 64)->nullable();

            /*
             * The aircraft's counters at the moment of fitting.
             *
             * A component's usage is the aircraft's counter now minus its
             * counter then -- so this snapshot is what makes any counted limit
             * answerable. Without it, refitting a part to a different aircraft
             * would silently restart its life.
             */
            $table->json('counters_at_installation')->nullable();

            /*
             * What the part had already done before it got here.
             *
             * A tow release that comes back from overhaul with 300 launches on
             * it does not start at zero, and a used part fitted from stock may
             * carry history the shelf never knew about.
             */
            $table->json('carried_usage')->nullable();

            $table->date('removed_at')->nullable()->index();
            $table->foreignId('removed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->json('counters_at_removal')->nullable();
            $table->text('removal_reason')->nullable();

            $table->text('note')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['aircraft_id', 'removed_at']);
        });

        /*
         * The limits on one fitted component.
         *
         * SEVERAL PER COMPONENT, OF DIFFERENT KINDS, and the earliest wins.
         * the example is the whole reason this is a table: a Tost tow
         * release runs "2 Jahre oder 500 Starts, whatever comes first".
         *
         * Columns per kind would have turned that into two half-filled fields
         * that nothing compares -- and the comparison IS the answer. As rows,
         * "what is due" is a minimum over a set, and a third limit is a row
         * rather than a migration.
         *
         * Note also what follows from the other remark: not every component
         * has a life at all. "Ein Ölfilter geht z.B. automatisch mit der
         * Motorwartung und ein neuer kommt." So this table is often empty for a
         * given installation, and that is the normal case, not a gap.
         */
        Schema::create('component_limits', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('installation_id')->constrained()->cascadeOnDelete();

            // calendar_months | calendar_date | flight_hours | landings |
            // engine_hours | starts | cycles
            $table->string('kind', 24)->index();

            /** The interval, or the count. Null when a fixed date is given. */
            $table->decimal('value', 12, 2)->nullable();

            /** Used by calendar_date, where the paper names a day. */
            $table->date('due_on')->nullable();

            /** TBO, TBR, AD, manufacturer bulletin -- where the limit comes from. */
            $table->string('source', 160)->nullable();

            $table->text('note')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('component_limits');
        Schema::dropIfExists('installations');
    }
};
