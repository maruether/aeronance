<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Was dem Motorflugblatt bisher fehlte.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * Block A des Blattes führt Kennblattdaten, die es im Schema nicht gab -- das
 * Blatt konnte deshalb gar nicht vollständig ausgefüllt werden, und der
 * Ausdruck ließ genau die Zeilen leer, nach denen ein Prüfer zuerst schaut.
 *
 * DIE BEZUGSEBENE ist nicht die Bezugslinie. B.L. ist waagerecht und sagt, wie
 * das Flugzeug beim Wiegen steht; B.E. ist senkrecht und ist der Nullpunkt, von
 * dem aus die Hebelarme gemessen werden. Beim Segelflugzeug fallen sie
 * praktisch zusammen, beim Motorflugzeug nicht -- deshalb steht auf dem Blatt
 * „XG = ___ mm von BE".
 *
 * DIE KONFIGURATIONEN sind eigene Zeilen, keine Spalten: Ein Muster kann
 * einsitzig und zweisitzig zugelassen sein, mit je eigener Zuladung, eigener
 * Höchstmasse und eigenem Schwerpunktbereich. Das Papier druckt dafür zwei
 * Tabellen mit denselben Zeilen; hier ist es eine Zeilenart mehr.
 * ─────────────────────────────────────────────────────────────────────────────
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('weighings', function (Blueprint $table): void {
            $table->string('datum_plane', 160)->nullable()->after('reference_line');
            $table->string('fuselage_reference_plane', 160)->nullable()->after('datum_plane');
        });

        Schema::table('weighing_entries', function (Blueprint $table): void {
            // Nur von der Zeilenart „configuration" benutzt -- eigene Tabelle
            // waere fuer vier Spalten und drei Zeilen je Blatt zu viel Apparat.
            $table->decimal('max_mass_kg', 10, 2)->nullable()->after('arm_mm');
            $table->decimal('useful_load_kg', 10, 2)->nullable()->after('max_mass_kg');
            $table->decimal('cg_from_mm', 10, 2)->nullable()->after('useful_load_kg');
            $table->decimal('cg_to_mm', 10, 2)->nullable()->after('cg_from_mm');
        });
    }

    public function down(): void
    {
        Schema::table('weighings', function (Blueprint $table): void {
            $table->dropColumn(['datum_plane', 'fuselage_reference_plane']);
        });

        Schema::table('weighing_entries', function (Blueprint $table): void {
            $table->dropColumn(['max_mass_kg', 'useful_load_kg', 'cg_from_mm', 'cg_to_mm']);
        });
    }
};
