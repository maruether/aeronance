<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Die Arbeitsstunden-Kategorien des Vereins, wie Vereinsflieger sie fuehrt.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * Vorgabe aus dem Betrieb: "kann nicht ohne auswahlliste nach der nummer der
 * kategorie gefragt werden." Die Einstellung "Kategorie" verlangte eine nackte
 * Nummer, die man nur aus Vereinsflieger abtippen konnte -- dabei liefert
 * workhourcategories/list die Liste mit Namen frei Haus.
 *
 * Der Abgleich schreibt sie hierher, die Einstellungsseite liest sie als
 * Auswahlliste. Wie bei den Mitgliedsstatus gilt: Der Schluessel ist die
 * NUMMER ('category', gemessen z. B. 7265 = "Wartung/Werkstatt"), denn Namen
 * benennt ein Verein um, Nummern bleiben.
 *
 * 'enabled' kommt mit, weil eine drueben abgeschaltete Kategorie ueber die
 * Schnittstelle trotzdem beschreibbar ist (gemessen an 7813, "Aeronance") --
 * genau so trennt ein Verein, was aus Aeronance kommt. Die Auswahlliste sagt
 * es dazu, statt die Kategorie zu verstecken.
 * ─────────────────────────────────────────────────────────────────────────────
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vereinsflieger_work_hour_categories', function (Blueprint $table): void {
            $table->id();

            // Die Nummer als Text, nicht als Zahl: eine KENNUNG, keine
            // Groesse -- wie msid bei den Mitgliedsstatus.
            $table->string('category', 32)->unique();

            // Was der Anbieter zuletzt als Namen lieferte, entity-dekodiert.
            $table->string('name', 191)->nullable();

            // Ob die Kategorie drueben fuer Mitglieder waehlbar ist.
            $table->boolean('enabled')->default(true);

            $table->timestamp('first_seen_at')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vereinsflieger_work_hour_categories');
    }
};
