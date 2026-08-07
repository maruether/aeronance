<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Stock: lots and movements.
 *
 * The central departure from the legacy schema. There is NO current-quantity
 * column anywhere -- stock is the sum of its movements (decision E1). That
 * makes a correction a counter-booking rather than an edit, makes the history
 * the ledger itself, and answers "where did this part come from" without a
 * separate audit trail that may or may not have been maintained.
 *
 * The lot is the traceable unit (4.5). A Form 1 covers a QUANTITY, identified
 * either by a serial number or by a batch number -- which is literally how the
 * form is laid out, blocks 9 and 10. A serialised part is simply a lot of one.
 *
 * A lot may be issued to several aircraft: four oil filters from one delivery
 * go to four different life records. So the certificate's DETAILS stay here
 * permanently even when the file itself moves on to the aircraft records
 * (4.7 f) -- otherwise the chain breaks exactly where it matters.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_lots', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('part_type_id')->constrained()->cascadeOnUpdate()->restrictOnDelete();

            // Internal, human-readable, unique. What goes on the shelf label.
            $table->string('lot_number', 32)->unique();

            // Exactly one of these, mirroring block 10 of the Form 1.
            $table->string('serial_number', 128)->nullable()->index();
            $table->string('batch_number', 128)->nullable();

            // form_one | certificate_of_conformity | none.
            $table->string('document_type', 32)->default('none');

            // Block 13 of the Form 1, copied rather than referenced: this is
            // certificate content and must stay readable after the file has
            // moved into the aircraft records. See E7 and 4.7 f.
            $table->string('document_reference', 128)->nullable()->index();
            $table->string('document_issuer', 255)->nullable();
            $table->string('document_issuer_approval', 128)->nullable();
            $table->date('document_issued_at')->nullable();
            $table->string('document_signatory', 255)->nullable();

            $table->foreignId('supplier_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('storage_compartment_id')->nullable()->constrained()->nullOnDelete();

            $table->date('received_at');

            // Calendar expiry, derived from the part type's shelf life at
            // receipt. Stored rather than computed: the shelf life may be
            // corrected later, and that must not silently revive expired stock.
            $table->date('expires_at')->nullable()->index();

            $table->string('state', 32)->default('serviceable')->index();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['part_type_id', 'state']);
            $table->index(['part_type_id', 'expires_at']);
        });

        Schema::create('stock_movements', function (Blueprint $table): void {
            $table->id();

            // Always present, so bulk stock -- standard parts kept without lots
            // -- can be booked against the part type alone.
            $table->foreignId('part_type_id')->constrained()->cascadeOnUpdate()->restrictOnDelete();

            // Absent for bulk stock, set for anything lot-tracked.
            $table->foreignId('stock_lot_id')->nullable()->constrained()->cascadeOnUpdate()->restrictOnDelete();

            $table->string('type', 32)->index();

            // Signed: positive adds, negative removes. Decimal because metres,
            // litres and kilograms do not come in whole numbers -- the legacy
            // schema used an integer while its form offered steps of 0.1.
            $table->decimal('quantity', 12, 3);

            $table->timestamp('occurred_at')->index();

            // Who booked it. Plain reference, no snapshot: this is a record, not
            // a certificate -- see E7. Nullable so the movement survives the
            // account being removed.
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();

            // Where the part went, when it went into a job. Plain strings and
            // deliberately NO foreign key: work orders and aircraft live in
            // other modules which need not be installed. See D4.
            $table->string('work_order_reference', 64)->nullable()->index();
            $table->string('aircraft_reference', 32)->nullable()->index();

            // A correction points at what it corrects; both stay visible.
            $table->foreignId('reverses_movement_id')->nullable()->constrained('stock_movements')->nullOnDelete();

            $table->text('note')->nullable();

            // Only created_at: a movement is never updated. The model refuses
            // it as well -- see StockMovement.
            $table->timestamp('created_at')->nullable();

            $table->index(['part_type_id', 'occurred_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_movements');
        Schema::dropIfExists('stock_lots');
    }
};
