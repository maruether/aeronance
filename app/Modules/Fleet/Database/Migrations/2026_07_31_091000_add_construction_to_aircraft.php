<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What an aircraft is made of, and how it is driven.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * THIS EXISTS BECAUSE LICENCES ARE LIMITED. A Part-66 licence may carry the
 * endorsement "ausgenommen Zellen in Metallbauweise" (point 66.A.50: limitations
 * are exclusions from the certifying privileges). Answering whether that
 * endorsement stands in the way of signing off a job needs one fact nobody was
 * recording: what the aircraft is built of.
 *
 * The core states the rule and never sees these columns; the check is handed a
 * WorkSubject built here. See App\Core\Access\WorkSubject.
 * ─────────────────────────────────────────────────────────────────────────────
 *
 * BOTH ARE NULLABLE, and null means "nobody wrote it down" rather than "none".
 * That distinction has teeth: for a holder with a matching limitation, an
 * unrecorded airframe is a refusal, because a signature that cannot be shown to
 * be covered must not be given. Everybody else is unaffected -- which is the
 * point, since the club that has no restricted licences should not have to fill
 * in a field to keep working.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('aircraft', function (Blueprint $table): void {
            /*
             * A list, because mixed construction is the normal case in gliding:
             * an ASK 13 is a steel tube fuselage with wooden wings. A limitation
             * bites if it excludes any one of them.
             *
             * JSON rather than a join table, like optional_counters above it: a
             * short list of enum values, always read with its aircraft, never
             * queried across.
             */
            $table->json('airframe_constructions')->nullable()->after('year_built');

            // One value: an aircraft has one kind of power plant or none.
            $table->string('propulsion', 16)->nullable()->after('airframe_constructions');
        });
    }

    public function down(): void
    {
        Schema::table('aircraft', function (Blueprint $table): void {
            $table->dropColumn(['airframe_constructions', 'propulsion']);
        });
    }
};
