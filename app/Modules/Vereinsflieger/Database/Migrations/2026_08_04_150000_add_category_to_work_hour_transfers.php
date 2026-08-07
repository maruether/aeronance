<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Die Kategorie, mit der gesendet wurde.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * Vorgabe: „check doch einfach die kategorie noch mit. Wenn jemand genau deinen
 * wortlaut und status, wenn er selbst beides genau trifft (und die kategorie
 * überhaupt auswählen kann) dann bekommt er die stunden halt nicht."
 *
 * Damit wird die bekannte Grenze des Nachsehens sehr schmal. Ein von Hand
 * angelegter Eintrag muesste jetzt Datum, Person, Wortlaut, Status UND
 * Kategorie treffen -- und bei einer API-only-Kategorie (in der
 * Referenzinstallation 7813 mit enabled=0) kann er die Kategorie ueberhaupt
 * nicht waehlen.
 *
 * GESPEICHERT UND NICHT AUS DER EINSTELLUNG GELESEN: Aendert der Admin die
 * Kategorie zwischen zwei Laeufen, wuerde ein offener Beleg sonst gegen den
 * falschen Wert verglichen und nie wiedergefunden -- und beim naechsten
 * Versuch doppelt gebucht. Was gesendet wurde, gehoert an den Beleg.
 * ─────────────────────────────────────────────────────────────────────────────
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vereinsflieger_work_hour_transfers', function (Blueprint $table): void {
            $table->string('category', 32)->nullable()->after('hours');
        });
    }

    public function down(): void
    {
        Schema::table('vereinsflieger_work_hour_transfers', function (Blueprint $table): void {
            $table->dropColumn('category');
        });
    }
};
