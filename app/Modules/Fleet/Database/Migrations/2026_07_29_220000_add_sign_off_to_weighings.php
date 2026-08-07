<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A weighing that has been signed off is frozen.
 *
 * Vorgabe: "eine einmal abgezeichnete Wägung ist unveränderlich. Wenn ich also
 * auf speichern und drucken gehe werden die werte unveränderlich festgesetzt."
 *
 * Which is the rule this system already keeps everywhere else -- stock movements
 * refuse to be edited, counter readings refuse to be deleted, a release is
 * frozen once given. The reasoning is the same each time: the document is the
 * evidence, and evidence that can be revised afterwards is not evidence.
 *
 * A correction is a NEW weighing, never a changed one. That is not extra work
 * imposed by the software -- it is what happens on paper too, because the old
 * sheet has somebody's signature on it and cannot be quietly improved.
 *
 * Note what this does to the drift check added a moment ago. Before, the two
 * causes of a mismatch were "somebody edited the rows" and "the calculation
 * changed". Once the rows cannot be edited, only the second remains -- so a
 * signed-off report that no longer matches its own arithmetic is telling us
 * something about the code, which is exactly the more interesting half.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('weighings', function (Blueprint $table): void {
            $table->timestamp('signed_off_at')->nullable()->after('remarks')->index();
            $table->foreignId('signed_off_by')->nullable()->after('signed_off_at')
                ->constrained('users')->nullOnDelete();

            // Copied at the moment of signing, like every other name that ends
            // up in a record (E7/E3a).
            $table->string('signed_off_by_name', 160)->nullable()->after('signed_off_by');
        });
    }

    public function down(): void
    {
        Schema::table('weighings', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('signed_off_by');
            $table->dropColumn(['signed_off_at', 'signed_off_by_name']);
        });
    }
};
