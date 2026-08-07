<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * How binding a line is, separately from what kind of document it is.
 *
 * the correction: "nur optional darf den status nicht durchgeführt
 * erhalten." The first version derived bindingness from the kind, which meant a
 * manufacturer's TM adopted by an authority could never be marked mandatory --
 * the same document, the same number, a different legal force.
 *
 * Existing rows are backfilled from the kind, which is the right default: an
 * LTA/AD is mandatory, a TM/SB is not until somebody says otherwise.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('directives', function (Blueprint $table): void {
            $table->string('bindingness', 16)->default('mandatory')->after('kind')->index();
        });

        DB::table('directives')
            ->whereIn('kind', ['tm', 'sb'])
            ->update(['bindingness' => 'optional']);
    }

    public function down(): void
    {
        Schema::table('directives', function (Blueprint $table): void {
            $table->dropColumn('bindingness');
        });
    }
};
