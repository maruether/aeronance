<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Whether a job is within pilot-owner maintenance -- and what the signature's
 * licence was limited to.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * WHY A CARD NEEDS THIS. Some Part-66 licences carry the entry "no maintenance
 * exceeding MA.803(b)" -- typically those written over from a national
 * qualification (66.A.70; in Baden-Württemberg the BWLV ones). Vorgabe: "die
 * Leute dürfen damit nicht mehr Sachen freigeben als ein P/O, aber für
 * Fremdarbeiten."
 *
 * So the cap is on SCOPE, not on whose work it is: the holder may sign off
 * somebody else's job, but only a job that a pilot-owner would have been allowed
 * to do -- the limited task list of Appendix VIII to Part-M (Part C for
 * sailplanes and powered sailplanes), referenced by M.A.803(b).
 *
 * Nothing in the system knew whether a job is on that list, and nothing can work
 * it out: "Ruder gangbar machen" is on it, "Ruderanschluss instand setzen" is
 * not, and no field distinguishes them. So it is asked -- once, by whoever
 * writes the card, before anybody has an interest in the answer.
 *
 * THREE STATES, and null is a real one: not assessed. For the capped holder that
 * is a refusal, the same way an unassessed airworthiness directive blocks a
 * release -- Vorgabe: "nicht beurteilt ist ne red flag." For everybody else it
 * means nothing at all, which is why the column is nullable rather than
 * defaulted: an existing card does not become "beyond pilot-owner scope" by
 * having been written before the question existed.
 * ─────────────────────────────────────────────────────────────────────────────
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('task_cards', function (Blueprint $table): void {
            // true = within limited pilot-owner maintenance, false = beyond it,
            // null = nobody has assessed it.
            $table->boolean('within_pilot_owner_scope')->nullable()->after('activity_kind');

            /*
             * The limitations on the licence that signed this off, frozen with
             * the rest of the credential (E7).
             *
             * Without it the record loses the one thing that makes a limited
             * signature reviewable: point 66.A.50 lets limitations be lifted
             * once the holder demonstrates the experience, and a card signed
             * last year would then read as if the licence had never been
             * restricted. It also carries the limitations nothing can check
             * automatically -- an avionics or free-text exclusion -- so an
             * auditor sees them even where the software could not act on them.
             */
            $table->string('qualification_limitations', 255)->nullable()->after('qualification_category');
        });

        Schema::table('releases', function (Blueprint $table): void {
            $table->string('qualification_limitations', 255)->nullable()->after('qualification_category');
        });
    }

    public function down(): void
    {
        Schema::table('task_cards', function (Blueprint $table): void {
            $table->dropColumn(['within_pilot_owner_scope', 'qualification_limitations']);
        });

        Schema::table('releases', function (Blueprint $table): void {
            $table->dropColumn('qualification_limitations');
        });
    }
};
