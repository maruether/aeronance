<?php

declare(strict_types=1);

namespace Tests\Feature\Directives;

use App\Core\Models\Qualification;
use App\Models\User;
use App\Modules\Directives\Actions\AssessDirective;
use App\Modules\Directives\Airworthiness\OutstandingDirectives;
use App\Modules\Directives\Enums\Bindingness;
use App\Modules\Directives\Enums\DirectiveKind;
use App\Modules\Directives\Enums\SubjectKind;
use App\Modules\Directives\Models\Directive;
use App\Modules\Directives\Permissions;
use App\Modules\Fleet\Airworthiness\AirworthinessCheck;
use App\Modules\Fleet\Airworthiness\OpenItem;
use App\Modules\Fleet\Models\Aircraft;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * What the directive list contributes to "hier ist noch was offen".
 *
 * Through the fleet's extension point, never by reaching into it -- the second
 * user of that interface after the task cards' findings, which is the first real
 * evidence it was the right seam.
 */
final class AirworthinessContributionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Permission::findOrCreate(Permissions::DIRECTIVES_ASSESS, 'web');
        app(AirworthinessCheck::class)->register(OutstandingDirectives::class);
    }

    #[Test]
    public function a_directive_nobody_has_looked_at_shows_up_by_itself(): void
    {
        // The one that would otherwise be invisible: importing a list creates no
        // assessment rows, so a new LTA lands in the database and nothing on the
        // aircraft's page mentions it.
        $aircraft = $this->aircraft();
        $this->directive();

        $items = $this->directiveItems($aircraft);

        $this->assertCount(1, $items);
        $this->assertSame('directives', $items[0]->source);
        $this->assertStringContainsString('noch keine Beurteilung', $items[0]->detail);
        $this->assertTrue($items[0]->blocking);
    }

    #[Test]
    public function complying_clears_it(): void
    {
        $aircraft = $this->aircraft();
        app(AssessDirective::class)->comply($this->directive(), $aircraft, $this->inspector(), 'Gemacht');

        $this->assertSame([], $this->directiveItems($aircraft->fresh()));
    }

    #[Test]
    public function not_applicable_clears_it_too(): void
    {
        // It was assessed. That is the point of the state.
        $aircraft = $this->aircraft();
        app(AssessDirective::class)->markNotApplicable(
            $this->directive(), $aircraft, $this->inspector(), 'Ausrüstung nicht verbaut',
        );

        $this->assertSame([], $this->directiveItems($aircraft->fresh()));
    }

    #[Test]
    public function an_undone_optional_line_stays_listed_with_its_reason(): void
    {
        // The reason travels into the open item, because "not done" without a why
        // is the state the module exists to avoid. Only optional lines can reach
        // this state at all -- a mandatory one cannot be declined.
        $aircraft = $this->aircraft();
        app(AssessDirective::class)->markNotCarriedOut(
            $this->directive(kind: DirectiveKind::Tm, number: 'TM-2026-77'),
            $aircraft, $this->inspector(), 'Ersatzteil nicht lieferbar',
        );

        $items = $this->directiveItems($aircraft->fresh());

        $this->assertCount(1, $items);
        $this->assertStringContainsString('Ersatzteil nicht lieferbar', $items[0]->detail);
        $this->assertFalse($items[0]->blocking, 'A recommendation declined does not ground it.');
        $this->assertFalse($items[0]->blocksRelease);
    }

    #[Test]
    public function an_unassessed_line_blocks_the_release_whatever_its_bindingness(): void
    {
        // "nicht beurteilt ist ne red flag und verhindert die freigabe."
        $aircraft = $this->aircraft();
        $this->directive(kind: DirectiveKind::Tm, number: 'TM-2026-77');

        $items = $this->directiveItems($aircraft);

        $this->assertCount(1, $items);
        $this->assertTrue($items[0]->blocking);
        $this->assertTrue($items[0]->blocksRelease, 'Even an optional line, while unread.');
    }

    #[Test]
    public function a_recurring_line_comes_back_when_its_interval_kicks(): void
    {
        // the rule, seen from the aircraft's page.
        $aircraft = $this->aircraft();
        $directive = $this->directive(recurringMonths: 12);

        app(AssessDirective::class)->comply(
            $directive, $aircraft, $this->inspector(), 'Geprüft',
            on: now()->subMonths(13)->toDateString(),
        );

        $items = $this->directiveItems($aircraft->fresh());

        $this->assertCount(1, $items);
        $this->assertStringContainsString('wieder fällig', $items[0]->detail);
    }

    #[Test]
    public function a_superseded_directive_is_not_reported(): void
    {
        $aircraft = $this->aircraft();
        $old = $this->directive();
        $new = $this->directive(number: 'LTA-2026-006');
        $old->update(['superseded_by_id' => $new->id]);

        $items = $this->directiveItems($aircraft);

        // Only the current one is outstanding.
        $this->assertCount(1, $items);
        $this->assertStringContainsString('LTA-2026-006', $items[0]->what);
    }

    #[Test]
    public function a_directive_for_another_type_is_not_reported(): void
    {
        $ask21 = $this->aircraft();
        Directive::create([
            'source' => 'manual',
            'number' => 'LTA-DG-1',
            'title' => 'Nur DG 300',
            'kind' => DirectiveKind::Lta,
            'subject_kind' => SubjectKind::AircraftModel,
            'subject_model' => 'DG 300',
        ]);

        $this->assertSame([], $this->directiveItems($ask21));
    }

    /**
     * Only what THIS module contributed.
     *
     * The check collects from every module, so an aircraft without an ARC on file
     * always carries the fleet's own item too. Filtering keeps these tests about
     * the directive contribution instead of about the fleet's unrelated state.
     *
     * @return list<OpenItem>
     */
    private function directiveItems(Aircraft $aircraft): array
    {
        return array_values(array_filter(
            app(AirworthinessCheck::class)->openItemsFor($aircraft),
            fn ($item): bool => $item->source === 'directives',
        ));
    }

    private function aircraft(): Aircraft
    {
        return Aircraft::firstOrCreate(['registration' => 'D-KABC'], ['model' => 'ASK 21']);
    }

    private function directive(
        DirectiveKind $kind = DirectiveKind::Lta,
        string $number = 'LTA-2026-005',
        ?int $recurringMonths = null,
    ): Directive {
        return Directive::create([
            'source' => 'manual',
            'number' => $number,
            'title' => 'Beschlag prüfen',
            'kind' => $kind,
            'bindingness' => $kind->isMandatory()
                ? Bindingness::Mandatory
                : Bindingness::Optional,
            'subject_kind' => SubjectKind::AircraftModel,
            'subject_model' => 'ASK 21',
            'is_recurring' => $recurringMonths !== null,
            'interval_months' => $recurringMonths,
        ]);
    }

    private ?User $inspectorUser = null;

    private function inspector(): User
    {
        if ($this->inspectorUser !== null) {
            return $this->inspectorUser->fresh();
        }

        $user = User::factory()->create(['is_active' => true]);
        $user->givePermissionTo(Permissions::DIRECTIVES_ASSESS);

        Qualification::create([
            'user_id' => $user->id,
            'type' => Qualification::TYPE_PART66,
            'reference' => 'DE.66.12345',
            'category' => 'B1',
            'valid_from' => now()->subYear()->toDateString(),
        ]);

        return $this->inspectorUser = $user->fresh();
    }
}
