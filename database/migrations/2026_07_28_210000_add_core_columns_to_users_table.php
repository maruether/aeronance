<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Accounts are deactivated, never deleted outright.
 *
 * A member who leaves keeps their trace in the records: their name may appear
 * in a release, and that entry has to stay readable years later. Deleting the
 * account would either break those records or drag their content along -- so
 * accounts are switched off, and the personal data in the activity log is
 * pseudonymised separately. Certificate content is exempt from that. See E3a.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->boolean('is_active')->default(true)->after('email');
            $table->timestamp('deactivated_at')->nullable()->after('is_active');
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropSoftDeletes();
            $table->dropColumn(['is_active', 'deactivated_at']);
        });
    }
};
