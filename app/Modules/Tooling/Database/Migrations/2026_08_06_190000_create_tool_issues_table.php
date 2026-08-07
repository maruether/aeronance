<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Werkzeugausgabe — wer hat was, und ist es zurück.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * DER FALL, DEN DAS VERHINDERT, HAT EINEN NAMEN: FOD.
 *
 * Ein Werkzeug, das beim Schließen im Flügel bleibt, ist kein Ordnungsproblem.
 * Es wandert, es klemmt eine Steuerung, und es fällt frühestens beim nächsten
 * Aufrüsten auf — wenn überhaupt. Deshalb verlangt 145.A.40 die Kontrolle über
 * Werkzeuge und nicht nur ihre Kalibrierung.
 *
 * Die Liste, die das leistet, ist banal: was ist draußen, seit wann, bei wem.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * DIE VORGANGSNUMMER IST TEXT UND KEIN FREMDSCHLÜSSEL, und das ist eine
 * Modulentscheidung.
 *
 * Das Werkzeugmodul steht allein — ein Verein, der nur wissen will, wann der
 * Drehmomentschlüssel ins Labor muss, hat keine Arbeitskarten. Ein
 * Fremdschlüssel auf `work_orders` machte daraus eine harte Abhängigkeit und
 * damit aus zwei Modulen eines.
 *
 * Als Text funktioniert beides: Mit Arbeitskarten bietet die Oberfläche die
 * offenen Vorgänge zur Auswahl an, ohne bleibt ein freies Feld. Dieselbe
 * Konstruktion wie `aircraft_reference` an der Lagerbewegung.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * UND DAMIT IST F42 BEANTWORTET, ohne bei jedem Handgriff etwas zu erfassen.
 *
 * Die offene Frage war: „Wer hat womit gearbeitet?" Die Antwort steht hier —
 * nicht handgriffgenau, aber vorgangsgenau, und das reicht. Fällt ein Werkzeug
 * bei der Kalibrierung durch, liefert der Nachprüfzeitraum das Fenster und
 * diese Tabelle die Vorgänge darin. Siehe ToolCalibration::issuesInReviewPeriod.
 * ─────────────────────────────────────────────────────────────────────────────
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tool_issues', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tool_id')->constrained('tools')->cascadeOnDelete();

            /*
             * Wer es hat. `nullOnDelete`: Scheidet der Mensch aus, bleibt der
             * Vorgang lesbar -- der Name steht daneben.
             */
            $table->foreignId('issued_to_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('issued_to_name', 160)->nullable();

            $table->timestamp('issued_at');
            $table->foreignId('issued_by_id')->nullable()->constrained('users')->nullOnDelete();

            /*
             * Wann es zurueck sein soll. Optional -- die meisten Ausgaben
             * dauern einen Nachmittag, und ein Pflichtdatum dafuer waere eine
             * Frage, die man wegklickt.
             */
            $table->date('due_back_at')->nullable();

            /** Woran gearbeitet wurde. Siehe Kopf: Text, kein Fremdschluessel. */
            $table->string('work_order_reference', 64)->nullable();

            $table->timestamp('returned_at')->nullable();
            $table->foreignId('returned_by_id')->nullable()->constrained('users')->nullOnDelete();

            $table->text('note')->nullable();

            $table->timestamps();

            /*
             * Die Frage, die diese Tabelle beantwortet: was ist gerade
             * draussen. Deshalb der Index auf genau der Kombination.
             */
            $table->index(['tool_id', 'returned_at']);
            $table->index(['returned_at', 'issued_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tool_issues');
    }
};
