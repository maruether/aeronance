<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The fleet: aircraft, who holds them, and what gets counted.
 *
 * The module boundary the analysis settled: the warehouse keeps calendar expiry
 * of things on a shelf, the fleet keeps operating time of things in the air.
 *
 * Two decisions from the brief shape these tables:
 *
 *  - FLIGHT TIME AND LANDINGS ARE REQUIRED BY LAW, so every aircraft carries
 *    them and no setting can switch them off. Engine time is separate and
 *    optional, because -- the detail that would have been easy to get wrong --
 *    not every aircraft with an engine has an engine counter.
 *
 *  - PILOT-OWNER AUTHORITY COMES FROM THE MAINTENANCE PROGRAMME, not from
 *    ownership. "Ich darf auch an Privatflugzeugen nach Pilot-Owner freigeben,
 *    solange ich im AMP aufgeführt bin." So the AMP names people, and that
 *    naming is what the aircraft-scoped check in Authority has been waiting for.
 */
return new class extends Migration
{
    public function up(): void
    {
        /*
         * Who holds the aircraft.
         *
         * A club fleet is not all the club's. Part-ML pins the continuing
         * airworthiness duty on the holder, so a privately held aircraft in the
         * club's care answers to its owner and not to the committee -- which is
         * exactly why this is an entity and not a name field.
         */
        Schema::create('holders', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 160);

            // club | private
            $table->string('type', 16)->default('private')->index();

            // The member, where the holder is one. Kept nullable: a holder may
            // be somebody with no account, and an account may be deleted long
            // after the aircraft has moved on.
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();

            $table->string('contact', 255)->nullable();
            $table->text('note')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('aircraft', function (Blueprint $table): void {
            $table->id();

            /*
             * The registration. Format is instance configuration, never
             * hardcoded -- D-KABC, HB-, OE-, F- all exist, and a club abroad is
             * not a special case in the code.
             */
            $table->string('registration', 32)->unique();

            $table->string('model', 96);
            $table->string('manufacturer', 96)->nullable();
            $table->string('serial_number', 96)->nullable();
            $table->unsignedSmallInteger('year_built')->nullable();

            $table->foreignId('holder_id')->nullable()->constrained()->nullOnDelete();

            /*
             * Which optional counters this aircraft keeps. Flight hours and
             * landings are not in here because they are not optional.
             *
             * A JSON column and not a join table, deliberately: it is a short
             * list of enum values read together with the aircraft and never
             * queried across. The guardrail against heavy JSON path queries
             * stands -- nothing here filters on it in SQL.
             */
            $table->json('optional_counters')->nullable();

            $table->boolean('is_active')->default(true)->index();
            $table->date('in_service_since')->nullable();
            $table->text('note')->nullable();

            $table->timestamps();
            $table->softDeletes();
        });

        /*
         * Counter readings, append-only.
         *
         * The same reasoning as the stock ledger (E1): there is no current-hours
         * field to overwrite, so a reading can never quietly replace the record
         * of what the aircraft had done a year ago. A wrong entry is corrected
         * by a further reading that references it, and both stay visible.
         *
         * These are ABSOLUTE readings, not increments -- because that is what
         * somebody reads off the instrument and writes on the sheet. Asking for
         * a difference would be asking them to do arithmetic before typing.
         */
        Schema::create('counter_readings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('aircraft_id')->constrained()->cascadeOnDelete();

            $table->string('kind', 24)->index();
            $table->decimal('value', 12, 2);
            $table->date('read_at')->index();

            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('corrects_reading_id')->nullable()->constrained('counter_readings')->nullOnDelete();
            $table->text('note')->nullable();

            $table->timestamp('created_at')->nullable();

            $table->index(['aircraft_id', 'kind', 'read_at']);
        });

        /*
         * The maintenance programme, and who may work to it.
         *
         * Two things hang here. The AMP itself is the reference every inspection
         * interval is derived from (ML.A.302). And the list of people cleared
         * for pilot-owner maintenance on THIS aircraft is what turns the
         * aircraft-scoped qualification check from an idea into an answer.
         */
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

        Schema::create('pilot_owner_authorisations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('aircraft_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            // Copied at the moment of listing -- the name in a certificate is
            // its content, not a lookup (E7/E3a).
            $table->string('listed_name', 160);

            $table->date('listed_at');
            $table->date('valid_until')->nullable();
            $table->text('note')->nullable();

            $table->timestamps();

            $table->unique(['aircraft_id', 'user_id']);
        });

        Schema::create('airworthiness_reviews', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('aircraft_id')->constrained()->cascadeOnDelete();

            $table->string('certificate_reference', 128)->nullable();
            $table->date('issued_at');
            $table->date('valid_until')->index();

            // Certificate content, copied and frozen.
            $table->string('issued_by_name', 160)->nullable();
            $table->string('issued_by_approval', 64)->nullable();

            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->text('note')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('airworthiness_reviews');
        Schema::dropIfExists('pilot_owner_authorisations');
        Schema::dropIfExists('maintenance_programmes');
        Schema::dropIfExists('counter_readings');
        Schema::dropIfExists('aircraft');
        Schema::dropIfExists('holders');
    }
};
