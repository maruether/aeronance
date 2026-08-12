<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Komponentenmuster kennen ihren Bauteiltyp -- und tragen Muster-Laufzeiten.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * Feldtest, woertlich: "Dies ueberlappt sich zum teil mit Bauteiltypen. Eine
 * schleppkupplung oder ein hoehenmesser konnen beides sein. Kopplung zwischen
 * beiden?" -- und die Freigabe dazu: Einbau aus dem Lager erbt die Laufzeiten
 * des Musters.
 *
 * ZWEI DINGE ENTSTEHEN HIER, und beide liegen in der FLOTTE:
 *
 *  1. component_types.part_type_id -- die LOSE Referenz auf den Bauteiltyp
 *     des Lagers. Kein Fremdschluessel, wie bei installations.part_type_id
 *     und beim Vereinsflieger-AircraftLink: Das Lager weiss nichts von der
 *     Flotte, und beide Module muessen einzeln abschaltbar bleiben. Die
 *     Kopplung ist ein Feature der Flotte, also liegt ihr Verweis hier.
 *     UNIQUE, weil die Rueckwaertssuche (Bauteiltyp -> Muster) beim Einbau
 *     eindeutig sein muss -- zwei Muster fuer denselben Bauteiltyp waeren
 *     zwei Wahrheiten ueber dieselben Laufzeiten.
 *
 *  2. component_type_limits -- die VORLAGEN. Die Laufzeiten selbst haengen
 *     am Einbau (component_limits), nicht am Muster, und das bleibt so: Beim
 *     Einbau aus dem Lager werden die Vorlagen KOPIERT, nie referenziert.
 *     E7-Disziplin: Wer spaeter die Vorlage am Muster aendert, aendert damit
 *     keinen bestehenden Einbau -- der wurde unter den damaligen Grenzen
 *     eingebaut, und genau das muss der Nachweis zeigen. due_on fehlt mit
 *     Absicht: Ein festes Datum ist die Eigenschaft EINES Einbaus, kein
 *     Merkmal des Musters.
 * ─────────────────────────────────────────────────────────────────────────────
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('component_types', function (Blueprint $table): void {
            $table->unsignedBigInteger('part_type_id')->nullable()->unique()->after('part_number');
        });

        Schema::create('component_type_limits', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('component_type_id')->constrained()->cascadeOnDelete();

            // calendar_months | flight_hours | landings | engine_hours |
            // starts | cycles -- wie component_limits, ohne calendar_date.
            $table->string('kind', 24)->index();

            $table->decimal('value', 12, 2);
            $table->decimal('tolerance_percent', 5, 2)->nullable();
            $table->decimal('tolerance_absolute', 12, 2)->nullable();

            /** TBO, TBR, LTA, Herstellerblatt -- woher die Grenze stammt. */
            $table->string('source', 160)->nullable();

            $table->text('note')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('component_type_limits');

        Schema::table('component_types', function (Blueprint $table): void {
            $table->dropColumn('part_type_id');
        });
    }
};
