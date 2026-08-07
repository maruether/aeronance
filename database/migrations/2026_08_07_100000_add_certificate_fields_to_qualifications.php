<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Gegenstand und Aussteller — was ein Schulungsnachweis braucht und eine
 * Lizenz nicht hat.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * DIE ERSTE FASSUNG WAR ZU ENG GEFASST, und zwar an einer Stelle, die man
 * leicht übersieht: Sie schrieb den GEGENSTAND der Schulung in `reference` —
 * ein Feld, das laut eigenem Hilfetext die *Lizenz- oder Berechtigungsnummer*
 * ist und bei Feststellungen unveränderlich mitgeschrieben wird.
 *
 * Bei einer Lizenz fällt das nicht auf: „DE.66.00000" ist Nummer und
 * Bezeichnung zugleich, und die ausstellende Behörde steckt in der Nummer. Ein
 * Zertifikat hat beides getrennt — „Rotax 912 Service Training" ist der
 * Gegenstand, „ZERT-2024-0815" die Nummer — und dazu einen Aussteller, der
 * nirgends steckt: Bei einer Schulung ist „bei wem" die halbe Aussage.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * BEIDE SPALTEN SIND NULLABLE UND GELTEN FÜR ALLE TYPEN.
 *
 * Nicht auf Schulungen eingeschränkt: Auch bei einer Lizenz ist die
 * ausstellende Behörde eine sinnvolle Angabe, und ein Typ-abhängiges
 * Pflichtfeld in der Datenbank wäre eine Regel, die sich niemandem erklären
 * kann. Was wo verlangt wird, entscheidet das Formular.
 * ─────────────────────────────────────────────────────────────────────────────
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('qualifications', function (Blueprint $table): void {
            /*
             * WORUM es ging -- "Rotax 912 Service Training", "Klebeverfahren
             * Faserverbund", "Human Factors Auffrischung", "Fallschirm packen".
             * Freitext, weil die Bandbreite kein Schema hergibt.
             */
            $table->string('subject', 200)->nullable()->after('type');

            /*
             * WER geschult oder ausgestellt hat -- die Schulungsstelle, der
             * Hersteller, die Behoerde. Ohne diese Angabe ist ein Zertifikat
             * eine Behauptung ohne Absender.
             */
            $table->string('issuer', 160)->nullable()->after('reference');
        });
    }

    public function down(): void
    {
        Schema::table('qualifications', function (Blueprint $table): void {
            $table->dropColumn(['subject', 'issuer']);
        });
    }
};
