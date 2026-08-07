<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Every change of condition a lot goes through -- append-only.
 *
 * A single set of columns on the lot would only remember the latest change, and
 * the awkward questions are about earlier ones: who released this back into
 * service, and under which licence?
 *
 * Two kinds of entry live here, and the difference is decision E7:
 *
 *  - Precautionary. Setting something aside because its paperwork has not
 *    arrived. Anyone may do it, it is reversible, and a plain reference to the
 *    person is enough.
 *  - Determined. Unserviceable, unsalvageable, or fit for service again. A
 *    qualified act under E8, so the person's name and the credential they
 *    relied on are COPIED here -- not referenced. A determination has to stay
 *    readable after the account has been pseudonymised or the licence renewed
 *    under a new number.
 *
 * The quarantine tag number lives here too: it is issued when the lot is set
 * aside, never reused, and the printed slip stays on the part.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lot_state_changes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('stock_lot_id')->constrained()->cascadeOnUpdate()->restrictOnDelete();

            $table->string('from_state', 32);
            $table->string('to_state', 32);
            $table->text('reason');

            // YYYYMM-NNN, assigned when a lot is set aside. Never reused, even
            // if the block is lifted again -- the slip was printed and hung on
            // the part.
            $table->string('quarantine_tag', 16)->nullable()->unique();

            // Who did it. Nullable so the entry survives the account going.
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();

            // Certificate content, copied at the moment of the act (E7).
            // Empty for precautionary entries, which need no qualification.
            $table->string('determined_by_name', 255)->nullable();
            $table->string('qualification_type', 64)->nullable();
            $table->string('qualification_reference', 128)->nullable();
            $table->string('qualification_category', 64)->nullable();
            $table->date('qualification_valid_until')->nullable();

            $table->timestamp('occurred_at')->index();
            $table->timestamp('created_at')->nullable();

            $table->index(['stock_lot_id', 'occurred_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lot_state_changes');
    }
};
