<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Wartungsunterlagen mit Revisionsstand — „arbeiten wir nach dem aktuellen
 * Handbuch?"
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * DIE FRAGE STELLT JEDES AUDIT, und heute kann Aeronance sie nicht beantworten.
 *
 * 145.A.45 verlangt, dass die geltenden Instandhaltungsunterlagen vorhanden und
 * AKTUELL sind und dem Personal zur Verfügung stehen. Der Punkt ist nicht, dass
 * ein Handbuch existiert — das tut es immer, meistens als PDF in einem
 * Ordner —, sondern dass es der Stand ist, den der Hersteller gerade gültig
 * hält. Eine Revision, die zwei Jahre alt ist, sieht auf dem Papier genauso aus
 * wie die aktuelle.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * JEDE ZEILE IST EINE REVISION, kein Handbuch.
 *
 * Der naheliegende Entwurf wäre ein Datensatz je Handbuch mit einem Feld
 * „Revision", das man überschreibt. Genau das darf es nicht sein: Die Frage,
 * nach welchem Stand im Mai gearbeitet wurde, ist beim Überschreiben nicht mehr
 * zu beantworten — und sie ist die einzige, die im Ernstfall zählt.
 *
 * Deshalb: Eine neue Revision ist ein NEUER Datensatz, der den alten als
 * abgelöst markiert. Die Historie bleibt stehen, so wie beim Los und bei der
 * Kalibrierung.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * MUSTERWEIT ODER FÜR EIN EINZELNES LUFTFAHRZEUG, beides kommt vor.
 *
 * Das Wartungshandbuch gilt fürs Muster, das Instandhaltungsprogramm oft für
 * das einzelne Luftfahrzeug (unterschiedliche Ausrüstung, unterschiedliche
 * Nutzung). Deshalb zwei nullable Verweise statt einer Entscheidung, die für
 * die Hälfte der Fälle falsch wäre.
 * ─────────────────────────────────────────────────────────────────────────────
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('maintenance_manuals', function (Blueprint $table): void {
            $table->id();

            /*
             * Genau einer von beiden ist gesetzt -- Muster ODER Luftfahrzeug.
             * Erzwungen wird das in der Aktion und nicht hier: Eine
             * CHECK-Bedingung waere in MariaDB zwar moeglich, aber die
             * Fehlermeldung daraus kann einem Menschen nicht erklaeren, was er
             * falsch gemacht hat.
             */
            $table->foreignId('aircraft_type_id')->nullable()->constrained('aircraft_types')->cascadeOnDelete();
            $table->foreignId('aircraft_id')->nullable()->constrained('aircraft')->cascadeOnDelete();

            $table->string('kind', 24)->default('maintenance');

            $table->string('title', 200);

            /** Die Dokumentnummer des Herstellers, falls es eine gibt. */
            $table->string('reference', 128)->nullable();

            /*
             * DER REVISIONSSTAND, wie ihn der Hersteller schreibt -- "Rev. 12",
             * "Ausgabe 3", "Issue B". Als Text, weil es kein gemeinsames Schema
             * gibt und eine Zahl die Haelfte der Faelle nicht abbildet.
             */
            $table->string('revision', 64);

            /** Wann diese Revision herausgegeben wurde. */
            $table->date('revision_date')->nullable();

            /** Ab wann sie anzuwenden ist, falls das spaeter liegt. */
            $table->date('effective_from')->nullable();

            /*
             * ABGELOEST DURCH -- die Kette. Wer wissen will, was im Mai galt,
             * folgt ihr rueckwaerts.
             */
            $table->timestamp('superseded_at')->nullable();
            $table->foreignId('superseded_by_id')->nullable()
                ->constrained('maintenance_manuals')->nullOnDelete();

            /*
             * Zurueckgezogen, ohne Nachfolger -- ein Handbuch fuer ein Geraet,
             * das ausgebaut wurde. Etwas anderes als abgeloest, und beides
             * heisst "gilt nicht mehr".
             */
            $table->date('withdrawn_at')->nullable();
            $table->string('withdrawn_reason', 255)->nullable();

            $table->text('note')->nullable();

            $table->foreignId('recorded_by_id')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['aircraft_type_id', 'kind', 'superseded_at']);
            $table->index(['aircraft_id', 'kind', 'superseded_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('maintenance_manuals');
    }
};
