<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One list, not two.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * The flag existed so a manufacturer's general notes could be kept off an
 * aircraft's open points until somebody recorded one as carried out, and shown
 * instead on a list of their own. Vorgabe: "wir sollten die 2. liste für general
 * aus der ui nehmen und auch die optionalen einfach normal in die liste
 * einbinden. das fühlt sich sauberer an."
 *
 * It is cleaner, and not only in the interface. BINDINGNESS ALREADY SAID ALL OF
 * IT: an optional line may be declined and answered for, a mandatory one may
 * not. A second flag on top of that could only ever agree with it or contradict
 * it -- and it did contradict it, on every sheet measured. Schleicher's general
 * notes are 17 binding of 18, SZD's 9 of 13; filed as offers, the EASA SIB
 * Schleicher passes on would have sat outside the open points of every aircraft
 * it applies to.
 *
 * Nothing is lost with the column. Where a line came from is still recorded --
 * the source is "schleicher-allgemein" or "szd-general" -- and what it demands
 * is in its bindingness, where it belongs.
 * ─────────────────────────────────────────────────────────────────────────────
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('directives', function (Blueprint $table): void {
            $table->dropIndex(['is_general', 'subject_model']);
            $table->dropColumn('is_general');
        });
    }

    public function down(): void
    {
        Schema::table('directives', function (Blueprint $table): void {
            $table->boolean('is_general')->default(false)->after('bindingness');
            $table->index(['is_general', 'subject_model']);
        });
    }
};
