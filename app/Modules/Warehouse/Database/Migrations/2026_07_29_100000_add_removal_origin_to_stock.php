<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A lot can now come from an aircraft, not only from a supplier.
 *
 * The case the brief needs for instruments: something is taken out of D-KABC,
 * stored, and fitted again later. Until now a lot could only come into being at
 * goods-in, so a removed part had nowhere to live.
 *
 * The aircraft is a plain string with no foreign key. Aircraft belong to the
 * fleet module, which need not be installed (D4) -- and when it is, the fleet
 * supplies these values at removal and the warehouse writes them down. The
 * warehouse receives that truth rather than owning it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stock_lots', function (Blueprint $table): void {
            // supplier | removal
            $table->string('origin', 16)->default('supplier')->after('part_type_id')->index();

            $table->string('removed_from_aircraft', 32)->nullable()->after('origin')->index();
            $table->string('removed_from_aircraft_type', 64)->nullable()->after('removed_from_aircraft');
            $table->date('removed_at')->nullable()->after('removed_from_aircraft_type');
            $table->text('removal_reason')->nullable()->after('removed_at');
        });

        Schema::table('part_types', function (Blueprint $table): void {
            /*
             * Not every life limit means the same thing, and treating them alike
             * would block exactly the case worth the effort.
             *
             *   none          no limit
             *   on_condition  used until it fails inspection
             *   tbo           overhaul interval, sometimes for life -- a tow
             *                 release is overhauled and fitted again, which is
             *                 precisely why one wants it tracked
             *   tbr           replacement interval -- spark plugs, hoses. These
             *                 are not reused, so they get no path back into stock
             */
            $table->string('life_limit_type', 16)->default('none')->after('shelf_life_days')->index();
        });
    }

    public function down(): void
    {
        Schema::table('part_types', function (Blueprint $table): void {
            $table->dropColumn('life_limit_type');
        });

        Schema::table('stock_lots', function (Blueprint $table): void {
            $table->dropColumn([
                'origin',
                'removed_from_aircraft',
                'removed_from_aircraft_type',
                'removed_at',
                'removal_reason',
            ]);
        });
    }
};
