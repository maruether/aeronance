<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Einstellungen, die ein Verein selbst setzen kann.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * WARUM DAS EINE TABELLE IST UND KEINE DATEI.
 *
 * Vorgabe: "Ziel muss es sein die Konsole nur für das Starten des fertig
 * runtergeladenen Dockers und für den Break-glass zu benötigen. Wir können den
 * Usern nicht zumuten alles mögliche in config files zu schreiben."
 *
 * Gezählt waren es 25 Werte -- Vereinsname, Zeitzone, die gesamte Sicherung
 * samt Auslagerungszugang, Toleranzen, Virenscanner. Am schlimmsten die
 * Aufbewahrungsfristen: Die standen nicht einmal in der .env, sondern in
 * config/aeronance.php -- einer Datei, die im Docker-Kanal IM IMAGE liegt und
 * bei jedem Update verlorengeht. Faktisch war Retention dort nicht
 * einschaltbar.
 *
 * ALLES VERSCHLUESSELT, nicht nur die Geheimnisse. Zwei Spalten -- eine offene
 * und eine verschluesselte -- waeren eine Einladung, ein Passwort versehentlich
 * in die falsche zu schreiben. Eine Spalte, ein Verfahren, kein Zweifelsfall.
 * Abgefragt wird ohnehin nie nach dem Wert, sondern immer nach dem Schluessel.
 *
 * KEIN SOFT DELETE. Eine geloeschte Einstellung ist keine geloeschte
 * Aufzeichnung, sondern die Rueckkehr zur Vorgabe -- und WAS sie war, steht im
 * Audit-Log.
 * ─────────────────────────────────────────────────────────────────────────────
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('settings', function (Blueprint $table): void {
            $table->id();

            // "backup.encryption.mode". Der Katalog kennt die gueltigen
            // Schluessel; die Tabelle nimmt bewusst jeden an, damit ein
            // Downgrade nicht an einer Fremdschluesselpruefung scheitert.
            $table->string('key', 128)->unique();

            $table->text('value')->nullable();

            /*
             * Ob der Wert je wieder angezeigt werden darf. Steht hier und nicht
             * nur im Katalog, damit auch ein Blick in die Tabelle sagt, was man
             * gerade vor sich hat.
             */
            $table->boolean('is_secret')->default(false);

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('settings');
    }
};
