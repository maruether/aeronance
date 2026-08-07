<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Bestellungen — Lieferverfolgung, keine Warenwirtschaft.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * Vorgabe vom 2026-08-06: „Lager meldet mir das etwas fehlt -> ich bestelle nach
 * -> ich bestätige die Meldung des lagers mit bestellnummern, lieferant,
 * vorraussichtliches lieferdatum und bestellten teilen+mengen -> falls die
 * bestellung nicht rechtzeitig kommt erinnert mich das modul via mail -> nach
 * ankunft gehe ich auf ‚offene bestellungen' und buche von da aus alles ein
 * inklusive lagertags. erst wenn alles abgehakt ist ist die bestellung
 * erledigt."
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * DAS IST KEINE REVISION VON E6. Vorgabe dazu ausdruecklich:
 *
 *   „Es geht bei den bestellungen nicht darum über aeronance bestellungen
 *   auszuführen oder die Kosten zu führen sondern nur darum einen reminder zu
 *   bekommen. Der Hintergrund ist das ich gerade erst mit einem Lieferanten
 *   auf die nase gefallen bin der sich nicht gemeldet hatte. Das hätte mir
 *   fast einen Termin gerissen."
 *
 * DER ERINNERER IST DER ZWECK, die Tabellen sind nur, woran er haengt. Es
 * entsteht KEINE Beschaffungskette: keine Preise, keine Rechnungen, keine
 * Konditionen, keine Lieferantenbewertung, keine Einkaufshistorie. Drei
 * Fragen, mehr nicht:
 *
 *   Was habe ich bestellt?  Kommt es noch?  Was davon ist angekommen?
 *
 * Die zweite ist die, wegen der es das gibt. Ein Lieferant, der sich nicht
 * meldet, faellt sonst erst auf, wenn das Luftfahrzeug schon steht -- und
 * genau das ist passiert.
 *
 * Der Nutzen am anderen Ende: Beim Eintreffen wird ueber die BESTEHENDE
 * Lageraktion eingebucht, also mit Los, Form 1 und Etikett. Die Bestellung
 * fuehrt damit in die Nachweiskette hinein, statt eine zweite Welt daneben
 * aufzumachen. Kommt jemals ein Preisfeld dazu, ist E6 wirklich gebrochen --
 * bis dahin nicht.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * WARUM MENGEN IN DER POSITION UND NICHT ALS BEWEGUNG.
 *
 * Eine bestellte Menge ist kein Bestand. Sie liegt nicht im Regal, sie ist
 * nicht verfuegbar, und sie darf in keiner Auswertung als Bestand auftauchen --
 * sonst rechnet sich ein Verein reich mit Teilen, die noch beim Lieferanten
 * stehen. Deshalb steht sie hier und nicht in stock_movements. Erst das
 * Einbuchen erzeugt eine Bewegung, und zwar ueber ReceiveStock wie jeder
 * andere Wareneingang auch.
 * ─────────────────────────────────────────────────────────────────────────────
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('purchase_orders', function (Blueprint $table): void {
            $table->id();

            /*
             * DIE NUMMER DES LIEFERANTEN, nicht eine eigene. Bestellt wird
             * ausserhalb dieses Systems -- am Telefon, per Mail, im Webshop --
             * und hinterher eingetragen, was ankam. Eine hausgemachte
             * Nummer waere eine zweite Wahrheit, die auf keinem Lieferschein
             * steht.
             *
             * Nicht eindeutig: Zwei Lieferanten duerfen dieselbe schlichte
             * Nummer vergeben, und wer eine Bestellung nachtraegt, hat sie
             * vielleicht gar nicht.
             */
            $table->string('order_number', 64)->nullable();

            $table->foreignId('supplier_id')->constrained('suppliers');

            $table->date('ordered_at');

            /*
             * Das zugesagte Lieferdatum. NULLABLE, weil es das nicht immer
             * gibt -- und weil eine erfundene Zusage schlimmer waere als
             * keine: Die Erinnerung haengt genau daran.
             */
            $table->date('expected_at')->nullable();

            $table->string('state', 24)->default('open');

            $table->date('cancelled_at')->nullable();
            $table->string('cancel_reason', 255)->nullable();

            $table->text('note')->nullable();

            /*
             * Wer die Bestellung eingetragen hat -- an diese Person geht die
             * Erinnerung. `nullOnDelete`: Scheidet sie aus, bleibt die
             * Bestellung stehen, nur die Erinnerung findet niemanden mehr.
             */
            $table->foreignId('created_by_id')->nullable()->constrained('users')->nullOnDelete();

            /*
             * Wann zuletzt erinnert wurde. Ohne das schriebe der taegliche Lauf
             * jeden Morgen dieselbe Mail, bis die Lieferung kommt -- und eine
             * Erinnerung, die man wegwischt, ohne sie zu lesen, ist keine.
             */
            $table->timestamp('reminded_at')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['state', 'expected_at']);
        });

        Schema::create('purchase_order_lines', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('purchase_order_id')->constrained('purchase_orders')->cascadeOnDelete();
            $table->foreignId('part_type_id')->constrained('part_types');

            $table->decimal('quantity_ordered', 12, 3);

            /*
             * Teillieferungen sind der Normalfall, nicht die Ausnahme --
             * deshalb eine eigene Spalte statt eines Hakens. "Erst wenn alles
             * abgehakt ist, ist die Bestellung erledigt" laesst sich nur so
             * beantworten.
             */
            $table->decimal('quantity_received', 12, 3)->default(0);

            $table->text('note')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_order_lines');
        Schema::dropIfExists('purchase_orders');
    }
};
