<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The manufacturer decides how long their subject line is.
 *
 * Found by the first real adapter, not by imagination: Schleicher's TM 4 b for
 * the ASK 21 carries a 400-character subject ("A) Sofern noch keine Halterung
 * für Trimmballast … B) Sofern die TM 4 bereits durchgeführt ist …"), and a
 * varchar(255) rejected the whole import.
 *
 * Truncating would have been the wrong fix. A directive's subject is the
 * manufacturer's words, and shortening them in the database means the record no
 * longer says what the document says. Displays truncate; storage does not.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('directives', function (Blueprint $table): void {
            $table->text('title')->change();
        });
    }

    public function down(): void
    {
        Schema::table('directives', function (Blueprint $table): void {
            $table->string('title', 255)->change();
        });
    }
};
