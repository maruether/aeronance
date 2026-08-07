<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Die Gruppen, die ein Provider tatsaechlich kennt.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * WARUM DAS EINE TABELLE BRAUCHT UND KEINE LISTE IM CODE.
 *
 * Vorgabe: „es geht bei der zuordnung um die funktionen die der verein angelegt
 * hat. die ui muss entsprechend dynamisch sein."
 *
 * Vereinsfunktionen sind Vereinssache. „Werkstattleiter" heisst hier so, bei
 * einem anderen Verein „Technischer Leiter", beim dritten „Wart". Eine fest
 * eingebaute Auswahl waere fuer jede Installation ausser einer falsch -- und
 * ein freies Textfeld waere schlimmer: Wer sich vertippt, legt eine Zuordnung
 * an, die niemals greift, und sieht keinen Fehler. Rechte, die stillschweigend
 * nicht vergeben werden, fallen erst auf, wenn jemand vor der Werkstatt steht.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * WARUM SIE GESPEICHERT WERDEN UND NICHT BEI JEDEM AUFBAU GEHOLT.
 *
 * Vereinsflieger ist mengenbegrenzt. Eine Auswahlliste, die sich bei jedem
 * Oeffnen des Formulars neu holt, ist bei einem solchen Dienst kein Komfort,
 * sondern ein Selbstangriff. Also: einmal abrufen, hier ablegen, aus der
 * Tabelle anzeigen.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * ES WIRD NIE ETWAS GELOESCHT, nur `last_seen_at` fortgeschrieben.
 *
 * Verschwindet eine Funktion beim Provider, muss die Zuordnung sichtbar bleiben
 * -- sonst verschwindet mit der Zeile auch die Erklaerung, warum jemand einmal
 * eine Rolle hatte. Die Oberflaeche zeigt „zuletzt gesehen" und markiert, was
 * im letzten Abruf fehlte. Wirkung hat so ein Eintrag ohnehin keine mehr: In
 * einer Gruppe, die es nicht gibt, ist niemand.
 * ─────────────────────────────────────────────────────────────────────────────
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('external_groups', function (Blueprint $table): void {
            $table->id();

            // Kurzname des Connectors, wie in role_mappings.provider.
            $table->string('provider', 32);

            /*
             * Der Wert, der beim Abgleich verglichen wird -- bei Vereinsflieger
             * der Text der Funktion. 191 Zeichen wie ueberall, damit der Index
             * auch unter utf8mb4 mit altem Zeilenformat passt.
             */
            $table->string('value', 191);

            // Was dem Menschen angezeigt wird, falls der Provider einen
            // schoeneren Namen kennt als den Vergleichswert (LDAP: CN gegen DN).
            $table->string('label', 191)->nullable();

            /*
             * Wie viele Mitglieder zuletzt darin waren. Kein Fachwert, sondern
             * eine Einordnungshilfe: Eine Funktion mit 120 Traegern ist etwas
             * anderes als eine mit zweien, und wer Rechte vergibt, sollte das
             * sehen, BEVOR er sie vergibt.
             */
            $table->unsignedInteger('member_count')->nullable();

            $table->timestamp('first_seen_at')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();

            $table->unique(['provider', 'value'], 'external_groups_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('external_groups');
    }
};
