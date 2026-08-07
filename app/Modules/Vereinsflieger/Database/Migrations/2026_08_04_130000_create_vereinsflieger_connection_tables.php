<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Mehrere Vereinsflieger-Anbindungen nebeneinander.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * Vorgabe: „ich möchte optional mehrere vereine koppeln können. Hintergrund ist
 * da das cao umfeld: Verein ist mit seinen flugzeugen in der cao und diese
 * bekommt so automatisch die stunden quasi live statt immer nachfragen zu
 * müssen."
 *
 * Das ist der Unterschied zwischen „ein Verein, ein Zugang" und dem echten
 * Betrieb: Eine CAO betreut Luftfahrzeuge MEHRERER Vereine, und jeder Verein
 * hat seinen eigenen Vereinsflieger. Bisher lag genau ein Zugang in den
 * Einstellungen -- damit haette die CAO reihum Zugangsdaten tauschen muessen.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * NUR EINE ANBINDUNG LIEFERT IDENTITAETEN, und das ist Absicht.
 *
 * Ein Mensch hat in Aeronance ein Konto. Kaeme er aus zwei Vereinsfliegern,
 * gaebe es zwei Wahrheiten darueber, wer er ist -- und die Zuordnung von
 * Funktionen auf Rollen wuesste nicht, welche gilt. Die anderen Anbindungen
 * sind reine Datenquellen: Sie liefern Betriebszeiten und nehmen Arbeitsstunden
 * entgegen, aber sie legen niemanden an.
 *
 * Erzwungen wird das im Model, nicht hier: Eine Datenbank kann „hoechstens eine
 * Zeile mit true" nicht ausdruecken, ohne dass es haesslich wird.
 * ─────────────────────────────────────────────────────────────────────────────
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vereinsflieger_connections', function (Blueprint $table): void {
            $table->id();

            // Wie der Verein hier heisst -- „Akaflieg Freiburg", „LSV Musterstadt".
            $table->string('name', 128)->unique();

            $table->string('username', 191);

            /*
             * Verschluesselt, wie jedes Geheimnis in dieser Anwendung. Das
             * Passwort geht im Klartext an Vereinsflieger (die hashen selbst,
             * siehe F19) -- also muss es rueckholbar sein und darf nicht
             * gehasht liegen.
             */
            $table->text('password');
            $table->text('app_key');
            $table->text('auth_secret')->nullable();

            // Ob `password` bereits der MD5-Hash ist. Ausgeschrieben statt
            // geraten -- eine falsche Heuristik saehe aus wie ein falsches
            // Passwort.
            $table->boolean('password_is_hash')->default(false);

            $table->string('cid', 32)->default('0');

            /** Hoechstens eine -- siehe Kopf. */
            $table->boolean('provides_identities')->default(false);

            $table->boolean('is_active')->default(true);

            // Was der letzte Lauf ergeben hat. Steht hier und nicht im Log,
            // weil es auf den Bildschirm gehoert: Eine Anbindung, die seit drei
            // Wochen scheitert, soll man sehen, ohne Logdateien zu lesen.
            $table->timestamp('last_run_at')->nullable();
            $table->text('last_error')->nullable();

            $table->timestamps();
        });

        /*
         * Welches Luftfahrzeug zu welcher Anbindung gehoert.
         *
         * ─────────────────────────────────────────────────────────────────────
         * Vorgabe: „dazu brauchen wir an den luftfahrzeugen (fleet modul) die
         * auswahl ob, und zu welcher VF API kopplung sie gehören."
         *
         * DIE TABELLE GEHOERT DEM VF-MODUL, nicht der Flotte. Ein Verein ohne
         * Vereinsflieger hat Luftfahrzeuge und braucht diese Spalte nie; die
         * Flotte muss ohne dieses Modul laufen. Der Verweis geht deshalb von
         * hier nach dort und nicht umgekehrt.
         *
         * DAS KENNZEICHEN STEHT EIGENS DABEI. Vorgabe: „geht nach kennzeichen,
         * da ist einfach davon auszugehen das es eingetragen wird wie es am lfz
         * steht." Vorbelegt wird es aus dem Luftfahrzeug, aenderbar bleibt es
         * trotzdem -- Vereinsflieger koennte es anders schreiben, und dann ist
         * eine Zeile hier besser als ein Abgleich, der stumm nichts findet.
         * ─────────────────────────────────────────────────────────────────────
         */
        Schema::create('vereinsflieger_aircraft_links', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('connection_id')
                ->constrained('vereinsflieger_connections')
                ->cascadeOnDelete();

            /*
             * OHNE Fremdschluessel auf aircraft: Die Flotte ist ein eigenes
             * Modul und kann abgeschaltet sein. Ein Fremdschluessel waere eine
             * harte Abhaengigkeit auf Datenbankebene -- genau das, was die
             * Modulgrenze verbietet.
             */
            $table->unsignedBigInteger('aircraft_id');

            $table->string('callsign', 32);

            $table->boolean('is_active')->default(true);

            // Was zuletzt geholt wurde -- fuer die Anzeige und damit ein
            // stummer Fehlschlag auffaellt.
            $table->timestamp('last_read_at')->nullable();
            $table->text('last_error')->nullable();

            $table->timestamps();

            // Ein Luftfahrzeug haengt an genau einer Anbindung.
            $table->unique('aircraft_id');
            $table->index(['connection_id', 'is_active']);
        });

        /*
         * Welche Arbeitszeit schon uebertragen wurde.
         *
         * ─────────────────────────────────────────────────────────────────────
         * GEMESSEN: workhours/add legt bei identischen Daten einen ZWEITEN
         * Eintrag an -- Vereinsflieger prueft nichts. Und geloescht werden kann
         * drueben gar nichts: Die API kennt weder edit noch delete.
         *
         * Eine Doppelbuchung ist damit dauerhaft. Deshalb ist der
         * Fremdschluessel auf die Arbeitszeit EINDEUTIG: Der zweite Versuch
         * laeuft in einen Datenbankfehler und nicht in einen zweiten Eintrag.
         * Verlassen wir uns auf eine Pruefung im Code, gewinnt irgendwann ein
         * gleichzeitiger Lauf.
         * ─────────────────────────────────────────────────────────────────────
         */
        Schema::create('vereinsflieger_work_hour_transfers', function (Blueprint $table): void {
            $table->id();

            // Wieder ohne Fremdschluessel: Die Arbeitskarten sind ein Modul.
            $table->unsignedBigInteger('task_card_time_id')->unique();

            $table->foreignId('connection_id')
                ->constrained('vereinsflieger_connections')
                ->cascadeOnDelete();

            /** Die Nummer, die Vereinsflieger vergeben hat. */
            $table->string('whid', 32)->nullable();

            // Was gesendet wurde -- der Text, die Dauer, der Status. Nach dem
            // Senden kann drueben niemand mehr etwas aendern, also ist das hier
            // die einzige Stelle, an der steht, was dort eigentlich ankam.
            $table->string('job_text', 191)->nullable();
            $table->string('hours', 8)->nullable();
            $table->string('status', 8)->nullable();

            $table->timestamp('transferred_at')->nullable();
            $table->text('last_error')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vereinsflieger_work_hour_transfers');
        Schema::dropIfExists('vereinsflieger_aircraft_links');
        Schema::dropIfExists('vereinsflieger_connections');
    }
};
