<?php

declare(strict_types=1);

namespace Tests\Feature\Directives;

use App\Core\Models\Qualification;
use App\Models\User;
use App\Modules\Directives\Actions\AssessDirective;
use App\Modules\Directives\Enums\Bindingness;
use App\Modules\Directives\Enums\ComplianceState;
use App\Modules\Directives\Enums\DirectiveKind;
use App\Modules\Directives\Enums\SubjectKind;
use App\Modules\Directives\Models\Directive;
use App\Modules\Directives\Models\DirectiveApplication;
use App\Modules\Directives\Permissions;
use App\Modules\Fleet\Actions\ListInMaintenanceProgramme;
use App\Modules\Fleet\Enums\CounterKind;
use App\Modules\Fleet\Models\Aircraft;
use App\Modules\Fleet\Models\CounterReading;
use App\Modules\Fleet\Models\Holder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Confirming a line for an aircraft -- the act the module exists for.
 *
 * Vorgabe: "es gibt aber nicht nur ja/nein sondern auch nicht zutreffend (mit
 * begründung) und nicht durchgeführt."
 */
final class AssessmentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        foreach ([Permissions::DIRECTIVES_ASSESS, Permissions::DIRECTIVES_MANAGE] as $p) {
            Permission::findOrCreate($p, 'web');
        }
    }

    #[Test]
    public function a_line_is_complied_with_and_records_how(): void
    {
        $directive = $this->directive();
        $aircraft = $this->aircraft();
        $inspector = $this->inspector();

        $application = app(AssessDirective::class)->comply(
            $directive, $aircraft, $inspector, 'Beschlag getauscht nach Werkstattanweisung',
        );

        $this->assertSame(ComplianceState::Complied, $application->state);
        $this->assertSame($inspector->name, $application->assessed_by_name);
        $this->assertSame('DE.66.12345', $application->qualification_reference);
        $this->assertStringContainsString('Beschlag', $application->method);
        $this->assertFalse($application->isOutstanding());
    }

    #[Test]
    public function compliance_has_to_say_how(): void
    {
        $this->expectException(InvalidArgumentException::class);

        app(AssessDirective::class)->comply(
            $this->directive(), $this->aircraft(), $this->inspector(), '   ',
        );
    }

    #[Test]
    public function not_applicable_needs_a_reason_and_stays_in_the_list(): void
    {
        // the decision was this over hiding it: a line marked "not applicable, S/N
        // outside range" proves somebody looked. An absent line proves nothing.
        $directive = $this->directive();
        $aircraft = $this->aircraft();

        try {
            app(AssessDirective::class)->markNotApplicable($directive, $aircraft, $this->inspector(), '');
            $this->fail('A reason must be required.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('clearing their list', $e->getMessage());
        }

        $application = app(AssessDirective::class)->markNotApplicable(
            $directive, $aircraft, $this->inspector(), 'S/N 0042 liegt nicht im Bereich ab 0100',
        );

        $this->assertSame(ComplianceState::NotApplicable, $application->state);
        $this->assertStringContainsString('0042', $application->reason);
        $this->assertFalse($application->isOutstanding());
    }

    #[Test]
    public function not_carried_out_is_an_answer_not_a_gap(): void
    {
        // THE distinction. Both mean the work has not happened; only one means
        // somebody decided that -- and the record has to tell them apart.
        $directive = $this->directive(kind: DirectiveKind::Tm, number: 'TM-2026-77');
        $aircraft = $this->aircraft();

        $unassessed = app(AssessDirective::class)->applicationFor($directive, $aircraft);
        $this->assertSame(ComplianceState::Open, $unassessed->state);
        $this->assertFalse($unassessed->state->isAssessed());

        $stated = app(AssessDirective::class)->markNotCarriedOut(
            $directive, $aircraft, $this->inspector(), 'Ersatzteil beim Hersteller nicht lieferbar',
        );

        $this->assertSame(ComplianceState::NotCarriedOut, $stated->state);
        $this->assertTrue($stated->state->isAssessed(), 'Somebody looked at it.');
        $this->assertTrue($stated->isOutstanding(), 'And it still wants attention.');
        $this->assertSame($this->inspector()->name, $stated->assessed_by_name);
    }

    #[Test]
    public function a_mandatory_directive_cannot_be_declared_not_carried_out(): void
    {
        // Vorgabe: "nur optional darf den status nicht durchgeführt erhalten."
        // There is no declaration for skipping a binding directive -- this test
        // asserted the opposite until that correction.
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/no such thing as deciding not to carry it out/');

        app(AssessDirective::class)->markNotCarriedOut(
            $this->directive(kind: DirectiveKind::Lta),
            $this->aircraft(), $this->inspector(), 'Teil nicht lieferbar',
        );
    }

    #[Test]
    public function an_adopted_tm_is_mandatory_and_refuses_it_too(): void
    {
        // Bindingness is independent of the kind: the same TM becomes binding the
        // day an authority adopts it, without its number changing. Deriving one
        // from the other made that case unrepresentable.
        $adopted = $this->directive(
            kind: DirectiveKind::Tm, number: 'TM-2026-99', bindingness: Bindingness::Mandatory,
        );

        $this->expectException(RuntimeException::class);

        app(AssessDirective::class)->markNotCarriedOut(
            $adopted, $this->aircraft(), $this->inspector(), 'Verschoben',
        );
    }

    #[Test]
    public function an_optional_line_left_undone_does_not_ground_the_aircraft(): void
    {
        $refused = app(AssessDirective::class)->markNotCarriedOut(
            $this->directive(kind: DirectiveKind::Tm, number: 'TM-2026-77'),
            $this->aircraft(), $this->inspector(), 'Empfehlung, verschoben',
        );

        $this->assertTrue($refused->isOutstanding(), 'Still on the list.');
        $this->assertFalse($refused->isBlocking(), 'But not grounding.');
    }

    #[Test]
    public function a_recommended_line_may_be_declined_and_stays_distinguishable(): void
    {
        /*
         * The third category, and both halves of what the brief said about it:
         * "Empfohlen bedeutet Optional, aber der hersteller empfielt es. Das ist
         * eine eigene Kategorie."
         *
         * It may be declined -- the manufacturer's advice is advice, not a
         * requirement -- and it stays visibly its own thing afterwards, because
         * "we skipped one the maker advised" and "we skipped a free choice" are
         * different sentences to read back in a year.
         */
        $recommended = $this->directive(
            kind: DirectiveKind::Tm, number: 'TM-2026-55', bindingness: Bindingness::Recommended,
        );

        $refused = app(AssessDirective::class)->markNotCarriedOut(
            $recommended, $this->aircraft(), $this->inspector(), 'Für diese Saison verschoben',
        );

        $this->assertTrue($refused->isOutstanding(), 'Still on the list.');
        $this->assertFalse($refused->isBlocking(), 'But not grounding.');
        $this->assertSame(Bindingness::Recommended, $refused->directive->bindingness);
    }

    #[Test]
    public function an_unassessed_line_grounds_it_and_stops_a_release(): void
    {
        // "nicht beurteilt ist ne red flag und verhindert die freigabe." Nobody
        // has read it, so nobody can say whether it is the harmless one.
        $application = app(AssessDirective::class)->applicationFor(
            $this->directive(kind: DirectiveKind::Tm, number: 'TM-2026-77'), $this->aircraft(),
        );
        $application->save();

        $fresh = $application->fresh()->load('directive');

        $this->assertTrue($fresh->isBlocking(), 'Even an optional line, while unread.');
        $this->assertTrue($fresh->blocksRelease());
    }

    #[Test]
    public function refusing_an_optional_line_needs_part66_or_the_holder_but_never_a_pilot_owner(): void
    {
        /*
         * Vorgabe: "nicht durchgeführt braucht auch part66 oder halter (nicht
         * p/o)." A pilot-owner authorisation certifies maintenance; it does not
         * waive a manufacturer's recommendation. Treating it as sufficient would
         * be the same mistake as letting a pilot-owner release another's work.
         */
        $aircraft = $this->aircraft();
        $directive = $this->directive(kind: DirectiveKind::Tm, number: 'TM-2026-77');

        $pilotOwner = $this->userWith(Permissions::DIRECTIVES_ASSESS);
        app(ListInMaintenanceProgramme::class)->add($aircraft, $pilotOwner);

        try {
            app(AssessDirective::class)->markNotCarriedOut(
                $directive, $aircraft, $pilotOwner->fresh(), 'Lass ich',
            );
            $this->fail('A pilot-owner authorisation must not cover this.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('pilot-owner authorisation does not cover', $e->getMessage());
        }

        // The holder may, in that capacity -- and the record says so.
        $holderUser = $this->userWith(Permissions::DIRECTIVES_ASSESS);
        $holder = Holder::create([
            'name' => 'Privathalter', 'type' => Holder::TYPE_PRIVATE,
            'user_id' => $holderUser->id,
        ]);
        $aircraft->update(['holder_id' => $holder->id]);

        $recorded = app(AssessDirective::class)->markNotCarriedOut(
            $directive, $aircraft->fresh(), $holderUser->fresh(), 'Kosten stehen nicht im Verhältnis',
        );

        $this->assertSame(ComplianceState::NotCarriedOut, $recorded->state);
        $this->assertSame('holder', $recorded->qualification_type, 'The capacity is recorded.');
    }

    #[Test]
    public function and_a_part66_licence_may_regardless_of_who_holds_the_aircraft(): void
    {
        $recorded = app(AssessDirective::class)->markNotCarriedOut(
            $this->directive(kind: DirectiveKind::Tm, number: 'TM-2026-77'),
            $this->aircraft(), $this->inspector(), 'Technisch nicht sinnvoll',
        );

        $this->assertSame(Qualification::TYPE_PART66, $recorded->qualification_type);
        $this->assertSame('DE.66.12345', $recorded->qualification_reference);
    }

    #[Test]
    public function a_recurring_line_stays_ticked_until_its_interval_comes_round(): void
    {
        // Vorgabe: "abgehakte punkte so lange abgehakt bis ihre laufzeit kickt."
        $directive = $this->directive(recurringMonths: 24);
        $aircraft = $this->aircraft();

        $application = app(AssessDirective::class)->comply(
            $directive, $aircraft, $this->inspector(), 'Geprüft',
            on: now()->subMonths(12)->toDateString(),
        );

        $this->assertFalse($application->isOutstanding(), 'Twelve months in, still ticked.');
        $this->assertSame(
            now()->subMonths(12)->addMonths(24)->toDateString(),
            $application->next_due_at->toDateString(),
            'Counted from the day it was done, not from the deadline.',
        );

        // And once the interval has passed.
        $this->assertTrue($application->isOutstanding(now()->addMonths(13)->toDateString()));
    }

    #[Test]
    public function a_counter_interval_is_measured_from_the_reading_at_compliance(): void
    {
        // An aircraft that has flown 300 hours gets its next 100-hour item at 400.
        $aircraft = $this->aircraft();
        CounterReading::create([
            'aircraft_id' => $aircraft->id,
            'kind' => CounterKind::FlightHours,
            'value' => 300,
            'read_at' => now()->toDateString(),
        ]);

        $directive = $this->directive(recurringCounter: 'flight_hours', recurringValue: 100);

        $application = app(AssessDirective::class)->comply(
            $directive, $aircraft->fresh(), $this->inspector(), 'Geprüft',
        );

        $this->assertSame(400.0, (float) $application->next_due_value);
        $this->assertFalse($application->isOutstanding());

        CounterReading::create([
            'aircraft_id' => $aircraft->id,
            'kind' => CounterKind::FlightHours,
            'value' => 405,
            'read_at' => now()->toDateString(),
        ]);

        $this->assertTrue($application->fresh()->load('aircraft', 'directive')->isOutstanding());
    }

    #[Test]
    public function a_negative_answer_carries_no_recurrence(): void
    {
        // There is nothing to come round.
        $application = app(AssessDirective::class)->markNotApplicable(
            $this->directive(recurringMonths: 12), $this->aircraft(), $this->inspector(),
            'Ausrüstung nicht verbaut',
        );

        $this->assertNull($application->next_due_at);
        $this->assertNull($application->next_due_value);
    }

    #[Test]
    public function every_assessment_needs_a_qualification(): void
    {
        // Including "not applicable" -- which is not the cautious option: set it
        // wrongly and a mandatory directive silently leaves the list.
        $directive = $this->directive();
        $aircraft = $this->aircraft();
        $unqualified = $this->userWith(Permissions::DIRECTIVES_ASSESS);

        // markNotCarriedOut is deliberately absent: it has its own standing rule
        // -- Part-66 or the holder -- covered by its own test above.
        foreach (['comply', 'markNotApplicable'] as $method) {
            try {
                app(AssessDirective::class)->{$method}($directive, $aircraft, $unqualified, 'Etwas');
                $this->fail(sprintf('%s must require a qualification.', $method));
            } catch (RuntimeException $e) {
                $this->assertStringContainsString('qualified staff', $e->getMessage());
            }
        }
    }

    #[Test]
    public function without_the_permission_nothing_is_assessed(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/permission/');

        app(AssessDirective::class)->comply(
            $this->directive(), $this->aircraft(), $this->userWith(), 'Etwas',
        );
    }

    #[Test]
    public function a_repeat_compliance_updates_the_same_row(): void
    {
        // One row per directive per aircraft; the history lives in the audit trail.
        $directive = $this->directive(recurringMonths: 12);
        $aircraft = $this->aircraft();
        $service = app(AssessDirective::class);

        $first = $service->comply($directive, $aircraft, $this->inspector(), 'Erste Prüfung',
            on: now()->subMonths(13)->toDateString());
        $second = $service->comply($directive, $aircraft, $this->inspector(), 'Zweite Prüfung');

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, DirectiveApplication::count());
        $this->assertStringContainsString('Zweite', $second->method);
        $this->assertFalse($second->isOutstanding());
    }

    #[Test]
    public function reopening_resets_the_view_and_needs_a_reason(): void
    {
        $directive = $this->directive();
        $aircraft = $this->aircraft();
        app(AssessDirective::class)->comply($directive, $aircraft, $this->inspector(), 'Gemacht');

        try {
            app(AssessDirective::class)->reopen($directive, $aircraft, $this->inspector(), '  ');
            $this->fail('A reason must be required.');
        } catch (InvalidArgumentException) {
        }

        $reopened = app(AssessDirective::class)->reopen(
            $directive, $aircraft, $this->inspector(), 'Falsches Luftfahrzeug beurteilt',
        );

        $this->assertSame(ComplianceState::Open, $reopened->state);
        $this->assertNull($reopened->assessed_by_name);
        $this->assertNull($reopened->method);
    }

    private function aircraft(string $registration = 'D-KABC'): Aircraft
    {
        return Aircraft::firstOrCreate(['registration' => $registration], ['model' => 'ASK 21']);
    }

    private function directive(
        DirectiveKind $kind = DirectiveKind::Lta,
        string $number = 'LTA-2026-005',
        ?int $recurringMonths = null,
        ?string $recurringCounter = null,
        ?float $recurringValue = null,
        ?Bindingness $bindingness = null,
    ): Directive {
        return Directive::create([
            'source' => 'manual',
            'number' => $number,
            'title' => 'Beschlag Höhenruderanschluss prüfen',
            'kind' => $kind,

            // Derived from the kind unless a test means otherwise -- only an
            // optional line may be refused.
            'bindingness' => $bindingness ?? ($kind->isMandatory()
                ? Bindingness::Mandatory
                : Bindingness::Optional),
            'subject_kind' => SubjectKind::AircraftModel,
            'subject_model' => 'ASK 21',
            'issued_at' => now()->subYear()->toDateString(),
            'is_recurring' => $recurringMonths !== null || $recurringCounter !== null,
            'interval_months' => $recurringMonths,
            'interval_counter' => $recurringCounter,
            'interval_value' => $recurringValue,
        ]);
    }

    private ?User $inspectorUser = null;

    private function inspector(): User
    {
        if ($this->inspectorUser !== null) {
            return $this->inspectorUser->fresh();
        }

        $user = $this->userWith(Permissions::DIRECTIVES_ASSESS, Permissions::DIRECTIVES_MANAGE);

        Qualification::create([
            'user_id' => $user->id,
            'type' => Qualification::TYPE_PART66,
            'reference' => 'DE.66.12345',
            'category' => 'B1',
            'valid_from' => now()->subYear()->toDateString(),
        ]);

        return $this->inspectorUser = $user->fresh();
    }

    private function userWith(string ...$permissions): User
    {
        $user = User::factory()->create(['is_active' => true]);

        foreach ($permissions as $permission) {
            $user->givePermissionTo($permission);
        }

        return $user->fresh();
    }
}
