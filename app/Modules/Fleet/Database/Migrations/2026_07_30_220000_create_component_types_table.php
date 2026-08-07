<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Component types -- engines, propellers, tow releases and their certificates.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * Vorgabe: "auch die haben tms." They do, and the ASK 21's own list proves it: the
 * Tost coupling carries "2 Jahre oder 500 Starts, whatever comes first" plus its
 * own technical notes and its own Kennblatt (60.230/2 in the LBA's coupling
 * volume).
 *
 * A SEPARATE TABLE FROM AircraftType, not a shared "type" with a kind column.
 * They look similar and behave differently: an aircraft type has one airframe per
 * registration, while a component type appears many times over in one fleet and
 * carries running times per installation. Merging them would mean every query
 * about either had to remember which it was looking at.
 *
 * WHERE IT DOES NOT REACH: the warehouse's PartType. Warehouse does not require
 * fleet, so a foreign key from there would break the module boundary or make the
 * warehouse mandatory. A part fitted from stock gets its component type on the
 * INSTALLATION, which is the fleet's own record -- the same discipline as every
 * other warehouse/fleet link in this project.
 * ─────────────────────────────────────────────────────────────────────────────
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('component_types', function (Blueprint $table): void {
            $table->id();

            $table->string('designation', 160);
            $table->string('manufacturer', 160)->nullable();

            // engine | propeller | tow_release | other
            $table->string('kind', 24)->index();

            /*
             * The Kennblatt number as the authority writes it. The component
             * volumes use notations of their own -- 60.230/2 for couplings,
             * 4502/EN for engines, 32.100/1/PR for propellers -- and normalising
             * across them would lose the form printed on the document.
             */
            $table->string('type_certificate', 64)->nullable()->index();
            $table->string('certificate_authority', 16)->nullable();

            $table->text('data_sheet_url')->nullable();

            /*
             * The manufacturer's own TM/LTA overview for this component. Rotax
             * publishes one, Tost publishes one -- the same "die reichen und
             * können zum Hersteller verlinken" as for aircraft types.
             */
            $table->text('directive_overview_url')->nullable();

            /*
             * The part number, where the manufacturer uses one.
             *
             * Kept apart from the designation because a directive names one or the
             * other and matching on the wrong one finds nothing: "Sicherheits-
             * kupplung Europa G 88" is what a person says, and a P/N is what a
             * parts list says.
             */
            $table->string('part_number', 96)->nullable()->index();

            $table->string('source', 64)->default('manual')->index();
            $table->text('note')->nullable();

            $table->timestamps();
            $table->softDeletes();

            // Designation alone is not unique enough: two manufacturers may both
            // build an "E 85". The pair is.
            $table->unique(['designation', 'manufacturer']);
        });

        Schema::table('installations', function (Blueprint $table): void {
            /*
             * What is actually fitted, catalogued.
             *
             * Alongside part_name, which stays: a component nobody has catalogued
             * must still be recordable, exactly as with aircraft types and for the
             * same reason.
             */
            $table->foreignId('component_type_id')
                ->nullable()
                ->after('part_name')
                ->constrained('component_types')
                ->nullOnDelete();
        });

        Schema::table('directives', function (Blueprint $table): void {
            // A component directive can point at the catalogued component, so its
            // applicability stops being a substring guess on part_name.
            $table->foreignId('component_type_id')
                ->nullable()
                ->after('subject_part_number')
                ->constrained('component_types')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('directives', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('component_type_id');
        });

        Schema::table('installations', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('component_type_id');
        });

        Schema::dropIfExists('component_types');
    }
};
