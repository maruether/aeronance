<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Eingangsprüfung — the gate between the delivery van and the rack.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * WHY THIS IS ITS OWN MODULE AND NOT PART OF THE WAREHOUSE.
 *
 * A club that keeps a box of rivets and four oil filters does not want a
 * two-step goods-in with a signature. It wants to type "12 arrived" and get on
 * with the evening. Forcing an inspection on that club would not make anything
 * safer; it would make people book stock in wrong to get past the dialogue,
 * which is worse than not having the gate.
 *
 * A Part-145 shop, on the other hand, has no choice: 145.A.42 requires arriving
 * components to be classified before use, and the classification rests on
 * exactly the checks below. So it is a module -- off for the club, on for the
 * shop, same code.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * WHAT IT ATTACHES TO, AND WHY IT IS THE MOVEMENT.
 *
 * The obvious hook would be the lot: a lot is the traceable unit, it carries the
 * certificate, it can be quarantined. But standard parts are held as a pooled
 * quantity WITHOUT a lot -- and a delivery of standard parts is exactly the kind
 * that arrives with a conformity declaration nobody looks at. Hanging the
 * inspection on the lot would silently exempt them.
 *
 * The receipt movement always exists. So the inspection hangs there, and reaches
 * the lot through it when there is one.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * WHAT IT DOES *NOT* DO: it never moves stock by itself.
 *
 * Accepting lifts the quarantine the arrival put on the lot -- that is the
 * warehouse's own transition, performed through the warehouse's own action, with
 * the warehouse's own qualification rule. Rejecting moves nothing at all: the
 * goods stay quarantined, and whether they go back to the supplier, sit in the
 * corner, or get scrapped is a separate, deliberate warehouse act.
 *
 * This mirrors the split the warehouse already draws between a precautionary
 * block and a determination (E7/E8). An inspection is a STATEMENT about goods.
 * Statements and stock movements stay separable, or the record stops meaning
 * anything.
 * ─────────────────────────────────────────────────────────────────────────────
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('incoming_inspections', function (Blueprint $table): void {
            $table->id();

            /*
             * The arrival this inspection is about. Unique: one arrival, one
             * inspection. A second one would be a second opinion nobody can
             * tell apart from the first.
             *
             * cascadeOnDelete: if the receipt is ever wiped, an inspection of
             * nothing is noise. (Movements are not deleted in normal operation
             * -- corrections are reversals -- so this is a safety net.)
             */
            $table->foreignId('stock_movement_id')->unique()->constrained('stock_movements')->cascadeOnDelete();

            /*
             * Denormalised on purpose: the inspection list has to be readable
             * and searchable without joining half the warehouse, and these two
             * answer "what arrived" at a glance.
             */
            $table->foreignId('part_type_id')->constrained('part_types');
            $table->foreignId('stock_lot_id')->nullable()->constrained('stock_lots')->nullOnDelete();

            $table->string('state', 24)->default('open');

            $table->timestamp('arrived_at');

            /*
             * Who signed, and when. Name kept as text ALONGSIDE the id: the
             * user record can be renamed or the person can leave, and a signed
             * inspection has to keep saying who signed it. Same reasoning as the
             * qualification snapshot on a lot state change.
             */
            $table->foreignId('decided_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('decided_by_name')->nullable();
            $table->timestamp('decided_at')->nullable();

            /*
             * Mandatory when rejecting -- enforced in the action, not here,
             * because a database default cannot explain itself to the user.
             */
            $table->text('decision_note')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['state', 'arrived_at']);
        });

        Schema::create('incoming_inspection_checks', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('incoming_inspection_id')->constrained('incoming_inspections')->cascadeOnDelete();

            /*
             * One row per question. Relational rather than a JSON blob: "which
             * deliveries failed on the issuer's approval" is a question worth
             * being able to ask, and the guardrails rule out JSON path queries.
             */
            $table->string('item', 40);

            /** Null means unanswered -- and an unanswered item blocks signing. */
            $table->string('result', 24)->nullable();

            $table->text('note')->nullable();

            $table->timestamps();

            $table->unique(['incoming_inspection_id', 'item']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('incoming_inspection_checks');
        Schema::dropIfExists('incoming_inspections');
    }
};
