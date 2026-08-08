<?php

declare(strict_types=1);

namespace Tests\Feature\Directives;

use App\Core\Modules\ModuleManager;
use App\Models\User;
use App\Modules\Directives\Actions\AdoptGeneralDirective;
use App\Modules\Directives\Enums\ComplianceState;
use App\Modules\Directives\Enums\DirectiveKind;
use App\Modules\Directives\Enums\SubjectKind;
use App\Modules\Directives\Models\Directive;
use App\Modules\Directives\Models\DirectiveApplication;
use App\Modules\Directives\Permissions;
use App\Modules\Fleet\Airworthiness\AirworthinessCheck;
use App\Modules\Fleet\Models\Aircraft;
use App\Modules\TaskCards\Actions\ManageWorkOrder;
use App\Modules\TaskCards\Models\WorkOrder;
use App\Modules\TaskCards\Permissions as CardPermissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * General notes: approved data, not an obligation.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * Vorgabe: "DG kann mir zum Beispiel per general TM erlauben ein fenster
 * einzubauen, was via cs stan nicht möglich ist." They are a way to make a
 * change legally -- so an aircraft is never overdue on one, and one that has
 * not been carried out has no business on that aircraft's sheet.
 *
 * Everything below is one of the two halves of that: what they must NOT do
 * before the decision, and what must happen at the moment of it.
 * ─────────────────────────────────────────────────────────────────────────────
 */
final class GeneralDirectiveTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        foreach ([
            Permissions::DIRECTIVES_VIEW,
            Permissions::DIRECTIVES_MANAGE,
            CardPermissions::CARDS_WORK,
        ] as $p) {
            Permission::findOrCreate($p, 'web');
        }

        app(ModuleManager::class)->enable('fleet');
        app(ModuleManager::class)->enable('taskcards');
        app(ModuleManager::class)->enable('directives');
    }

    #[Test]
    public function a_general_note_is_an_open_item_like_any_other(): void
    {
        /*
         * It used to be hidden until somebody recorded it as carried out, and it
         * had a list of its own. Vorgabe: "wir sollten die 2. liste für general
         * aus der ui nehmen und auch die optionalen einfach normal in die liste
         * einbinden. das fühlt sich sauberer an."
         *
         * Cleaner, and not only in the interface: BINDINGNESS ALREADY SAID ALL
         * OF IT. An optional line may be declined and answered for, a mandatory
         * one may not -- a second flag on top could only agree with that or
         * contradict it, and it contradicted it on every sheet measured
         * (Schleicher's general notes are 17 binding of 18).
         */
        $aircraft = $this->aircraft();
        $this->general();

        $items = app(AirworthinessCheck::class)->openItemsFor($aircraft);

        $this->assertNotSame([], array_filter(
            $items,
            static fn ($item): bool => str_contains($item->what, 'DG-G-02'),
        ));
    }

    #[Test]
    public function an_ordinary_directive_still_is(): void
    {
        // The same aircraft, the same absence of an assessment. Only the flag
        // differs, so the test says what the flag does and nothing else.
        $aircraft = $this->aircraft();
        $this->general(['number' => 'TM 359/9', 'is_general' => false]);

        $items = app(AirworthinessCheck::class)->openItemsFor($aircraft);

        $this->assertNotSame([], array_filter(
            $items,
            static fn ($item): bool => str_contains($item->what, 'TM 359/9'),
        ));
    }

    #[Test]
    public function once_carried_out_it_behaves_like_any_other(): void
    {
        $aircraft = $this->aircraft();
        $directive = $this->general();

        DirectiveApplication::create([
            'directive_id' => $directive->id,
            'aircraft_id' => $aircraft->id,
            'aircraft_registration' => $aircraft->registration,
            'state' => ComplianceState::Complied,
            'complied_at' => now()->subMonth()->toDateString(),
            'assessed_by' => $this->user()->id,
        ]);

        // It is part of this aeroplane's history now: what was fitted, when, and
        // on whose word. A later note referring to it has something to refer to.
        $this->assertDatabaseHas('directive_applications', [
            'directive_id' => $directive->id,
            'aircraft_id' => $aircraft->id,
            'state' => ComplianceState::Complied->value,
        ]);
    }

    #[Test]
    public function deciding_to_carry_one_out_opens_a_visit(): void
    {
        $aircraft = $this->aircraft();
        $directive = $this->general();

        $this->assertSame(0, WorkOrder::where('aircraft_id', $aircraft->id)->count());

        $result = app(AdoptGeneralDirective::class)->handle($directive, $aircraft, $this->user());

        // Vorgabe: "wenn ich beschließe eine general TM durchzuführen sollte auch
        // direkt eine workorder aufgehen, dort kann dann das material eingebucht
        // werden etc."
        $this->assertTrue($result['opened']);
        $this->assertSame($aircraft->id, $result['order']->aircraft_id);
        $this->assertStringContainsString('DG-G-02', $result['card']->title);
    }

    #[Test]
    public function an_open_visit_is_reused_rather_than_doubled(): void
    {
        $aircraft = $this->aircraft();
        $user = $this->user();

        $order = app(ManageWorkOrder::class)
            ->open($aircraft, 'Jahresnachprüfung', $user);

        $result = app(AdoptGeneralDirective::class)->handle($this->general(), $aircraft, $user);

        // Two visits on one aircraft on one day is how parts get booked against
        // the wrong one -- and somewhere for the parts to go is the entire point
        // of opening one here.
        $this->assertFalse($result['opened']);
        $this->assertSame($order->id, $result['order']->id);
        $this->assertSame(1, WorkOrder::where('aircraft_id', $aircraft->id)->count());
    }

    #[Test]
    public function it_refuses_for_an_aircraft_the_note_does_not_cover(): void
    {
        // "General" does not mean "everything": DG-G-06 names DG-500 only, and
        // several general notes are narrower than a type's own overview.
        $other = Aircraft::create(['registration' => 'D-KXYZ', 'model' => 'LS 4', 'is_active' => true]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/gilt nicht für D-KXYZ/');

        app(AdoptGeneralDirective::class)->handle($this->general(), $other, $this->user());
    }

    private function aircraft(): Aircraft
    {
        return Aircraft::create(['registration' => 'D-KABC', 'model' => 'DG-300', 'is_active' => true]);
    }

    /** @param array<string, mixed> $attributes */
    private function general(array $attributes = []): Directive
    {
        return Directive::create($attributes + [
            'source' => 'dg',
            'number' => 'DG-G-02',
            'title' => 'Einbau von Transponder und Transponderantenne',
            'kind' => DirectiveKind::Tm,
            'subject_kind' => SubjectKind::AircraftModel,
            'subject_model' => 'DG-300',
            'is_general' => true,
        ]);
    }

    private function user(): User
    {
        // CARDS_WORK gehoert dazu, seit das Kartenanlegen aus den LTA auch die
        // Huerde der Arbeitskarten verlangt -- die Uebernahme schreibt in den
        // Vorgang, und Leserecht allein darf dafuer nicht reichen.
        return tap(User::factory()->create(['is_active' => true]))
            ->givePermissionTo([
                Permissions::DIRECTIVES_VIEW,
                Permissions::DIRECTIVES_MANAGE,
                CardPermissions::CARDS_WORK,
            ]);
    }
}
