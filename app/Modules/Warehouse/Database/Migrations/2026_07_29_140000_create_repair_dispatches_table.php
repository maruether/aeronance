<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Parts that left the store to be repaired.
 *
 * The gap this closes: a part sent away for repair is off the shelf but still
 * the club's, and until now it simply vanished from the books at the moment it
 * went into the parcel. "Where is the tow release?" had no answer.
 *
 * This is also the one lawful way a club without a component rating gets a
 * Form 1 for a part it already owns. A removal lot is tied to the aircraft it
 * came out of; send it to an approved shop, and what comes back carries that
 * shop's certificate and travels freely. The restriction is not worked around,
 * it is discharged.
 *
 * The destination is either an outside organisation or -- if a component repair
 * module is ever installed -- the club's own shop. That second option is
 * declared here and gated on the module, so the column does not have to change
 * later.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('repair_dispatches', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('part_type_id')->constrained()->cascadeOnDelete();

            // Null for parts kept as a plain quantity. Those are rarely
            // repaired, but refusing outright would be a rule without a reason.
            $table->foreignId('stock_lot_id')->nullable()->constrained()->nullOnDelete();

            $table->decimal('quantity', 12, 3);

            // Copied, not referenced: which item went away has to stay readable
            // even if the source lot is later corrected or emptied.
            $table->string('serial_number', 128)->nullable();

            // external | in_house
            $table->string('destination', 16)->default('external')->index();

            $table->string('shop_name', 160)->nullable();

            // Whoever will sign the Form 1 has to be approved to do so, and the
            // number is the only part of that which is worth recording here.
            $table->string('shop_approval', 64)->nullable();

            $table->string('dispatch_reference', 128)->nullable();
            $table->text('reason');

            // Carried along so the aircraft restriction survives the journey.
            // If the part comes back without a certificate, it is still only
            // good for the aircraft it started in.
            $table->string('restricted_to_aircraft', 32)->nullable()->index();
            $table->string('aircraft_type', 64)->nullable();

            $table->date('dispatched_at')->index();
            $table->foreignId('dispatched_by')->nullable()->constrained('users')->nullOnDelete();
            $table->date('expected_back_at')->nullable()->index();

            // dispatched | returned | written_off
            $table->string('state', 16)->default('dispatched')->index();

            $table->date('returned_at')->nullable();
            $table->foreignId('returned_by')->nullable()->constrained('users')->nullOnDelete();

            // The lot the part came back in. A new one rather than the old one,
            // because a lot is a quantity covered by ONE certificate, and after
            // a repair the certificate is a different document.
            $table->foreignId('returned_lot_id')->nullable()->constrained('stock_lots')->nullOnDelete();
            $table->text('return_note')->nullable();

            $table->timestamps();
            $table->softDeletes();
        });

        Schema::table('stock_lots', function (Blueprint $table): void {
            // Which dispatch a returned lot came out of, so the chain
            // lot -> repair -> lot stays walkable in both directions.
            $table->foreignId('repair_dispatch_id')
                ->nullable()
                ->after('removal_reason')
                ->constrained('repair_dispatches')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('stock_lots', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('repair_dispatch_id');
        });

        Schema::dropIfExists('repair_dispatches');
    }
};
