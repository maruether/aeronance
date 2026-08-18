<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Der Befundbericht — und warum er keine eigene Tabelle bekommt.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * Vorgabe: „Einem Vorgang sollte immer ein befundbericht zugeordnet sein, nach
 * dem neu anlegen eines vorgangs sollte ein befundbericht angelegt werden
 * können innerhalb des vorgangs, wobei jeder punkt zu einer arbeitskarte wird.
 * Außerdem Sollte der Befundbericht nach dem Schema ‚Laufende Nummer - Befund -
 * Behebung - Ausgeführt durch - Geprüft durch - freigegeben durch' aufgebaut
 * sein."
 *
 * „IMMER ZUGEORDNET" IST EINE AUSSAGE ÜBER DIE STRUKTUR, nicht über eine
 * Zeile in einer Tabelle. Der Bericht ist die Befundsicht des Vorgangs: Seine
 * Zeilen SIND die Befunde des Vorgangs mit der Karte, die sie behebt, und die
 * drei Unterschriftsspalten stehen längst an dieser Karte -- fertiggemeldet
 * von, unabhängig kontrolliert von, abgezeichnet von. Ein eigenes Blattobjekt
 * mit denselben Namen daneben wären zwei Wahrheiten über eine Unterschrift,
 * und das gedruckte Blatt könnte etwas anderes sagen als die Akte darunter.
 *
 * Damit hat jeder Vorgang seinen Bericht, ohne dass ihn jemand anlegen muss und
 * ohne dass er je fehlen kann.
 *
 * WAS TATSÄCHLICH FEHLTE, ist die vorgedruckte letzte Zeile des Blatts: die
 * Fremdkörper- und Werkzeugkontrolle nach Beendigung der Arbeiten. Sie gehört
 * zum Vorgang als Ganzem und zu keiner einzelnen Karte -- man räumt einmal auf,
 * nicht je Befund. Deshalb diese drei Spalten und keine mehr.
 *
 * Der Name wird MITGESCHRIEBEN, wie überall in diesem System, wo eine Person
 * unterschreibt: Das Konto kann später umbenannt, pseudonymisiert oder gelöscht
 * werden; was auf dem Blatt stand, bleibt.
 * ─────────────────────────────────────────────────────────────────────────────
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('work_orders', function (Blueprint $table): void {
            $table->timestamp('foreign_object_check_at')->nullable()->after('released_at');
            $table->unsignedBigInteger('foreign_object_check_by')->nullable()->after('foreign_object_check_at');
            $table->string('foreign_object_check_by_name', 160)->nullable()->after('foreign_object_check_by');
        });
    }

    public function down(): void
    {
        Schema::table('work_orders', function (Blueprint $table): void {
            $table->dropColumn([
                'foreign_object_check_at',
                'foreign_object_check_by',
                'foreign_object_check_by_name',
            ]);
        });
    }
};
