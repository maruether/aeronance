<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Every use of the emergency access, on its own table -- decision E2.
 *
 * Separate from the activity log for two reasons. It outlives it (five years
 * against three), because a privileged access is the thing one most wants to be
 * able to reconstruct. And it must be writable when the rest of the system is
 * not working, which is the situation break-glass exists for.
 *
 * The origin address may be empty: an artisan command has no HTTP request, so
 * there is only an SSH origin to record, and someone sitting at the server
 * console leaves none. A missing environment detail must never block the
 * emergency access -- that would defeat its purpose at the worst moment.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('break_glass_records', function (Blueprint $table): void {
            $table->id();

            // Who was granted access. No foreign key: the record has to survive
            // the account being removed.
            $table->string('target_email', 255);
            $table->unsignedBigInteger('target_user_id')->nullable();

            // Who triggered it, as seen on the server.
            $table->string('shell_user', 128)->nullable();
            $table->string('origin_ip', 45)->nullable();
            $table->string('hostname', 255)->nullable();

            $table->text('reason');
            $table->timestamp('granted_at');
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('revoked_at')->nullable();

            $table->timestamps();

            $table->index('granted_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('break_glass_records');
    }
};
