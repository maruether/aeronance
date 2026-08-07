<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Work orders, task cards, findings and hours.
 *
 * The module CLAUDE.md has been pointing at since the beginning, and the one the
 * Part-66 logbook depends on: "Das Erfahrungslogbuch ist eine Auswertung, keine
 * Extra-Pflege." That only works if the cards carry the fields from the first
 * one -- date, registration, type, ATA chapter, kind of work, duration,
 * executed/assisted, certifying person -- so they are here rather than added
 * later when there is already a year of cards without them.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * TWO SIGNATURES ON A CARD, and The brief picked that over one: "wer die arbeit
 * gemacht hat, meldet sie fertig. ein Qualifizierter zeichnet sie danach ab."
 *
 * So completed_* and certified_* are separate sets of columns. One is a
 * statement that the work is finished, made by whoever did it; the other is a
 * judgement about it, made by somebody who may make judgements. Collapsing them
 * would either lock the mechanic out of his own card or let unchecked work pass
 * as certified.
 * ─────────────────────────────────────────────────────────────────────────────
 *
 * The aircraft is a real foreign key here, unlike in the warehouse. This module
 * DECLARES that it requires the fleet, so the table is guaranteed to exist --
 * where the warehouse keeps a plain string precisely because it does not (D4).
 */
return new class extends Migration
{
    public function up(): void
    {
        /*
         * The bracket over a visit: "D-KABC zur Jahresnachprüfung".
         *
         * Worth having separately from the cards because the counters at the
         * start and end of a visit are a property of the visit, and because
         * "what did we do to this aircraft last spring" is the question people
         * actually ask.
         */
        Schema::create('work_orders', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('aircraft_id')->constrained()->cascadeOnDelete();

            $table->string('number', 32)->unique();
            $table->string('title', 160);
            $table->text('description')->nullable();

            $table->date('opened_at')->index();
            $table->date('closed_at')->nullable()->index();

            $table->foreignId('opened_by')->nullable()->constrained('users')->nullOnDelete();

            // open | closed | cancelled
            $table->string('state', 16)->default('open')->index();

            /*
             * The aircraft's counters when the visit began and ended. Copied,
             * because a card written months later has to say what the aircraft
             * had done at the time and not what it has done now.
             */
            $table->json('counters_at_open')->nullable();
            $table->json('counters_at_close')->nullable();

            /*
             * Where an external shop did the work. No foreign key: the fleet
             * owns that table and this module must not break if the record is
             * removed, so the reference is deliberately loose.
             */
            $table->unsignedBigInteger('external_work_order_id')->nullable()->index();

            $table->text('note')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('task_cards', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('work_order_id')->constrained()->cascadeOnDelete();

            $table->string('number', 32)->index();
            $table->string('title', 160);
            $table->text('instruction')->nullable();

            /* ---- Part-66 fields, from the very first card ---- */

            /*
             * Registration and type are COPIED, not read through the aircraft.
             *
             * An experience logbook entry has to stay readable when the aircraft
             * is sold, re-registered or deleted -- the entry records what
             * somebody worked on that day, and that fact does not change because
             * the fleet list did.
             */
            $table->string('aircraft_registration', 32)->index();
            $table->string('aircraft_model', 96)->nullable();

            /*
             * ATA chapter as free text with a suggestion list in the interface.
             * the choice, and the right one for gliding: a fixed list would
             * force somebody to pick a chapter where none fits, and what gets
             * picked then is worse than nothing.
             */
            $table->string('ata_chapter', 16)->nullable()->index();

            /** inspection | maintenance | repair | modification | ad_compliance | other */
            $table->string('activity_kind', 24)->default('maintenance')->index();

            // open | completed | certified | cancelled
            $table->string('state', 16)->default('open')->index();

            /* ---- first signature: the work is finished ---- */
            $table->timestamp('completed_at')->nullable();
            $table->foreignId('completed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('completed_by_name', 160)->nullable();
            $table->text('work_performed')->nullable();

            /* ---- second signature: somebody qualified has checked it ---- */
            $table->timestamp('certified_at')->nullable();
            $table->foreignId('certified_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('certified_by_name', 160)->nullable();

            // Certificate content, copied at the moment of the act (E7).
            $table->string('qualification_type', 64)->nullable();
            $table->string('qualification_reference', 128)->nullable();
            $table->string('qualification_category', 64)->nullable();

            $table->text('cancellation_reason')->nullable();

            /*
             * The fleet limit this card discharges, if any.
             *
             * the rule for how the two modules meet: "eine anstehende
             * aufgabe bekommt eine arbeitskarte, wenn diese abgezeichnet ist,
             * ist auch die aufgabe erledigt." Loose reference again -- the fleet
             * owns component_limits.
             */
            $table->unsignedBigInteger('component_limit_id')->nullable()->index();

            $table->timestamps();
            $table->softDeletes();
        });

        /*
         * Hours, per person per card.
         *
         * A join table rather than a column, because a card commonly has several
         * people on it and the logbook needs to know who did what for how long.
         * A single "duration" would make 66.A.20(b) unanswerable and the
         * experience log something to keep by hand after all.
         */
        Schema::create('task_card_times', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('task_card_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            // Copied, so the logbook survives pseudonymisation (E3a).
            $table->string('person_name', 160);

            // executed | assisted | supervised
            $table->string('participation', 16)->default('executed')->index();

            /** Minutes, not hours: everybody writes "1:45" and nobody writes 1.75. */
            $table->unsignedInteger('minutes');

            $table->date('worked_on')->index();
            $table->text('note')->nullable();

            $table->timestamps();
        });

        /*
         * Findings.
         *
         * Their own entity because that is what they are: you take out a screw
         * and see a crack. It is not part of the card you were doing, and it
         * does not go away when that card is finished.
         */
        Schema::create('findings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('aircraft_id')->constrained()->cascadeOnDelete();

            /** Where it was noticed. Null for one reported outside any job. */
            $table->foreignId('task_card_id')->nullable()->constrained()->nullOnDelete();

            $table->string('number', 32)->unique();
            $table->string('title', 160);
            $table->text('description');

            // open | scheduled | deferred | resolved | dismissed
            $table->string('state', 16)->default('open')->index();

            /*
             * Whether it stops the aircraft flying.
             *
             * Entered rather than derived: only a person can say whether a crack
             * is cosmetic, and a system that guessed would guess in one
             * direction or the other -- both wrong.
             */
            $table->boolean('is_blocking')->default(true)->index();

            $table->foreignId('found_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('found_by_name', 160)->nullable();
            $table->date('found_on')->index();

            /*
             * Deferring is a decision somebody answers for, so it is frozen with
             * the credential it was made under -- the same treatment as every
             * other determination in this system.
             */
            $table->date('deferred_until')->nullable();
            $table->text('deferral_reason')->nullable();
            $table->foreignId('deferred_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('deferred_by_name', 160)->nullable();
            $table->string('deferral_qualification_type', 64)->nullable();
            $table->string('deferral_qualification_reference', 128)->nullable();

            /** The card raised to deal with it. */
            $table->unsignedBigInteger('resolving_task_card_id')->nullable()->index();

            $table->date('resolved_on')->nullable();
            $table->text('resolution')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['aircraft_id', 'state']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('findings');
        Schema::dropIfExists('task_card_times');
        Schema::dropIfExists('task_cards');
        Schema::dropIfExists('work_orders');
    }
};
