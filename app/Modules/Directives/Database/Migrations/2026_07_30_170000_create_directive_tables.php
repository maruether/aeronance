<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * LTA/TM -- airworthiness directives and service bulletins.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * TWO TABLES, AND THE SPLIT IS THE WHOLE DESIGN.
 *
 * `directives` is the manufacturer's or authority's line: it exists once, no
 * matter how many aircraft it touches, and the constraint governs it --
 * "die Übersichtsliste ändert sich herstellerseitig nicht oder wird länger."
 * A directive is never edited away and never deleted; a newer one supersedes it.
 *
 * `directive_applications` is what THIS operation says about that line for ONE
 * aircraft. That is the record an inspector reads, and it is per aircraft
 * because the same LTA is complied with on D-KABC and not applicable to D-KXYZ.
 *
 * Merging the two would have been the obvious mistake: a directive would then
 * carry one aircraft's answer, and importing a manufacturer list would overwrite
 * assessments somebody already made.
 * ─────────────────────────────────────────────────────────────────────────────
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('directives', function (Blueprint $table): void {
            $table->id();

            /*
             * Where the line came from: 'manual', 'csv', or the name of a
             * manufacturer adapter. Kept because an imported line and a typed one
             * are trusted differently -- and because re-importing has to find
             * what it wrote last time without touching hand-entered rows.
             */
            $table->string('source', 64)->default('manual')->index();
            $table->string('external_reference', 128)->nullable();

            $table->string('number', 64);
            $table->string('title', 255);
            $table->text('summary')->nullable();

            // lta | tm | sb | ad -- who issued it and with what force. An LTA is
            // mandatory, a TM/SB usually is not until an authority adopts it.
            $table->string('kind', 16)->index();
            $table->string('issuer', 160)->nullable();

            $table->date('issued_at')->nullable();

            /*
             * The deadline the directive itself sets. Distinct from a recurrence:
             * "comply before 2027-03-01" is a one-off date, and missing it is not
             * the same as an interval coming round.
             */
            $table->date('comply_before')->nullable()->index();

            /*
             * What the directive is about -- all three of the cases.
             *
             * aircraft_model: applies to a type, so every registration of it
             * component:      applies to a part, so every aircraft carrying one
             * engine, propeller: separate lists and numbering at the
             *                 manufacturer, technically the component case
             */
            $table->string('subject_kind', 24)->index();
            $table->string('subject_model', 96)->nullable()->index();
            $table->string('subject_designation', 160)->nullable();
            $table->string('subject_part_number', 96)->nullable();

            // The S/N range, as text: manufacturers write "0123", "A-45" and
            // "1000-and-up". Comparing those numerically is a trap.
            $table->string('serial_from', 64)->nullable();
            $table->string('serial_to', 64)->nullable();

            /*
             * Recurring directives.
             *
             * Vorgabe: "abgehakte punkte so lange abgehakt bis ihre laufzeit
             * kickt." So a complied recurring directive is closed until its
             * interval comes round, and then it is open again -- the same
             * asymmetry the fleet's component limits already model.
             */
            $table->boolean('is_recurring')->default(false)->index();
            $table->unsignedInteger('interval_months')->nullable();
            $table->string('interval_counter', 32)->nullable();
            $table->decimal('interval_value', 12, 2)->nullable();

            /*
             * A newer directive replaces an older one. Recorded rather than
             * deleting the old line: the record has to show that the old one was
             * dealt with and by what.
             */
            $table->foreignId('superseded_by_id')->nullable()
                ->constrained('directives')->nullOnDelete();

            $table->text('reference_url')->nullable();

            $table->timestamps();
            $table->softDeletes();

            // The same number can exist from two sources (a manufacturer TM the
            // authority later adopts as an LTA), so uniqueness is per source.
            $table->unique(['source', 'number']);
        });

        Schema::create('directive_applications', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('directive_id')->constrained()->cascadeOnDelete();
            $table->foreignId('aircraft_id')->constrained()->cascadeOnDelete();

            // Copied: an assessment is a record, and it must stay readable when
            // an aircraft is re-registered.
            $table->string('aircraft_registration', 32)->index();

            /*
             * open | complied | not_applicable | not_carried_out
             *
             * The fourth is the, and it is the one that matters: "es gibt
             * aber nicht nur ja/nein sondern auch nicht zutreffend (mit
             * begründung) und nicht durchgeführt."
             *
             * "Not carried out" is not the absence of an answer -- it IS an
             * answer: this applies to us, we know, and it has not been done. That
             * is an airworthiness statement, so it blocks, while `open` merely
             * means nobody has looked yet.
             */
            $table->string('state', 24)->default('open')->index();

            $table->date('assessed_at')->nullable();
            $table->foreignId('assessed_by')->nullable()->constrained('users')->nullOnDelete();

            // Certificate content, frozen (E7).
            $table->string('assessed_by_name', 160)->nullable();
            $table->string('qualification_type', 64)->nullable();
            $table->string('qualification_reference', 128)->nullable();

            /*
             * Why. Mandatory for not_applicable and not_carried_out -- a reason
             * is the entire value of those two states. An inspector wants to see
             * that somebody looked, not that a line is missing.
             */
            $table->text('reason')->nullable();

            // How it was complied with, and the counters at that moment.
            $table->text('method')->nullable();
            $table->json('counters_at_compliance')->nullable();

            /*
             * The task card that did it -- a loose string, no FK. Cross-module
             * reference (D4): this module works without task cards.
             */
            $table->string('task_card_reference', 64)->nullable()->index();

            /*
             * When a recurring directive comes round again. Derived at
             * compliance and stored, because the interval anchors on the date it
             * was actually done.
             */
            $table->date('next_due_at')->nullable()->index();
            $table->decimal('next_due_value', 12, 2)->nullable();

            $table->timestamps();

            // One assessment per directive per aircraft. A repeat is a new
            // compliance on the same row, not a second row.
            $table->unique(['directive_id', 'aircraft_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('directive_applications');
        Schema::dropIfExists('directives');
    }
};
