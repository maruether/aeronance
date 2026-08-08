<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "Nothing is reversed twice", at the schema level.
 *
 * ReverseMovement promises it, and until now enforced it only by looking before
 * writing -- two parallel corrections of the same booking both looked, both saw
 * nothing, and both wrote. The stock then moved twice for one mistake, and the
 * reference chain stopped meaning anything.
 *
 * The release chain solved the identical race with a unique index on
 * supersedes_release_id and called it the backstop; this is the warehouse twin.
 * MariaDB allows many NULLs in a unique index, so the movements that were never
 * corrected -- almost all of them -- are unaffected.
 *
 * The action now also locks and re-checks inside its transaction, which turns
 * the second attempt into a readable refusal. The index is for the path nobody
 * thought of.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stock_movements', function (Blueprint $table): void {
            $table->unique('reverses_movement_id');
        });
    }

    public function down(): void
    {
        Schema::table('stock_movements', function (Blueprint $table): void {
            $table->dropUnique(['reverses_movement_id']);
        });
    }
};
