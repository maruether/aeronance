<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Work given to somebody else.
 *
 * Vorgabe: "Es kann sein das ich eine Wartung oder Reparatur extern vergebe. Wenn
 * dabei Teile reinkommen muss ich das irgendwie dokumentieren. Es ist dabei
 * offen ob ich selbst freigebe oder die fremdwerft."
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * TWO THINGS MAKE THIS ITS OWN RECORD RATHER THAN A NOTE.
 *
 * 1. PARTS ARRIVE THAT NEVER TOUCHED OUR STORE. They came out of somebody
 *    else's stock and went into our aircraft, during a period when the aircraft
 *    was still our responsibility. That is a third provenance, distinct from
 *    both the ones already modelled: not witnessed by us like a stock issue, and
 *    not historical like an onboarding transcription.
 *
 * 2. WHO SIGNED IS GENUINELY OPEN. The shop may release its own work, or we may
 *    release it on the strength of their report -- and those are different
 *    positions. Signing for work somebody else performed means answering for
 *    having accepted it, and a record that cannot tell the two apart cannot
 *    answer the only question anybody will ask afterwards.
 * ─────────────────────────────────────────────────────────────────────────────
 *
 * The boundary to the task card module, which the brief flagged: this records an
 * EVENT AFFECTING AN AIRCRAFT -- who had it, what came back, who signed. The
 * tasks, findings and hours belong to work cards, and when that module arrives
 * an order may reference one. The life-record side stands on its own either way.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('external_work_orders', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('aircraft_id')->constrained()->cascadeOnDelete();

            $table->string('shop_name', 160);

            /*
             * Their approval number. Needed whichever way the release goes: if
             * they sign, it is the authority behind the signature; if we sign,
             * it is what we relied on when we accepted the work.
             */
            $table->string('shop_approval', 64)->nullable();

            $table->string('order_reference', 128)->nullable();
            $table->text('scope');

            $table->date('sent_at')->index();
            $table->date('expected_back_at')->nullable()->index();
            $table->date('returned_at')->nullable()->index();

            $table->foreignId('sent_by')->nullable()->constrained('users')->nullOnDelete();

            // commissioned | returned | released | cancelled
            $table->string('state', 16)->default('commissioned')->index();

            /* ---- the release, whichever way it went ---- */

            // external | internal
            $table->string('released_by', 16)->nullable()->index();

            $table->date('released_at')->nullable();
            $table->string('release_reference', 128)->nullable();

            /*
             * Certificate content, copied at the moment of the act (E7).
             *
             * For an external release these describe the shop's signatory; for
             * an internal one, ours -- and in that case the qualification fields
             * carry the licence somebody signed under, because accepting
             * somebody else's work is a determination like any other.
             */
            $table->string('released_by_name', 160)->nullable();
            $table->string('released_by_approval', 64)->nullable();
            $table->foreignId('released_by_user')->nullable()->constrained('users')->nullOnDelete();
            $table->string('qualification_type', 64)->nullable();
            $table->string('qualification_reference', 128)->nullable();
            $table->string('qualification_category', 64)->nullable();

            $table->text('report_reference')->nullable();
            $table->text('note')->nullable();

            $table->timestamps();
            $table->softDeletes();
        });

        Schema::table('installations', function (Blueprint $table): void {
            $table->foreignId('external_work_order_id')->nullable()->after('transcribed_by_name')
                ->constrained('external_work_orders')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('installations', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('external_work_order_id');
        });

        Schema::dropIfExists('external_work_orders');
    }
};
