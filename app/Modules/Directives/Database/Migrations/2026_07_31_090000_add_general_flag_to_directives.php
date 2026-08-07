<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * General notes are an OFFER, not an obligation.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * A manufacturer's general notes are approved data from the design holder --
 * Vorgabe: "DG kann mir zum Beispiel per general TM erlauben ein fenster
 * einzubauen, was via cs stan nicht möglich ist." They are a way to make a
 * change legally, not a thing the aircraft is overdue on.
 *
 * So they behave differently in one specific way, and that is what this column
 * is for: a general note appears in an aircraft's overview only once it has
 * actually been CARRIED OUT on that aircraft. Before that it belongs to the
 * type, not to the aeroplane -- and listing fourteen unassessed lines against
 * every airframe would bury the ones that really are outstanding.
 *
 * Not derived from the source name, though every one of them arrives from a
 * "general" sheet today. A club that types one in by hand is making the same
 * statement about it, and that has to be recordable.
 * ─────────────────────────────────────────────────────────────────────────────
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('directives', function (Blueprint $table): void {
            $table->boolean('is_general')->default(false)->after('bindingness');

            // The list a person opens when deciding whether to fit something is
            // always "this type, general notes" -- never the whole table.
            $table->index(['is_general', 'subject_model']);
        });
    }

    public function down(): void
    {
        Schema::table('directives', function (Blueprint $table): void {
            $table->dropIndex(['is_general', 'subject_model']);
            $table->dropColumn('is_general');
        });
    }
};
