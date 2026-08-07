<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Die Zulassung eines Betriebs — aus Freitext wird eine prüfbare Angabe.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * ZWEI STELLEN FRAGEN HEUTE DANACH UND KÖNNEN ES NICHT NACHSEHEN.
 *
 * Die Eingangsprüfung stellt den Punkt „Ausstellende Stelle: Ist der Aussteller
 * zur Ausstellung berechtigt, Zulassungsnummer vorhanden und plausibel?" — und
 * der Mensch davor muss es wissen oder raten. Und `repair_dispatches` trägt
 * `shop_approval` als Freitext: Wohin ein Teil zur Instandsetzung ging, steht
 * da, ob der Betrieb dafür zugelassen WAR, weiß niemand.
 *
 * Eine Bescheinigung ist genau so viel wert wie die Zulassung dessen, der sie
 * ausgestellt hat. Steht die Nummer nur als Text da, ist sie eine Behauptung.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * AM LIEFERANTEN UND NICHT IN EINER EIGENEN TABELLE.
 *
 * Der Betrieb, der repariert, und die Firma, von der man kauft, sind dieselbe
 * Firma — Lange Aviation verkauft Ersatzteile UND repariert. Zwei Tabellen
 * hießen zwei Datensätze für einen Betrieb, die auseinanderlaufen, sobald
 * jemand die Adresse an einer Stelle ändert.
 *
 * Nicht jeder Lieferant ist ein zugelassener Betrieb — die Schraubenhandlung
 * ist keiner. Deshalb ist die Zulassung OPTIONAL und nicht Pflicht; ein
 * Pflichtfeld hier hieße, für jeden Baumarkt eine Nummer zu erfinden.
 * ─────────────────────────────────────────────────────────────────────────────
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('suppliers', function (Blueprint $table): void {
            /*
             * Die Zulassungsnummer, wie sie auf der Bescheinigung steht --
             * "EASA.145.1234", "DE.MF.1234", "EASA.21G.0123". Bewusst KEIN
             * Format erzwungen: Die Nummernkreise unterscheiden sich je nach
             * Zulassungsart und Behoerde, und ein zu enges Muster wuerde
             * ausgerechnet den seltenen Fall ablehnen, bei dem jemand
             * hinsieht.
             */
            $table->string('approval_number', 64)->nullable()->after('name');

            /*
             * Wofuer die Zulassung gilt -- "Part-145", "Part-M/F", "Part-21G",
             * oder in Worten. Als Text, weil eine Auswahlliste die Faelle
             * ausschliesst, die es auch gibt (Drittland-Betriebe, FAA-Repair
             * Stations).
             */
            $table->string('approval_scope', 128)->nullable()->after('approval_number');

            /*
             * WANN SIE ABLAEUFT. Das ist der eigentliche Zweck der ganzen
             * Migration: Eine Zulassung, die niemand nachhaelt, faellt genau
             * dann auf, wenn ein Auditor danach fragt -- also Jahre spaeter und
             * rueckwirkend fuer alles, was in der Zwischenzeit von dort kam.
             *
             * NULLABLE, denn viele Zulassungen sind unbefristet, solange die
             * Aufsicht sie nicht entzieht. Leer heisst hier ausdruecklich
             * "unbefristet" und nicht "unbekannt" -- wer es nicht weiss, traegt
             * nichts ein und hat dann auch keine Nummer.
             */
            $table->date('approval_expires_at')->nullable()->after('approval_scope');

            $table->index('approval_expires_at');
        });

        Schema::table('repair_dispatches', function (Blueprint $table): void {
            /*
             * Der Betrieb aus dem Verzeichnis, FALLS es einer ist. Name und
             * Nummer stehen weiterhin als Text daneben und werden beim
             * Versand kopiert -- wohin ein Teil ging, muss lesbar bleiben,
             * auch wenn der Betrieb spaeter umbenannt wird oder seine
             * Zulassung wechselt (E7). Der Verweis dient dem Nachschlagen,
             * nicht dem Nachweis.
             */
            $table->foreignId('supplier_id')->nullable()->after('destination')
                ->constrained('suppliers')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('repair_dispatches', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('supplier_id');
        });

        Schema::table('suppliers', function (Blueprint $table): void {
            $table->dropIndex(['approval_expires_at']);
            $table->dropColumn(['approval_number', 'approval_scope', 'approval_expires_at']);
        });
    }
};
