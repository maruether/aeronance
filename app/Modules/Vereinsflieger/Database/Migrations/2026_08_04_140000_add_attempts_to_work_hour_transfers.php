<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Wie oft eine Uebertragung schon versucht wurde.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * Vorgabe: „eine mehrfachübertragung kann nötig sein. nach eintagung der stunden
 * muss das tool einmal alles abrufen und prüfen ob die einträge da sind. wenn
 * was fehlt wiederholen. max 3 versuche."
 *
 * DER FALL, DEN DAS LOEST, IST DER SCHLIMMSTE DER DREI:
 *
 *   1. Antwort kommt, Eintrag ist da        -> alles gut
 *   2. Antwort kommt mit Fehler             -> Eintrag ist NICHT da, klar
 *   3. KEINE Antwort (Timeout, Abbruch)     -> ??? -- niemand weiss es
 *
 * Bei 3 hilft nur Nachsehen. Deshalb wird nach dem Senden die Tagesliste
 * geholt und verglichen: Steht der Eintrag drueben, wird seine Nummer
 * nachgetragen; fehlt er, wird erneut gesendet.
 *
 * DIE OBERGRENZE IST KEIN SCHMUCK. Loeschen kann Vereinsflieger nicht -- wer
 * ohne Grenze wiederholt, riskiert bei einem dauerhaft kaputten Zustand jede
 * Nacht einen neuen Eintrag, den niemand mehr wegbekommt.
 * ─────────────────────────────────────────────────────────────────────────────
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vereinsflieger_work_hour_transfers', function (Blueprint $table): void {
            $table->unsignedTinyInteger('attempts')->default(0)->after('status');

            // Wann zuletzt nachgesehen wurde -- damit ein Fehlschlag nicht
            // aussieht wie „nie versucht".
            $table->timestamp('verified_at')->nullable()->after('transferred_at');
        });
    }

    public function down(): void
    {
        Schema::table('vereinsflieger_work_hour_transfers', function (Blueprint $table): void {
            $table->dropColumn(['attempts', 'verified_at']);
        });
    }
};
