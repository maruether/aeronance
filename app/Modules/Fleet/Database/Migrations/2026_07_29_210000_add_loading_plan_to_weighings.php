<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The loading plan.
 *
 * The weighing says where the aircraft's centre of gravity sits empty. The
 * loading plan answers the question the pilot actually has: how much may sit in
 * the seat.
 *
 * That needs two things the sheet did not carry:
 *
 *  - THE IN-FLIGHT CG LIMITS, which are not the empty-mass ones. The
 *    Massenübersicht records "Schwerpunktbereich laut Flughandbuch ... bei
 *    Leermasse", and the powered sheet separately lists "Zulässige
 *    Fluggewicht-Schwerpunktlagen". Two different pairs of numbers, and using
 *    the empty range to work out a seat load would be wrong in the direction
 *    that lets somebody heavy sit down.
 *
 *  - THE SEAT ARMS, which live as their own section of entries, because a
 *    two-seater has two of them at different distances and the answer for one
 *    depends on what is in the other.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('weighings', function (Blueprint $table): void {
            $table->decimal('flight_cg_from_mm', 10, 2)->nullable()->after('cg_range_to_mm');
            $table->decimal('flight_cg_to_mm', 10, 2)->nullable()->after('flight_cg_from_mm');
        });
    }

    public function down(): void
    {
        Schema::table('weighings', function (Blueprint $table): void {
            $table->dropColumn(['flight_cg_from_mm', 'flight_cg_to_mm']);
        });
    }
};
