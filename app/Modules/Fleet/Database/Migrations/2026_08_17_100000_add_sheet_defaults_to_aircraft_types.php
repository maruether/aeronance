<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Blattart und Fahrwerk gehören zum Muster.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * Feldtest: „wenn ich für die D-EICC eine wägung anlege bekomme ich als
 * eingabemaske die massenübersicht segelflugzeug. Bei der Auswahl des
 * Flugzeuges gehört die abfrage nach typ und fahrwerkskonfiguration rein. Noch
 * besser wäre wenn Diese Daten direkt im Muster hinterlegt werden könnten."
 *
 * Beides ist eine Eigenschaft des MUSTERS und nicht der einzelnen Wägung: Eine
 * Aquila steht auf drei Beinen und wird auf dem Flugzeugblatt gewogen, heute
 * wie in vier Jahren, und für jede Aquila des Vereins gleich. Bisher stand die
 * Angabe ausschliesslich am Wägeblatt -- also musste sie jedes Mal neu
 * getroffen werden, und beim ersten Blatt eines Flugzeugs gab es nichts, woraus
 * sie hätte kommen können. Genau da fiel das System auf „Segelflugzeug"
 * zurück, ohne zu fragen.
 *
 * NICHTS WIRD NACHGETRAGEN. Ableiten liesse es sich (alle Flugzeuge des Musters
 * unmotorisiert ⇒ Segelflugzeug), aber eine gespeicherte Vermutung sieht später
 * aus wie eine Angabe. Leer heisst leer: Dann fragt die Maske und schlägt vor,
 * was sie aus Antrieb und Vorgängerwägung erschliessen kann -- sichtbar und
 * änderbar. Siehe App\Modules\Fleet\Support\SheetSetup.
 * ─────────────────────────────────────────────────────────────────────────────
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('aircraft_types', function (Blueprint $table): void {
            // Dieselben Spaltenbreiten wie an `weighings` -- es sind dieselben
            // Aufzählungen, und zwei Breiten für einen Wert sind eine Falle.
            $table->string('sheet_variant', 16)->nullable()->after('manufacturer');
            $table->string('undercarriage', 24)->nullable()->after('sheet_variant');
        });
    }

    public function down(): void
    {
        Schema::table('aircraft_types', function (Blueprint $table): void {
            $table->dropColumn(['sheet_variant', 'undercarriage']);
        });
    }
};
