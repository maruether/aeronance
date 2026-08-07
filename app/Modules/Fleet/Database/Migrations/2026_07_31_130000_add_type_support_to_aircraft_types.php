<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Who looks after this type today -- and the case where nobody does.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * WHY THIS IS A STATED FACT AND NOT A DERIVED ONE.
 *
 * The directives module exists against a single failure: an empty list must
 * never read as "the manufacturer has published nothing new". For a type whose
 * manufacturer is gone and whose type support nobody took over -- Bölkow
 * Phoebus, SHK-1, Fauvel AV-36, IS-28B2, the old K types, SN Centrair's Pégase
 * -- the list is legitimately empty, but for an entirely different reason: there
 * is nobody left to publish anything. The club is on its own and has to research
 * for itself.
 *
 * Three states have to stay apart, and only the third is this column:
 *
 *   1. type support exists, source set up      -> the ordinary list
 *   2. type support exists, source NOT set up  -> somebody has to configure it
 *   3. no type support at all                  -> nothing to configure, ever
 *
 * Deriving (3) from "no source found" would merge it with (2), and the two want
 * opposite reactions: (2) is a task for the administrator that goes away once
 * done, (3) is a permanent property of the aircraft type that no amount of
 * configuring will fix. So it is declared, by a person, on the type.
 *
 * `type_support` carries the other half of the answer -- for a Grob glider the
 * club would write "LTB Lindner". Free text on purpose: these are LTBs,
 * associations, sometimes a single person who bought the drawings, and there is
 * no register to reference.
 * ─────────────────────────────────────────────────────────────────────────────
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('aircraft_types', function (Blueprint $table): void {
            $table->string('type_support', 160)->nullable()->after('manufacturer');

            /*
             * Default false, so every row that already exists stays unflagged.
             *
             * That is the safe direction: an unflagged orphan shows no warning
             * until somebody ticks the box, whereas defaulting to true would put
             * "Achtung! Kein Musterbetreuer!" on every well-supported type in the
             * fleet -- and a warning that appears everywhere is read nowhere.
             */
            $table->boolean('without_type_support')->default(false)->after('type_support');
        });
    }

    public function down(): void
    {
        Schema::table('aircraft_types', function (Blueprint $table): void {
            $table->dropColumn(['type_support', 'without_type_support']);
        });
    }
};
