<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Werkzeuge und ihre Kalibrierung — der zweite Part-145-Baustein.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * 145.A.40 verlangt, dass die nötigen Werkzeuge vorhanden sind, dass sie
 * kontrolliert und dass sie kalibriert werden, wo Genauigkeit zählt. Der Teil,
 * den ein Verein regelmäßig verliert, ist nicht die Kalibrierung selbst — die
 * macht ein Labor — sondern das WISSEN, wann sie fällig ist. Ein
 * Drehmomentschlüssel sieht nach zwei Jahren aus wie nach zwei Wochen.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * DIE LÜCKE IST DER EIGENTLICHE PUNKT, und sie hat einen eigenen Datensatz.
 *
 * Wird ein Werkzeug überfällig nachkalibriert, ist die Zeit zwischen dem Ablauf
 * der alten Kalibrierung und der neuen Messung eine Zeit, in der mit einem
 * Werkzeug gearbeitet wurde, dessen Genauigkeit niemand belegen kann. 145.A.40
 * verlangt dann, die damit ausgeführte Arbeit zu bewerten. Genau dieses
 * Zeitfenster hält `tool_calibrations.gap_started_at` fest — sonst ist die
 * Information nach dem nächsten Kalibrierschein verschwunden, weil der neue
 * Schein nur sagt, dass jetzt alles stimmt.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * OFFEN, BEWUSST NICHT GERATEN (siehe Modul-Docblock): Kalibrierintervalle
 * laufen hier über die ZEIT (Monate). Nutzungsabhängige Intervalle — alle N
 * Anwendungen — gibt es nicht, weil das Zählen der Anwendungen eine
 * Erfassungspflicht bei jedem Handgriff wäre und die Frage, ob ein Verein das
 * will, noch offen ist.
 * ─────────────────────────────────────────────────────────────────────────────
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tools', function (Blueprint $table): void {
            $table->id();

            /*
             * Die Nummer, die am Werkzeug klebt. Eindeutig, weil sie auf dem
             * Kalibrierschein steht: Zwei Drehmomentschluessel mit derselben
             * Nummer machen jeden Schein wertlos.
             */
            $table->string('inventory_number', 64)->unique();

            $table->string('name');
            $table->string('manufacturer')->nullable();
            $table->string('model')->nullable();
            $table->string('serial_number', 64)->nullable();

            /** Wo es liegt -- als Text, weil das Lagerortmodell des Lagers fuer
             *  Bauteile gedacht ist und ein Werkzeug im Werkstattwagen liegt. */
            $table->string('location')->nullable();

            $table->string('state', 24)->default('in_service');

            /*
             * Nicht jedes Werkzeug wird kalibriert. Ein Schraubendreher nicht,
             * ein Drehmomentschluessel schon. Ohne dieses Flag stuende die
             * halbe Liste ewig auf "keine Kalibrierung hinterlegt" und die
             * Warnung waere nach einer Woche Hintergrundrauschen.
             */
            $table->boolean('calibration_required')->default(false);

            $table->unsignedSmallInteger('calibration_interval_months')->nullable();

            /*
             * Gespeichert, nicht gerechnet: Wer das Intervall spaeter aendert,
             * darf damit nicht rueckwirkend eine abgelaufene Kalibrierung
             * wieder gueltig machen. Dieselbe Regel wie beim Verfallsdatum im
             * Lager.
             */
            $table->date('calibration_due_at')->nullable();

            $table->text('note')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['state', 'calibration_due_at']);
        });

        Schema::create('tool_calibrations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tool_id')->constrained('tools')->cascadeOnDelete();

            $table->date('performed_at');

            /*
             * NULLABLE, weil nicht jeder Kalibrierschein eine Gueltigkeit
             * nennt: Manche Labore schreiben nur, wann gemessen wurde, und das
             * Intervall ist Sache des Betriebs. Steht dann auch beim Werkzeug
             * kein Intervall, bleibt das Faelligkeitsdatum leer -- und das
             * Werkzeug gilt als ueberfaellig. Das ist kein Fehler, sondern der
             * Hinweis, dass fuer dieses Werkzeug noch kein Intervall
             * festgelegt wurde; genau das verlangt ein Kalibrierprogramm.
             */
            $table->date('valid_until')->nullable();

            /** Wer gemessen hat -- in aller Regel ein externes Labor. */
            $table->string('provider')->nullable();
            $table->string('certificate_reference', 128)->nullable();

            /*
             * DIE LUECKE. Gesetzt, wenn diese Kalibrierung eine abgelaufene
             * abloest: ab wann die Genauigkeit nicht mehr belegt war. Bleibt
             * fuer immer stehen -- die Frage "womit wurde in dieser Zeit
             * gearbeitet" beantwortet sich nicht dadurch, dass das Werkzeug
             * heute wieder stimmt.
             */
            $table->date('gap_started_at')->nullable();

            /** Wurde die Luecke bewertet, und mit welchem Ergebnis. */
            $table->timestamp('gap_reviewed_at')->nullable();
            $table->foreignId('gap_reviewed_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('gap_review_note')->nullable();

            $table->text('note')->nullable();

            $table->foreignId('recorded_by_id')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            $table->index(['tool_id', 'performed_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tool_calibrations');
        Schema::dropIfExists('tools');
    }
};
