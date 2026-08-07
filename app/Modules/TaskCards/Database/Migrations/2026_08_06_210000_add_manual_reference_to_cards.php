<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Nach welchem Stand wurde gearbeitet.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * ALS TEXT UND NICHT ALS FREMDSCHLÜSSEL, und das ist derselbe Grundsatz wie
 * überall sonst in diesem Projekt: Der Nachweis wird KOPIERT.
 *
 * Ein Verweis auf die Unterlage würde mitwandern — wird sie abgelöst,
 * behauptete die Karte rückwirkend, nach dem neuen Stand gearbeitet worden zu
 * sein. Genau das ist die Unwahrheit, gegen die es die Revisionsführung gibt.
 *
 * Dieselbe Regel wie beim Bescheinigungsinhalt am Los (E7), beim Kennzeichen an
 * der Karte und beim Betrieb an der Instandsetzung.
 * ─────────────────────────────────────────────────────────────────────────────
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('task_cards', function (Blueprint $table): void {
            $table->string('manual_reference', 255)->nullable()->after('instruction');
        });
    }

    public function down(): void
    {
        Schema::table('task_cards', function (Blueprint $table): void {
            $table->dropColumn('manual_reference');
        });
    }
};
