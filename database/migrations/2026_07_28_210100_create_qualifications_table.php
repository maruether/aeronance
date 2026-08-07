<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * External credentials held by a person -- see decision E8.
 *
 * The scope column carries a plain identifier (an aircraft registration, for a
 * pilot-owner authorisation) and deliberately has NO foreign key: aircraft live
 * in the fleet module, which need not be installed. A constraint here would
 * break the guarantee that the core runs on its own. See D4.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('qualifications', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            // part66_licence, pilot_owner_authorisation, ...
            $table->string('type', 64)->index();

            // Licence or authorisation number -- becomes certificate content.
            $table->string('reference', 128)->nullable();

            // Part-66 category (B1.2, B2, ...) or the equivalent.
            $table->string('category', 64)->nullable();

            // Empty means "applies generally"; otherwise the aircraft it is
            // limited to. No FK on purpose, see above.
            $table->string('scope', 64)->nullable()->index();

            $table->date('valid_from');
            $table->date('valid_until')->nullable();
            $table->text('note')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['user_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('qualifications');
    }
};
