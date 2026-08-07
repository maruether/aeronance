<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The release to service.
 *
 * The third and last signature. "Fertig gemeldet" says the work is finished,
 * "abgezeichnet" says it was done properly, and this says the aircraft may fly.
 * Three statements, three moments, and the last one is the only one an operator
 * acts on.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * IT LIVES IN THIS MODULE AND NOT ITS OWN, and the reasoning is in CLAUDE.md
 * since the brief allows it: a CRS is not optional under ML.A.801, so a module
 * for it could never be switched off; the immutability rule speaks of Vorgänge
 * and therefore of these tables; and a fourth copy of "somebody qualified
 * answers for this" is the copy that goes wrong -- as two of the existing three
 * did before the pilot-owner limit was corrected.
 *
 * ITS OWN TABLE RATHER THAN COLUMNS ON work_orders, because of the other half of
 * that leitplanke: "Korrekturen nur als neue, referenzierende Einträge — nie
 * durch Editieren des Originals." A correction is therefore a second release
 * pointing at the first, and both stay readable. Columns would have made that
 * impossible to express.
 * ─────────────────────────────────────────────────────────────────────────────
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('releases', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('work_order_id')->constrained()->cascadeOnDelete();

            /*
             * Aircraft as a real key AND registration copied.
             *
             * The key because this module requires the fleet; the copy because a
             * release is a certificate, and a certificate that starts reading
             * differently once the aircraft is re-registered is not one.
             */
            $table->foreignId('aircraft_id')->constrained()->cascadeOnDelete();
            $table->string('aircraft_registration', 32)->index();
            $table->string('aircraft_model', 96)->nullable();

            $table->string('number', 32)->unique();

            /*
             * The statement itself, in words.
             *
             * ML.A.801(b) / 145.A.50 want the certificate to say what was done
             * and against which data. Stored as text rather than assembled at
             * display time: a signature belongs to the words that were above it,
             * not to whatever a later template renders.
             */
            $table->text('statement');

            /** The maintenance data the work was carried out to. */
            $table->string('maintenance_data', 255)->nullable();

            $table->timestamp('released_at');
            $table->foreignId('released_by')->nullable()->constrained('users')->nullOnDelete();

            // Certificate content, copied at the moment of the act (E7/E3a).
            $table->string('released_by_name', 160);
            $table->string('qualification_type', 64);
            $table->string('qualification_reference', 128)->nullable();
            $table->string('qualification_category', 64)->nullable();
            $table->date('qualification_valid_until')->nullable();

            /*
             * The aircraft's counters at release.
             *
             * What the certificate is about: this aircraft, at this many hours.
             * A release that cannot say when it applied is one nobody can place
             * on a timeline afterwards.
             */
            $table->json('counters_at_release')->nullable();

            /*
             * A correction is a new release that references the old one.
             *
             * Never an edit -- see the class comment. The superseded one keeps
             * its text and its signature, which is the whole point of saying so
             * this way.
             */
            $table->foreignId('supersedes_release_id')->nullable()
                ->constrained('releases')->nullOnDelete();
            $table->text('correction_reason')->nullable();

            $table->timestamps();

            $table->index(['aircraft_id', 'released_at']);
        });

        Schema::table('work_orders', function (Blueprint $table): void {
            /*
             * Denormalised deliberately, and it is the only place in this
             * project where I do that.
             *
             * Every write to a card, a time entry or the visit itself has to ask
             * "is this frozen?", and doing that through a relation on the
             * releases table would mean a query on every single save -- including
             * inside the loops that create a visit's cards. The flag is written
             * in the same transaction as the release, so it cannot drift.
             */
            $table->timestamp('released_at')->nullable()->after('closed_at')->index();
        });
    }

    public function down(): void
    {
        Schema::table('work_orders', function (Blueprint $table): void {
            $table->dropColumn('released_at');
        });

        Schema::dropIfExists('releases');
    }
};
