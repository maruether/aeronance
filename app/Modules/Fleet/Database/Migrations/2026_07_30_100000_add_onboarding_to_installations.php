<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Where a life-record line came from, and who wrote it down.
 *
 * A correction to my framing: an aircraft joining the operation is not a
 * migration. "Selbst wenn ich ein nagelneues flugzeug kaufe sind da schon
 * bauteile drin ... Der vogel mag seit 60 Jahren fliegen, ist aber für den
 * Betrieb neu. Das nennt sich onboarding, nicht migration."
 *
 * Which makes it a recurring business event rather than a one-off import, and
 * therefore something the module owes a proper path -- with the one thing that
 * makes such a path safe: the line says, permanently, that it was transcribed
 * from documents rather than witnessed here.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('installations', function (Blueprint $table): void {
            // stock | onboarding
            $table->string('origin', 16)->default('stock')->after('aircraft_id')->index();

            /*
             * Which paper it was copied from. Required for an onboarded line and
             * meaningless for a stock one, because there the document is already
             * on the lot.
             *
             * "Betriebszeitenübersicht des Vorbetriebs vom 12.03.2019" is a
             * usable answer to "how do you know"; an empty field is not.
             */
            $table->string('transcribed_from', 255)->nullable()->after('origin');

            $table->date('transcribed_at')->nullable()->after('transcribed_from');
            $table->foreignId('transcribed_by')->nullable()->after('transcribed_at')
                ->constrained('users')->nullOnDelete();

            // Copied at the moment of writing, like every other name in a record.
            $table->string('transcribed_by_name', 160)->nullable()->after('transcribed_by');
        });

        Schema::table('aircraft', function (Blueprint $table): void {
            /*
             * When the aircraft joined THIS operation -- which is not when it
             * was built and not when it was registered.
             *
             * Separate from in_service_since, deliberately: a glider may have
             * been flying since 1964 and have been ours since March. Both dates
             * are true, and conflating them would lose the one that answers
             * "since when are we responsible for it".
             */
            $table->date('onboarded_at')->nullable()->after('in_service_since');
        });
    }

    public function down(): void
    {
        Schema::table('aircraft', function (Blueprint $table): void {
            $table->dropColumn('onboarded_at');
        });

        Schema::table('installations', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('transcribed_by');
            $table->dropColumn(['origin', 'transcribed_from', 'transcribed_at', 'transcribed_by_name']);
        });
    }
};
