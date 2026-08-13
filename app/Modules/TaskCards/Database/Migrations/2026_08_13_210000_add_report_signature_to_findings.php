<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Die Abzeichnung des Befundberichts.
 *
 * Vorgabe: "ein Befundbericht ist mit der entsprechenden nummer die zu
 * freigaben berechtigt abgezeichnet" -- Part-66-Lizenz, wenn vorhanden,
 * sonst die Nummer der Pilot-Owner-Berechtigung fuer dieses Luftfahrzeug.
 *
 * Als KOPIE am Befund, nicht als Verweis (E7): Der Bericht muss auch dann
 * noch sagen, unter welcher Nummer er abgegeben wurde, wenn die Lizenz
 * spaeter unter neuer Nummer verlaengert oder das Konto pseudonymisiert
 * wurde. Dasselbe Muster wie bei der Zurueckstellung
 * (deferral_qualification_*). Nullable, weil Werkstatt-Befunde aus einem
 * Vorgang heraus (record) weiterhin ohne Signatur entstehen -- dort ist
 * die Beobachtung frei, nur der BERICHT traegt eine Nummer.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('findings', function (Blueprint $table): void {
            $table->string('reported_qualification_type', 64)->nullable()->after('found_on');
            $table->string('reported_qualification_reference', 128)->nullable()->after('reported_qualification_type');
        });
    }

    public function down(): void
    {
        Schema::table('findings', function (Blueprint $table): void {
            $table->dropColumn(['reported_qualification_type', 'reported_qualification_reference']);
        });
    }
};
