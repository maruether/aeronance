<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The equipment list, folded into what is already there.
 *
 * The BWLV keeps two sheets -- Ausrüstungsverzeichnis and
 * Betriebszeitenübersicht -- because paper cannot show one set of rows two ways.
 * They describe the same things: what is fitted, by whom, with what times
 * against it. So this is one table and two printouts, which is the own
 * framing: "ein Ausrüstungsverzeichnis in dem ich auch gleich die Laufzeiten und
 * co habe".
 *
 * Two flags, and they are NOT the same question:
 *
 *   is_present            -- the BWLV column, "ankreuzen, wenn vorhanden".
 *   is_minimum_equipment  -- the addition, and the one with teeth. Take out
 *                            the extra Garmin G5 and the aircraft flies; take out
 *                            the analogue instrument and it does not.
 *
 * The lever arm is here because the equipment list IS part of the weight and
 * balance record -- the BWLV column reads "Einbauort, oder Hebelarm in mm vom
 * Bezugspunkt (+/- Vorzeichen beachten)". Signed, in millimetres, and nullable,
 * since most rows never carry one.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('installations', function (Blueprint $table): void {
            /** Baumuster -- the model designation, which is not the part number. */
            $table->string('type_designation', 128)->nullable()->after('part_number');

            $table->string('manufacturer', 128)->nullable()->after('type_designation');

            /*
             * Millimetres from the datum, sign included. An integer because
             * tenths of a millimetre on a lever arm are noise, and signed
             * because the datum is not at the nose.
             */
            $table->integer('lever_arm_mm')->nullable()->after('position');

            $table->boolean('is_present')->default(true)->after('lever_arm_mm');

            /*
             * The one that decides whether the aircraft may go. Indexed because
             * the airworthiness check asks for exactly this set.
             */
            $table->boolean('is_minimum_equipment')->default(false)->after('is_present')->index();
        });
    }

    public function down(): void
    {
        Schema::table('installations', function (Blueprint $table): void {
            $table->dropColumn([
                'type_designation',
                'manufacturer',
                'lever_arm_mm',
                'is_present',
                'is_minimum_equipment',
            ]);
        });
    }
};
