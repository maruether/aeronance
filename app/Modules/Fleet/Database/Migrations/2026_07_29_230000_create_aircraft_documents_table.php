<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Papers that hang on an aircraft and may or may not run out.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * THE VALIDITY IS OPTIONAL, AND THAT IS THE WHOLE POINT.
 *
 * Vorgabe: "vorsicht mit fälligkeiten ... ist oft ein 'kommt drauf an' thema.
 * Manche lfz brauchen z.B. alle 4 Jahre eine wägung, andere nur bei bedarf. Das
 * gilt für alle dokumente und bauteile."
 *
 * So a document with no expiry is not a document with a missing expiry. It is a
 * document that does not expire, and the difference matters in both directions:
 * inventing a deadline would nag about something nobody owes, and treating the
 * absence as an oversight would fill the due list with false work.
 *
 * The airworthiness review is the exception and stays in its own table: it
 * ALWAYS expires, its absence is always a finding, and it carries fields nothing
 * else has.
 * ─────────────────────────────────────────────────────────────────────────────
 *
 * This also absorbs the maintenance programme. Vorgabe: "IHP gibt es nicht mehr,
 * ist inzwischen ein AMP. Das lässt sich als Dokument anhängen, sich daraus
 * ergebene änderungen in laufzeiten und wartungsintervallen wird anderswo
 * eingepflegt." So it is a document like the others, and the intervals it
 * dictates live on the component limits where they can actually be acted on --
 * not in a second place that would drift from the first.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('aircraft_documents', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('aircraft_id')->constrained()->cascadeOnDelete();

            // amp | weighing_report | noise | radio | insurance | registration |
            // flight_manual | other
            $table->string('type', 32)->index();

            $table->string('title', 160);
            $table->string('reference', 128)->nullable();

            $table->date('issued_at')->nullable();

            /*
             * Null means it does not expire. Not "we forgot" -- "it does not".
             */
            $table->date('valid_until')->nullable()->index();

            $table->string('issued_by', 160)->nullable();
            $table->text('note')->nullable();

            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['aircraft_id', 'type']);
        });

        /*
         * The maintenance programme table goes.
         *
         * Built on the assumption that an AMP is a record with fields --
         * reference, approval, next review. It is not: it is a document one
         * attaches, and what follows from it is entered as intervals on the
         * components. Leaving an unused table that contradicts the domain would
         * be worse than removing it, and nothing has ever written to it.
         */
        Schema::dropIfExists('maintenance_programmes');
    }

    public function down(): void
    {
        Schema::dropIfExists('aircraft_documents');

        Schema::create('maintenance_programmes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('aircraft_id')->constrained()->cascadeOnDelete();
            $table->string('reference', 128)->nullable();
            $table->date('approved_at')->nullable();
            $table->string('approved_by', 160)->nullable();
            $table->date('next_review_at')->nullable();
            $table->text('note')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }
};
