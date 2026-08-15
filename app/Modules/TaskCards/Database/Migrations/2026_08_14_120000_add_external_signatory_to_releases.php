<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Die Freigabe durch jemanden, der nicht im Verein ist.
 *
 * Feldtest: "wir brauchen noch die möglichkeit eines ‚Freigegeben durch',
 * falls der prüfer nicht im verein ist." Der Regelfall im kleinen Verein: Ein
 * freiberuflicher Part-66-Prüfer oder ein LTB zeichnet die Nachprüfung ab --
 * er hat kein Konto hier und wird auch keins bekommen.
 *
 * ZWEI SPALTEN, weil es zwei verschiedene Menschen sind: `released_by` bleibt
 * für den Fall, dass ein Mitglied selbst freigibt (dann steht dort sein Konto,
 * und die Qualifikation wurde geprueft). Bei einer externen Freigabe ist es
 * NULL -- die Bescheinigung traegt den Namen des Pruefers, und `recorded_by`
 * sagt, wer sie eingetragen hat. Die beiden zu vermischen hiesse zu behaupten,
 * das Konto habe unterschrieben.
 *
 * `is_external` ist kein abgeleiteter Wert, sondern eine Aussage: Diese
 * Fassung des Systems hat die Lizenz NICHT geprueft, sondern abgeschrieben.
 * Wer die Bescheinigung spaeter liest, muss das unterscheiden koennen, ohne
 * aus einer NULL-Spalte zu schliessen.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('releases', function (Blueprint $table): void {
            $table->boolean('is_external')->default(false)->after('released_by_name');
            $table->foreignId('recorded_by')->nullable()->after('is_external')
                ->constrained('users')->nullOnDelete();
            $table->string('recorded_by_name', 160)->nullable()->after('recorded_by');

            // Der Betrieb, wenn nicht eine Person, sondern eine Organisation
            // zeichnet -- ein LTB hat eine Betriebsnummer, keine Lizenz.
            $table->string('external_organisation', 160)->nullable()->after('recorded_by_name');
        });
    }

    public function down(): void
    {
        Schema::table('releases', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('recorded_by');
            $table->dropColumn(['is_external', 'recorded_by_name', 'external_organisation']);
        });
    }
};
