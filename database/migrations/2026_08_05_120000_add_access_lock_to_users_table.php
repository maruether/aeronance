<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Der Not-Aus — eine Sperre, die kein Abgleich wieder aufhebt.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * WARUM DAS NICHT `is_active` SEIN KANN.
 *
 * `is_active` gehört dem Provider: `LinkExternalIdentity` setzt es bei jedem
 * nächtlichen Lauf auf das, was Vereinsflieger sagt. Wer jemanden dort von Hand
 * abschaltet, hat ihn bis 2 Uhr morgens abgeschaltet — und genau dann, wenn es
 * eilt, ist das die falsche Antwort. Ein Streit im Verein, ein abhanden
 * gekommenes Notebook, ein Verdacht: Der Zugang muss in dieser Minute weg sein
 * und weg bleiben, ohne dass jemand erst im Mitgliederverwaltungssystem etwas
 * ändern kann oder darf.
 *
 * Deshalb zwei getrennte Aussagen:
 *
 *   is_active   — „Der Provider führt diesen Menschen als Mitglied."
 *   locked_at   — „DIESER Betrieb hat den Zugang gesperrt."
 *
 * Zugang gibt es nur, wenn beides stimmt (User::hasAccess()). Der Abgleich
 * fasst `locked_at` nie an: Wer gesperrt ist, bleibt gesperrt, auch wenn er in
 * Vereinsflieger munter aktiv ist — und auch über ein Aus und Wieder-Ein dort
 * hinweg.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * WARUM GRUND UND URHEBER MIT DAZUGEHÖREN.
 *
 * Eine Sperre ohne Begründung ist in drei Monaten eine offene Frage: Niemand
 * traut sich, sie aufzuheben, weil keiner weiß, warum sie da ist. Und ein
 * Betrieb, der einem Menschen den Zugang entzieht, muss sagen können, wer das
 * entschieden hat — spätestens wenn der Betroffene fragt.
 * ─────────────────────────────────────────────────────────────────────────────
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->timestamp('locked_at')->nullable()->after('is_active');
            $table->string('lock_reason')->nullable()->after('locked_at');

            /*
             * Wer gesperrt hat. `nullOnDelete` und nicht `cascade`: Scheidet
             * der Sperrende aus, verschwindet der Name -- die SPERRE bleibt.
             * Andersherum wäre es eine Katastrophe mit Ansage.
             */
            $table->foreignId('locked_by_id')
                ->nullable()
                ->after('lock_reason')
                ->constrained('users')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('locked_by_id');
            $table->dropColumn(['locked_at', 'lock_reason']);
        });
    }
};
