<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A directive can point at a catalogued type instead of a model string.
 *
 * The payoff for the type table: applicability stops being a substring
 * comparison. `subject_model` stays -- it must, because a manufacturer's list
 * names a type that may not be catalogued yet, and a row has to be importable
 * before somebody has curated the type.
 *
 * So the matching becomes: exact by type where both sides have one, loose by name
 * otherwise. Not a replacement, a sharpening.
 *
 * Existing rows are linked where the name already matches a type exactly. Loose
 * matches are deliberately NOT linked: guessing here would attach a directive to
 * the wrong variant, and the name comparison already covers those correctly.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('directives', function (Blueprint $table): void {
            $table->foreignId('aircraft_type_id')
                ->nullable()
                ->after('subject_model')
                ->constrained('aircraft_types')
                ->nullOnDelete();
        });

        foreach (DB::table('aircraft_types')->get(['id', 'designation']) as $type) {
            DB::table('directives')
                ->whereNull('aircraft_type_id')
                ->where('subject_model', $type->designation)
                ->update(['aircraft_type_id' => $type->id]);
        }
    }

    public function down(): void
    {
        Schema::table('directives', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('aircraft_type_id');
        });
    }
};
