<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Master data of the warehouse: suppliers, locations, compartments, part types.
 *
 * Two departures from the legacy schema worth naming, both from decisions in
 * docs/ANALYSE.md:
 *
 *  - No -1 sentinels. "Not set" is NULL, and a boolean has two values. The old
 *    schema used -1 for "no minimum stock" and had three states for a flag,
 *    which meant every read had to know the convention.
 *  - The price is informational and nothing hangs off it. Purchasing, price
 *    history and valuation are merchandise management, which this module is
 *    deliberately not (E6).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('suppliers', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 128)->unique();
            $table->text('address')->nullable();
            $table->text('contact')->nullable();
            $table->text('description')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('storage_locations', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 128)->unique();
            $table->text('description')->nullable();

            // A quarantine location keeps unserviceable and unsalvageable parts
            // apart from usable ones, which 145.A.42 requires. Marking it as a
            // TYPE rather than a separate table means a lot keeps its identity,
            // its Form 1 and its history when it moves there -- blocking is a
            // transfer, not a loss of information.
            $table->boolean('is_quarantine')->default(false)->index();

            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('storage_compartments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('storage_location_id')->constrained()->cascadeOnUpdate()->restrictOnDelete();
            $table->string('name', 128);
            $table->text('description')->nullable();
            $table->timestamps();
            $table->softDeletes();

            // Names are unique within their location, not globally: every
            // cupboard may have a "shelf 1".
            $table->unique(['storage_location_id', 'name']);
        });

        Schema::create('part_types', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 128)->unique();
            $table->text('description')->nullable();

            // component | standard_part | consumable_material -- see E5.
            $table->string('classification', 32)->index();

            $table->foreignId('supplier_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('storage_compartment_id')->nullable()->constrained()->nullOnDelete();

            $table->string('order_code', 128)->nullable();
            $table->string('ipc_part_number', 128)->nullable()->index();

            $table->string('unit_of_measure', 16)->default('St');

            // NULL means "not set" -- no sentinel values.
            $table->unsignedInteger('minimum_stock')->nullable();
            $table->unsignedInteger('maximum_stock')->nullable();

            // Calendar time only. Flight hours, landings and cycles start on
            // installation and belong to the fleet module.
            $table->unsignedSmallInteger('shelf_life_days')->nullable();

            $table->boolean('requires_form_one')->default(false);
            $table->boolean('serial_tracked')->default(false);

            // Informational, nothing hangs off it (E6).
            $table->decimal('net_purchase_price', 10, 2)->nullable();

            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('part_types');
        Schema::dropIfExists('storage_compartments');
        Schema::dropIfExists('storage_locations');
        Schema::dropIfExists('suppliers');
    }
};
