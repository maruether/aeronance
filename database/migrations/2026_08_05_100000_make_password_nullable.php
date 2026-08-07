<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Ein neues Konto hat KEIN Passwort.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * Vorgabe: „wenn ein konto neu angelegt wird hat es bitte gar kein passwort.
 * dieses entsteht erst durch einen aktiven passwort reset durch den user."
 *
 * Vorher bekam ein Konto aus dem Mitgliederabgleich ein ZUFALLSPASSWORT, das
 * niemand kannte. Das klingt gleichwertig, ist es aber nicht:
 *
 *  - Ein Zufallspasswort IST ein Passwort. Es steht als Hash in der Datenbank,
 *    es wandert in jede Sicherung, und in zehn Jahren ist der Algorithmus, mit
 *    dem es gehasht wurde, vielleicht keiner mehr.
 *
 *  - NULL ist eine Aussage: „Dieses Konto hat noch nie jemand benutzt." Das ist
 *    beantwortbar, auswertbar und im Zweifel vorzeigbar. „Hat ein Passwort, das
 *    niemand kennt" ist keine Aussage, sondern eine Hoffnung.
 *
 *  - Und es macht den Zustand SICHTBAR. Eine Liste „diese 40 Konten wurden nie
 *    aktiviert" laesst sich aus NULL bilden, aus einem Zufallswert nicht.
 *
 * Laravel kommt damit zurecht: Hash::check() gegen einen leeren Hash schlaegt
 * fehl, die Anmeldung wird also abgewiesen -- was genau richtig ist. Der Weg
 * hinein fuehrt ueber die Einladung, und die setzt das erste Passwort.
 * ─────────────────────────────────────────────────────────────────────────────
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('password')->nullable()->change();
        });
    }

    public function down(): void
    {
        /*
         * Zurueck geht nur, wenn kein Konto ohne Passwort dasteht -- sonst
         * scheitert die Spaltenaenderung an genau den Zeilen, um die es geht.
         * Deshalb bekommen sie vorher einen unbrauchbaren Wert: kein gueltiger
         * Hash, also weiterhin keine Anmeldung.
         */
        DB::table('users')
            ->whereNull('password')
            ->update(['password' => '']);

        Schema::table('users', function (Blueprint $table): void {
            $table->string('password')->nullable(false)->change();
        });
    }
};
