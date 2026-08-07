<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Unabhängige Kontrolle kritischer Arbeiten — das zweite Augenpaar.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * DIE LÜCKE, DIE DAS SCHLIESST, WAR ECHT.
 *
 * Die Karte kennt zwei Unterschriften: `complete` sagt, die Arbeit ist fertig,
 * `certify` sagt, sie wurde ordentlich gemacht. Beide darf dieselbe Person
 * leisten, wenn sie eine Part-66-Lizenz hat — das ist richtig so, denn genau
 * dafür ist die Lizenz da, und CertifyTaskCard erlaubt es ausdrücklich.
 *
 * Bei einer kritischen Arbeit ist es aber falsch. Wer eine Steuerung
 * angeschlossen hat, sieht seinen eigenen Fehler nicht — nicht aus Nachlässig-
 * keit, sondern weil er dieselbe Erwartung mitbringt, die ihn beim Anschließen
 * geleitet hat. Deshalb verlangt 145.A.48 für kritische Arbeiten eine
 * unabhängige Kontrolle DURCH JEMAND ANDEREN.
 *
 * Im Segelflug ist das kein theoretischer Punkt: Der falsch angeschlossene
 * Steuerungsanschluss beim Aufrüsten ist der klassische tödliche Fehler.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * SIE IST EIN DRITTER AKT, nicht die Freigabe unter anderem Namen.
 *
 * Die Freigabe ist eine Aussage über die Karte: Papier vollständig, Arbeit
 * ordnungsgemäß. Die unabhängige Kontrolle ist eine Aussage über das Werkstück:
 * Jemand hat NACHGESEHEN — an der Ruderanlenkung gezogen, den Splint gesucht,
 * den Anschluss angefasst. Beides in eine Unterschrift zu legen hieße, sich auf
 * die eine zu verlassen und die andere zu meinen.
 *
 * Deshalb eigene Spalten und eine eigene Reihenfolge: fertiggemeldet →
 * kontrolliert → freigegeben. Ohne Kontrolle keine Freigabe.
 * ─────────────────────────────────────────────────────────────────────────────
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('task_cards', function (Blueprint $table): void {
            /*
             * Kritisch oder nicht. Vorgabe FALSE, und das ist Absicht: Wäre
             * jede Karte kritisch, wäre die Kontrolle nach zwei Wochen ein
             * Haken, den man setzt, ohne hinzusehen. Der Wert der Markierung
             * liegt darin, dass sie selten ist.
             */
            $table->boolean('critical')->default(false)->after('activity_kind');

            /*
             * WARUM sie kritisch ist -- "Steuerungsanschluss", "Ruderanlenkung
             * getrennt". Der Kontrolleur muss wissen, WORAUF er sehen soll;
             * eine Karte, die nur "kritisch" sagt, schickt ihn suchen.
             */
            $table->string('critical_reason', 160)->nullable()->after('critical');

            $table->timestamp('inspected_at')->nullable()->after('work_performed');
            $table->foreignId('inspected_by')->nullable()->after('inspected_at')
                ->constrained('users')->nullOnDelete();
            $table->string('inspected_by_name', 160)->nullable()->after('inspected_by');

            /*
             * Was der Kontrolleur getan hat -- nicht was zu tun war. "Anlenkung
             * beidseitig gezogen, Sicherung sichtbar" ist ein Nachweis;
             * "kontrolliert" ist eine Behauptung.
             */
            $table->text('inspection_note')->nullable()->after('inspected_by_name');

            /*
             * Qualifikationsabdruck, falls vorhanden -- aber NUR Art und
             * Nummer, nicht die volle Trias wie bei der Freigabe. Kategorie und
             * Einschränkungen entscheiden über ein Freigaberecht; die
             * unabhängige Kontrolle ist kein Freigaberecht, sondern ein zweites
             * Augenpaar. Wer eine Lizenz hat, dessen Nummer steht dabei; wer
             * keine hat, darf trotzdem kontrollieren. Siehe die Aktion.
             */
            $table->string('inspection_qualification_type', 64)->nullable()->after('inspection_note');
            $table->string('inspection_qualification_reference', 128)->nullable()
                ->after('inspection_qualification_type');

            $table->index(['critical', 'state']);
        });
    }

    public function down(): void
    {
        Schema::table('task_cards', function (Blueprint $table): void {
            $table->dropIndex(['critical', 'state']);
            $table->dropConstrainedForeignId('inspected_by');
            $table->dropColumn([
                'critical',
                'critical_reason',
                'inspected_at',
                'inspected_by_name',
                'inspection_note',
                'inspection_qualification_type',
                'inspection_qualification_reference',
            ]);
        });
    }
};
