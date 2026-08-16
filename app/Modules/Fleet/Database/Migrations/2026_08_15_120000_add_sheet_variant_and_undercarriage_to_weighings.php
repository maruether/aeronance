<?php

declare(strict_types=1);

use App\Modules\Fleet\Enums\SheetVariant;
use App\Modules\Fleet\Enums\Undercarriage;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Das Wägeblatt bekommt seine Überschrift, sein Fahrwerk -- und Bestandsblätter
 * bekommen ihre Auflagenzeilen nachgereicht.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * DIE NACHRÜSTUNG IST DER EIGENTLICHE ANLASS.
 *
 * Feldtest zu v0.1.8: „Ich habe immer noch beim anlegen der wägung die kacheln
 * zum werte eintragen und in der Druckansicht die Tabelle. Keine Grafik."
 *
 * Die Grafik hängt an den Auflagenzeilen, und die legt seit v0.1.8 nur der
 * Anlegeweg an. Wer sein Blatt vorher angelegt hat, hat keine -- und bekam
 * deshalb nie eine Zeichnung zu sehen, ohne dass irgendwo stand, warum. Eine
 * Reparatur, die nur für künftige Datensätze gilt, ist für den, der es gemeldet
 * hat, keine.
 *
 * WAS DABEI NICHT PASSIERT: kein recalculate(). Ein abgezeichnetes Blatt ist
 * eingefroren (Weighing::booted), und leere Auflagenzeilen ändern ohnehin keine
 * Zahl -- netto() ist 0, die Summe bleibt, der Schwerpunkt bleibt null. Die
 * Nachrüstung schreibt ausschliesslich in weighing_entries und rührt die
 * Ergebnisspalten nicht an.
 * ─────────────────────────────────────────────────────────────────────────────
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('weighings', function (Blueprint $table): void {
            /*
             * Die Überschrift des Blatts. Drei Werte, wo `kind` zwei hat: Der
             * Motorsegler rechnet wie ein Flugzeug und heisst anders.
             */
            $table->string('sheet_variant', 16)->nullable()->after('kind');

            // Worauf es beim Wiegen steht -- bestimmt Wägepunkte und Zeichnung.
            $table->string('undercarriage', 24)->nullable()->after('sheet_variant');

            /*
             * „Schwerpunktbereich laut Flughandbuch von __ bis __ mm BEI
             * LEERMASSE __ kg". Die Bezugsmasse stand auf dem Blatt und
             * nirgends im Schema; ohne sie ist der Bereich nur die halbe
             * Aussage.
             */
            $table->decimal('cg_range_at_mass_kg', 10, 2)->nullable()->after('cg_range_to_mm');
        });

        /*
         * Bestandsblätter bekommen die Blattart aus dem Rechenweg, den sie
         * ohnehin haben. Motorsegler von Flugzeug zu unterscheiden kann
         * niemand nachträglich -- das bleibt „Flugzeug", bis es jemand ändert.
         */
        DB::table('weighings')->whereNull('sheet_variant')->update([
            'sheet_variant' => DB::raw(sprintf(
                "CASE WHEN kind = 'glider' THEN '%s' ELSE '%s' END",
                SheetVariant::Glider->value,
                SheetVariant::Aeroplane->value,
            )),
        ]);

        DB::table('weighings')->whereNull('undercarriage')->update([
            'undercarriage' => DB::raw(sprintf(
                "CASE WHEN kind = 'glider' THEN '%s' ELSE '%s' END",
                Undercarriage::TailwheelOneMain->value,
                Undercarriage::Tricycle->value,
            )),
        ]);

        $this->nachtragenFehlenderAuflagen();
    }

    /**
     * Jedem Blatt ohne Auflagenzeilen die vorgedruckten geben.
     */
    private function nachtragenFehlenderAuflagen(): void
    {
        $mitAuflagen = DB::table('weighing_entries')
            ->where('section', 'support')
            ->distinct()
            ->pluck('weighing_id')
            ->all();

        $ohne = DB::table('weighings')
            ->when($mitAuflagen !== [], fn ($q) => $q->whereNotIn('id', $mitAuflagen))
            ->get(['id', 'undercarriage']);

        $jetzt = now();

        foreach ($ohne as $blatt) {
            $fahrwerk = Undercarriage::tryFrom((string) $blatt->undercarriage)
                ?? Undercarriage::TailwheelOneMain;

            $zeilen = [];
            $position = 0;

            foreach ($fahrwerk->supports() as $bezeichnung) {
                $zeilen[] = [
                    'weighing_id' => $blatt->id,
                    'section' => 'support',
                    'label' => $bezeichnung,
                    'position' => $position++,
                    'created_at' => $jetzt,
                    'updated_at' => $jetzt,
                ];
            }

            if ($zeilen !== []) {
                DB::table('weighing_entries')->insert($zeilen);
            }
        }
    }

    public function down(): void
    {
        Schema::table('weighings', function (Blueprint $table): void {
            $table->dropColumn(['sheet_variant', 'undercarriage', 'cg_range_at_mass_kg']);
        });

        /*
         * Die nachgetragenen Auflagenzeilen bleiben stehen. Sie von denen zu
         * unterscheiden, die jemand selbst eingetragen hat, ginge nur über
         * Raten -- und eine Rücknahme, die dabei echte Wägedaten löscht, ist
         * schlimmer als eine leere Zeile zu viel.
         */
    }
};
