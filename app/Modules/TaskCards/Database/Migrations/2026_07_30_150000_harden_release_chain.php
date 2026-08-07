<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What the adversarial review of the release chain found, at the schema level.
 *
 * Three of the holes were racier or deeper than model guards can reach, so the
 * database itself now refuses them:
 *
 *  - `releases` cascaded on work_order_id and aircraft_id. A DB-level cascade
 *    fires no Eloquent event, so a force-deleted visit would have taken its
 *    SIGNED CERTIFICATES with it -- silently, past every guard in the model.
 *    Now RESTRICT: the database refuses to hard-delete anything that holds a
 *    certificate. "Nichts hart löschen" was always the rule; now it has teeth
 *    where the guards cannot reach.
 *  - A release could be corrected twice concurrently (no constraint on
 *    supersedes_release_id), leaving two "current" corrections. Unique now --
 *    MariaDB allows many NULLs in a unique index, so uncorrected releases are
 *    unaffected.
 *  - Card numbers had no unique index, and the count-based generator could
 *    hand out the same number twice under concurrency. The generator is fixed
 *    too, but the index is what turns a silent duplicate identity into a loud
 *    refusal.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('releases', function (Blueprint $table): void {
            $table->dropForeign(['work_order_id']);
            $table->dropForeign(['aircraft_id']);

            $table->foreign('work_order_id')->references('id')->on('work_orders')->restrictOnDelete();
            $table->foreign('aircraft_id')->references('id')->on('aircraft')->restrictOnDelete();

            $table->unique('supersedes_release_id');
        });

        Schema::table('task_cards', function (Blueprint $table): void {
            $table->unique('number');
        });
    }

    public function down(): void
    {
        Schema::table('task_cards', function (Blueprint $table): void {
            $table->dropUnique(['number']);
        });

        Schema::table('releases', function (Blueprint $table): void {
            $table->dropUnique(['supersedes_release_id']);
            $table->dropForeign(['work_order_id']);
            $table->dropForeign(['aircraft_id']);

            $table->foreign('work_order_id')->references('id')->on('work_orders')->cascadeOnDelete();
            $table->foreign('aircraft_id')->references('id')->on('aircraft')->cascadeOnDelete();
        });
    }
};
