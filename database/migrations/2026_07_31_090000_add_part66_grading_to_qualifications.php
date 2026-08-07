<?php

declare(strict_types=1);

use App\Core\Enums\Part66Category;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Part-66 qualifications, graded along the three axes a licence actually has.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * 1. CATEGORY -- point 66.A.3 of Annex III (Part-66) to Regulation (EU)
 *    No 1321/2014. A closed list, and a LIST: the licence document is a column
 *    of ticked boxes. Until now this was one free-text field, which could hold
 *    "L1" but not "L1 und L2".
 *
 * 2. LIMITATIONS -- points 66.A.45/66.A.50, and 66.A.70 for licences converted
 *    from a national qualification (the BWLV case). Vorgabe: "Die Zellentypen
 *    können eingeschränkt werden und zählen über die gesamte Lizenz. Wenn ich
 *    beantrage bekomme ich z.B. die Einschränkung 'ausgenommen Zellen in
 *    Metallbauweise', da ist egal ob das L1 oder L2 ist."
 *
 *    So a limitation hangs off the LICENCE and not off a category -- which is
 *    why this is a table of its own hanging off the qualification, and not a
 *    column beside the category. Point 66.A.50 states the same thing from the
 *    regulator's side: limitations are EXCLUSIONS from the certifying
 *    privileges. They are therefore stored as exclusions. Turning them into a
 *    positive list ("darf Holz und FVK") would be an inference, and it becomes
 *    wrong the day a new construction method appears.
 *
 * 3. THE MA.803(b) CAP -- the licence entry "no maintenance exceeding
 *    MA.803(b)". Vorgabe: "die Leute dürfen damit nicht mehr Sachen freigeben als
 *    ein P/O, aber für Fremdarbeiten." M.A.803(b) is pilot-owner maintenance:
 *    the limited task list in Appendix VIII to Part-M (Part C of it covers
 *    sailplanes and powered sailplanes). The entry caps WHAT may be released,
 *    not WHOSE work -- which makes it a third step between a full licence and a
 *    pilot-owner authorisation, and a boolean rather than another limitation
 *    row, because it is the one entry that changes the authorisation outcome.
 * ─────────────────────────────────────────────────────────────────────────────
 *
 * NOTHING EXISTING IS DROPPED. The old free-text `category` column stays: it is
 * what was actually typed, it is the fallback for anything the enum does not
 * know (a B2L system rating, a foreign licence), and existing rows keep it. The
 * new list is filled from it where it can be read with confidence.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('qualifications', function (Blueprint $table): void {
            /*
             * The ticked boxes. A JSON column and not a join table, for the same
             * reason the fleet keeps optional_counters that way: a short list of
             * enum values that is always read together with its row and never
             * queried across. The guardrail is against heavy JSON path queries,
             * and there are none here -- the check iterates a person's handful
             * of qualifications in PHP.
             */
            $table->json('categories')->nullable()->after('category');

            /*
             * "no maintenance exceeding MA.803(b)".
             *
             * Indexed because the question "who may still sign this off" gets
             * asked of a list of people, and defaulting to false because the
             * absence of the entry is the normal licence.
             */
            $table->boolean('no_maintenance_exceeding_ma803b')
                ->default(false)
                ->after('categories');
        });

        /*
         * The exclusions on the licence.
         *
         * Soft-deleted like everything else, and here it earns its keep beyond
         * the house rule: point 66.A.50 provides for limitations to be REMOVED
         * once the holder demonstrates the missing experience. Which limitation
         * was lifted, and when, is exactly the kind of thing an audit asks about
         * -- a hard delete would leave certificates issued under a limitation
         * that no longer appears anywhere.
         */
        Schema::create('qualification_limitations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('qualification_id')->constrained()->cascadeOnDelete();

            /*
             * The machine-checkable part, from MaintenanceSubject: metal, wood,
             * composite, piston, turbine, electric, avionics. Nullable, because
             * a licence may carry an exclusion this system cannot reason about
             * ("ausgenommen Arbeiten an Rettungsgeräten") -- that one is text
             * only, and it is recorded rather than lost.
             */
            $table->string('subject', 32)->nullable()->index();

            // What the licence actually says. Free text on purpose: the
            // regulation fixes neither the wording nor the set of limitations.
            $table->string('text', 255)->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index('qualification_id');
        });

        $this->fillCategoriesFromFreeText();
    }

    public function down(): void
    {
        Schema::dropIfExists('qualification_limitations');

        Schema::table('qualifications', function (Blueprint $table): void {
            $table->dropColumn(['categories', 'no_maintenance_exceeding_ma803b']);
        });
    }

    /**
     * Read the old free-text category into the new list where it is unambiguous.
     *
     * Conservative on purpose: only tokens that ARE a category are taken, and
     * the original text is left untouched either way. A row that cannot be read
     * loses nothing -- it keeps its text and somebody ticks the boxes when they
     * next open it. Guessing here would put a category on a licence that never
     * had it, and that is a worse outcome than an empty list.
     */
    private function fillCategoriesFromFreeText(): void
    {
        $rows = DB::table('qualifications')
            ->whereNotNull('category')
            ->where('category', '<>', '')
            ->get(['id', 'category']);

        foreach ($rows as $row) {
            $tokens = preg_split('/[\s,;\/|]+/', (string) $row->category) ?: [];

            $categories = [];

            foreach ($tokens as $token) {
                $category = Part66Category::tryFrom(mb_strtoupper(trim($token)));

                if ($category !== null) {
                    $categories[] = $category->value;
                }
            }

            if ($categories === []) {
                continue;
            }

            DB::table('qualifications')
                ->where('id', $row->id)
                ->update(['categories' => json_encode(array_values(array_unique($categories)))]);
        }
    }
};
