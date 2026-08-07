<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * TSN and TSO are two different numbers.
 *
 * the case, and it breaks the first version cleanly:
 *
 *   Engine A goes to the manufacturer, who performs an overhaul and RESETS THE
 *   TSO TO ZERO. The TSN carries on.
 *   Engine B goes to the SAME manufacturer for a repair, and the TSO is NOT
 *   reset.
 *
 * Two identical journeys, two different outcomes. Which means the reset can
 * never be inferred from a repair having happened -- only from what the returning
 * paperwork says was done. Anything that concluded "came back from the shop,
 * therefore overhauled" would zero the life of engine B and quietly hand it a
 * second full run between overhauls.
 *
 * The old carried_usage was one figure per counter and could express neither.
 * Now there are two accumulators, and they differ only in where they start:
 * after fitting, both advance by the same aircraft hours. An overhaul sets the
 * since-overhaul figure back to nil and leaves the since-new one alone -- which
 * is precisely what a GÜ does on paper.
 *
 * the second remark is handled by the defaults rather than by a flag: for a
 * component that has no overhaul concept at all, TSO simply equals TSN, so an
 * absent since-overhaul figure falls back to the since-new one. Most parts never
 * need to think about it; the ones whose paperwork does, can.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('installations', function (Blueprint $table): void {
            // Total time, since the part was made. Never reset by anything.
            $table->json('carried_since_new')->nullable()->after('counters_at_installation');

            /*
             * Time since the last overhaul. Null means "same as since new",
             * which is the truth for a part that has never been overhauled and
             * for every part with no overhaul concept.
             */
            $table->json('carried_since_overhaul')->nullable()->after('carried_since_new');

            /*
             * When the overhaul that reset the TSO was carried out, and what
             * says so. Recorded because the reset is an assertion about a
             * component's life, and an assertion with no document behind it is
             * the kind an audit asks about.
             */
            $table->date('overhauled_at')->nullable()->after('carried_since_overhaul');
            $table->string('overhaul_reference', 128)->nullable()->after('overhauled_at');
        });

        // The old single figure was "what it had already done" with no statement
        // about which of the two it meant. Read as since-new, which is what it
        // was used for, and leave since-overhaul to fall back to it.
        DB::statement('UPDATE installations SET carried_since_new = carried_usage WHERE carried_usage IS NOT NULL');

        Schema::table('installations', function (Blueprint $table): void {
            $table->dropColumn('carried_usage');
        });

        Schema::table('component_limits', function (Blueprint $table): void {
            /*
             * What this limit is measured from: since_new or since_overhaul.
             *
             * A TBO is measured since the last overhaul -- that is what the O
             * stands for. A hard life limit is measured since new: 12 000 hours
             * total means the part is finished at 12 000 whatever has been done
             * to it in between. Reading a TBO against TSN would condemn a
             * freshly overhauled engine; reading a life limit against TSO would
             * fly one for ever.
             *
             * Default since_overhaul, because the overwhelming majority of
             * limits in a club fleet are overhaul intervals -- and where a part
             * has never been overhauled the two are the same number anyway.
             */
            $table->string('basis', 20)->default('since_overhaul')->after('kind');
        });
    }

    public function down(): void
    {
        Schema::table('component_limits', function (Blueprint $table): void {
            $table->dropColumn('basis');
        });

        Schema::table('installations', function (Blueprint $table): void {
            $table->json('carried_usage')->nullable()->after('counters_at_installation');
        });

        DB::statement('UPDATE installations SET carried_usage = carried_since_new WHERE carried_since_new IS NOT NULL');

        Schema::table('installations', function (Blueprint $table): void {
            $table->dropColumn(['carried_since_new', 'carried_since_overhaul', 'overhauled_at', 'overhaul_reference']);
        });
    }
};
