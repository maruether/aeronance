<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Sammel-Anweisungen sprengen die Musterspalte.
 *
 * Feldtest, wörtlich aus dem Fehler: C.E.A.P.R.s SB 090702 gilt für
 * "DR400/(...);R3000/(...);DR220(...), DR221(...);HR100(...), ..." -- die
 * komplette Robin-Palette in einer Zeile, gemessen bis 142 Zeichen (und das
 * "(...)" steht wörtlich so in deren Liste). `subject_model` war 96 Zeichen
 * breit; der Import starb mit "Data too long", und zwar für die GANZE Quelle.
 *
 * Verbreitert statt gekürzt, denn Kürzen hieße still Muster verlieren --
 * genau die Zeile "gilt auch für DR 315", die hinten abgeschnitten würde,
 * wäre für irgendeinen Verein die entscheidende. 300 Zeichen tragen das
 * Gemessene mit Reserve und bleiben indexierbar (utf8mb4: 1200 von maximal
 * 3072 Bytes). `subject_designation` zieht auf dieselbe Breite mit -- die
 * zweite Aufzählungsspalte, dieselbe Fehlerklasse.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('directives', function (Blueprint $table): void {
            $table->string('subject_model', 300)->nullable()->change();
            $table->string('subject_designation', 300)->nullable()->change();
        });
    }

    public function down(): void
    {
        // Nicht auf 96 zurück: Zeilen mit langen Aufzählungen stünden dann
        // schon in der Tabelle, und das down() schnitte sie ab -- derselbe
        // stille Verlust, gegen den das up() gebaut ist.
    }
};
