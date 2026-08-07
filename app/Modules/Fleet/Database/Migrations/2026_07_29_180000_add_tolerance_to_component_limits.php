<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Permitted overrun, and the anchor it needs.
 *
 * Two things were wrong in the first version, and the second is the serious one.
 *
 * 1. THERE WAS NO TOLERANCE AT ALL. A limit was either met or overdue, so an
 *    inspection done four days past a twelve-month interval read the same as one
 *    forgotten for a year. AMP tasks generally carry one (commonly 10 % or one
 *    month, whichever is less); airworthiness directives generally do not, and
 *    an ARC never does. So it is entered, with defaults rather than rules.
 *
 * 2. THE DUE DATE WAS ANCHORED TO THE INSTALLATION. Which is right exactly once.
 *    A recurring limit has to be anchored to the last time the work was actually
 *    done -- and the rule for what "actually done" means is asymmetric, in
 *    a way that matters:
 *
 *      DONE LATE, within tolerance -> the OLD due date is the anchor.
 *      DONE EARLY                  -> the ACTUAL date is the anchor.
 *
 *    Both lean the same way, which is the tell that they are one rule and not
 *    two. Anchoring a late job to the day it happened would push every future
 *    interval out by the overrun -- ten percent a year, and after a decade a
 *    whole interval has quietly gone missing. Anchoring an early job to the old
 *    due date would hand back the time that was given up by doing it early.
 *    Neither is a rounding question; both accumulate.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('component_limits', function (Blueprint $table): void {
            /*
             * Tolerance, in either form or both. Where both are given the
             * smaller wins, which is how "10 % oder 1 Monat" is meant: ten per
             * cent of a hundred hours is ten hours, ten per cent of twelve
             * months is more than a month, and the month is the answer.
             */
            $table->decimal('tolerance_percent', 5, 2)->nullable()->after('value');

            /** In the limit's own unit -- months for calendar, hours or counts. */
            $table->decimal('tolerance_absolute', 12, 2)->nullable()->after('tolerance_percent');

            /*
             * When the work was last done, and what the counter read then.
             *
             * Null means "never since it was fitted", so the installation is the
             * anchor -- which is the honest starting position rather than a
             * special case.
             */
            $table->date('last_done_at')->nullable()->after('due_on');
            $table->decimal('last_done_value', 12, 2)->nullable()->after('last_done_at');

            /** What the due date WAS when it was last done, so the asymmetry has
             *  something to compare against afterwards. */
            $table->date('last_due_at')->nullable()->after('last_done_value');
            $table->decimal('last_due_value', 12, 2)->nullable()->after('last_due_at');
        });
    }

    public function down(): void
    {
        Schema::table('component_limits', function (Blueprint $table): void {
            $table->dropColumn([
                'tolerance_percent',
                'tolerance_absolute',
                'last_done_at',
                'last_done_value',
                'last_due_at',
                'last_due_value',
            ]);
        });
    }
};
