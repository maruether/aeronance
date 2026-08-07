<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which modules are active in this installation.
 *
 * One row per module ever switched on. Switching off nulls enabled_at rather
 * than deleting the row, so the history of what was once active survives -- and
 * because deactivation is explicitly not an uninstall.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('modules', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 64)->unique();
            $table->string('version', 32);
            $table->timestamp('enabled_at')->nullable()->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('modules');
    }
};
