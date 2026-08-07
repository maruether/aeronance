<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Der Befund der Kalibrierung — das Feld, an dem die Nachprüfpflicht hängt.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * NACHGETRAGEN, WEIL DIE ERSTE FASSUNG DIE FALSCHE BEDINGUNG PRÜFTE.
 *
 * Sie hielt fest, dass eine Kalibrierung zu SPÄT kam, und verlangte dafür eine
 * Bewertung. Die Vorschrift knüpft aber woanders an — EASA-FAQ 116318: „If the
 * tool / equipment fails during next regular calibration / inspection, the
 * completed tasks may require to be verified / performed again."
 *
 * Ausschlaggebend ist der DURCHFALLER, nicht die Verspätung. Und der Zeitraum
 * ist ein anderer: Wer außer Toleranz ankommt, stellt jede Arbeit seit der
 * letzten Messung mit Befund „in Toleranz" in Frage — oft Monate mehr als die
 * Verspätung.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * EIGENE MIGRATION STATT ÄNDERUNG DER ERSTEN.
 *
 * Das Modul ist am selben Tag entstanden und steht in keinem Release. Die erste
 * Migration umzuschreiben wäre trotzdem falsch: Sie ist auf master, und wer sie
 * schon laufen ließ, bekäme eine stillschweigend andere Tabelle als die Datei
 * behauptet. Eine zusätzliche Migration ist in beiden Fällen richtig.
 * ─────────────────────────────────────────────────────────────────────────────
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tool_calibrations', function (Blueprint $table): void {
            /*
             * „as found" -- der Zustand VOR einer etwaigen Justage. NULLABLE,
             * weil Altbestand und von Hand nachgetragene Historie ihn nicht
             * kennen. Ein fehlender Befund gilt ausdruecklich NICHT als
             * bestanden: Er ist unbekannt, und das ist etwas anderes.
             */
            $table->string('result', 24)->nullable()->after('valid_until');

            /*
             * Warum nachzupruefen ist. Ohne das waere in der Liste nicht
             * unterscheidbar, ob ein Werkzeug nur zu spaet gemessen wurde oder
             * ob es abgewichen ist -- zwei Faelle, die dieselbe Spalte fuellen
             * und ganz verschiedene Dringlichkeit haben.
             */
            $table->string('gap_reason', 24)->nullable()->after('gap_started_at');
        });

        Schema::table('tools', function (Blueprint $table): void {
            /*
             * WORAUF DAS INTERVALL BERUHT, als Text.
             *
             * Bewusst kein Betaetigungszaehler: EN ISO 6789 kennt fuer
             * Drehmomentwerkzeuge „12 Monate ODER 5.000 Betaetigungen, was
             * zuerst eintritt", und ein Zaehler dafuer muesste bei jedem
             * Handgriff gepflegt werden. Einer, den niemand hochzaehlt, zeigt
             * „1.200 von 5.000" an und ist eine Luege mit Nachkommastelle.
             *
             * Stattdessen steht hier, woher das Intervall kommt. Dann weiss der
             * Betrieb, dass es eine zweite Grenze gibt, und kann das
             * Zeitintervall entsprechend kuerzer ansetzen -- was die AMC zu
             * 145.A.40(b) ausdruecklich erlaubt, wenn er es begruenden kann.
             */
            $table->string('calibration_basis')->nullable()->after('calibration_interval_months');
        });
    }

    public function down(): void
    {
        Schema::table('tool_calibrations', function (Blueprint $table): void {
            $table->dropColumn(['result', 'gap_reason']);
        });

        Schema::table('tools', function (Blueprint $table): void {
            $table->dropColumn('calibration_basis');
        });
    }
};
