<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Was aus einem Vereinsflieger-Mitgliedsstatus werden soll.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * Vorgabe: „bei memberstatus interessieren mich initial nur 1 und 2. alle
 * anderen soll das modul initial abrufen und den admin entscheiden lassen was
 * damit passiert (als aktives oder passives mitglied führen oder als nicht
 * vorhanden ignorieren)."
 *
 * DER SCHLUESSEL IST DIE NUMMER, NICHT DAS WORT. Gemessen: aktiv=1, passiv=2,
 * sonstige=6, Ehrenmitglied=101, Externer Pilot=102. Die niedrigen Nummern mit
 * Luecken bei 3 bis 5 sehen nach einer systemseitigen Liste aus, die ab 100
 * nach selbst angelegten Eintraegen -- und selbst angelegte Eintraege benennt
 * ein Verein irgendwann um. Wer auf das Wort abbildet, hat am naechsten Tag
 * eine still wirkungslose Regel.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * WARUM `handling` NULLABLE IST, und das ist der Kern.
 *
 * NULL heisst „noch nicht entschieden" und ist etwas ANDERES als „ignorieren".
 * Beide fuehren dazu, dass kein Konto entsteht -- aber nur das eine ist eine
 * Entscheidung. Die Oberflaeche kann deshalb zeigen, was noch offen ist, statt
 * dass 243 Menschen stillschweigend keinen Zugang bekommen und niemand weiss,
 * ob das so gewollt war.
 *
 * Vorbelegt werden ausschliesslich 1 und 2. Alles andere wartet.
 * ─────────────────────────────────────────────────────────────────────────────
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vereinsflieger_member_statuses', function (Blueprint $table): void {
            $table->id();

            /*
             * Die msid als Text, nicht als Zahl: Sie ist eine KENNUNG, keine
             * Groesse. Niemand rechnet damit, und sollte Vereinsflieger je
             * etwas Nichtnumerisches liefern, faellt hier nichts um.
             */
            $table->string('msid', 32)->unique();

            // Was der Anbieter zuletzt als Namen lieferte -- nur zur Anzeige.
            $table->string('label', 191)->nullable();

            // Wie viele Menschen zuletzt darauf standen. Wer entscheidet, ob
            // ein Status Konten bekommt, sollte sehen, um wie viele es geht --
            // BEVOR er entscheidet.
            $table->unsignedInteger('member_count')->nullable();

            // active | passive | ignore -- NULL = noch nicht entschieden.
            $table->string('handling', 16)->nullable();

            $table->timestamp('first_seen_at')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vereinsflieger_member_statuses');
    }
};
