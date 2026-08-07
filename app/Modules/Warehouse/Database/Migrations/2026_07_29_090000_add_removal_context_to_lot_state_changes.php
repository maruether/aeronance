<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Where a blocked part came from.
 *
 * The quarantine tag the requirement described carries a registration and an aircraft
 * type, which says something about how these tags are used in practice: they go
 * on parts taken OFF an aircraft, not only on stock that arrived without its
 * paperwork.
 *
 * Both are optional, because both cases are real -- a lot blocked on the shelf
 * has no aircraft -- and both are plain strings without a foreign key, since
 * aircraft live in the fleet module which need not be installed (D4).
 *
 * Note that this records the CIRCUMSTANCES of a block. Booking a removed part
 * back into stock as a lot of its own is a workflow that does not exist yet;
 * see the open question in docs/LAGERMODUL.md.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lot_state_changes', function (Blueprint $table): void {
            $table->string('aircraft_reference', 32)->nullable()->after('reason');
            $table->string('aircraft_type', 64)->nullable()->after('aircraft_reference');

            // Set once the tag has actually been printed, so a sheet is not
            // reprinted by accident and the interface can show what is pending.
            $table->timestamp('tag_printed_at')->nullable()->after('quarantine_tag');
        });
    }

    public function down(): void
    {
        Schema::table('lot_state_changes', function (Blueprint $table): void {
            $table->dropColumn(['aircraft_reference', 'aircraft_type', 'tag_printed_at']);
        });
    }
};
