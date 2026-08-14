<?php

declare(strict_types=1);

namespace Tests\Feature\Directives;

use App\Core\Access\AccessSetup;
use App\Core\Modules\ModuleManager;
use App\Models\User;
use App\Modules\Directives\Actions\PruneDirectives;
use App\Modules\Directives\Enums\Bindingness;
use App\Modules\Directives\Enums\DirectiveKind;
use App\Modules\Directives\Enums\SubjectKind;
use App\Modules\Directives\Models\Directive;
use App\Modules\Directives\Models\DirectiveApplication;
use App\Modules\Directives\Permissions;
use App\Modules\Fleet\Models\Aircraft;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

/**
 * Die Liste aufräumen -- und was dabei stehen bleibt.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * Feldtest: "zum speicher sparen möchte ich gerne noch die LTA/TM Liste
 * aufräumen können, also alles löschen zu dem es kein Flugzeug gibt."
 *
 * Die Tests halten vor allem die GRENZEN fest, denn die sind das Heikle an
 * einem Löschknopf: Nachweise bleiben, weiches Löschen statt hartem, und eine
 * leere Flotte räumt gar nichts weg.
 * ─────────────────────────────────────────────────────────────────────────────
 */
final class PruneTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        app(ModuleManager::class)->enable('fleet');
        app(ModuleManager::class)->enable('directives');
        app(ModuleManager::class)->forgetCache();
        app(AccessSetup::class)->run();
    }

    #[Test]
    public function a_line_without_a_matching_aircraft_goes_away(): void
    {
        $this->fleetWithAsk21();

        $passt = $this->directive('TM 21-01', 'ASK 21');
        $fremd = $this->directive('TM 300/12', 'DG-300');

        $entfernt = app(PruneDirectives::class)->handle($this->manager());

        $this->assertSame(1, $entfernt);
        $this->assertNull(Directive::find($fremd->id));
        $this->assertNotNull(Directive::find($passt->id));
    }

    #[Test]
    public function an_assessed_line_stays_even_without_its_aircraft(): void
    {
        // Eine Beurteilung ist ein Nachweis. Nachweise räumt man nicht weg --
        // auch nicht, wenn das Luftfahrzeug den Verein verlassen hat. Genau
        // dieser Fall: Die DG wurde verkauft, ihre Beurteilung bleibt lesbar.
        $this->fleetWithAsk21();
        $verkauft = Aircraft::create(['registration' => 'D-KWEG', 'model' => 'DG-300']);

        $beurteilt = $this->directive('TM 300/12', 'DG-300');

        DirectiveApplication::create([
            'directive_id' => $beurteilt->id,
            'aircraft_id' => $verkauft->id,
            'aircraft_registration' => $verkauft->registration,
            'state' => 'not_applicable',
            'assessed_at' => now()->toDateString(),
        ]);

        $verkauft->delete();

        $this->assertSame(0, app(PruneDirectives::class)->handle($this->manager()));
        $this->assertNotNull(Directive::find($beurteilt->id));
    }

    #[Test]
    public function an_empty_fleet_prunes_nothing(): void
    {
        // Mitten in der Einrichtung passte "kein Flugzeug passt" auf jede
        // Zeile -- der Knopf leerte sonst die ganze Liste.
        $this->directive('TM 300/12', 'DG-300');
        $this->directive('TM 21-01', 'ASK 21');

        $this->assertSame(0, app(PruneDirectives::class)->handle($this->manager()));
        $this->assertSame(2, Directive::query()->count());
    }

    #[Test]
    public function what_was_pruned_comes_back_when_the_aircraft_does(): void
    {
        /*
         * Weich gelöscht, nicht hart: Kommt das Muster später in die Flotte,
         * stellt der nächste Import dieselbe Zeile wieder her -- samt ihrer
         * Nummer. Hart gelöscht käme sie als NEUE Zeile wieder, und die alte
         * Beurteilungsgeschichte wäre für immer weg.
         */
        $this->fleetWithAsk21();
        $fremd = $this->directive('TM 300/12', 'DG-300');

        app(PruneDirectives::class)->handle($this->manager());

        $this->assertNotNull(Directive::withTrashed()->find($fremd->id));
        $this->assertTrue(Directive::withTrashed()->find($fremd->id)->trashed());
    }

    #[Test]
    public function pruning_needs_the_manage_permission(): void
    {
        $this->fleetWithAsk21();
        $this->directive('TM 300/12', 'DG-300');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/directives\.manage/');

        app(PruneDirectives::class)->handle(User::factory()->create(['is_active' => true]));
    }

    private function fleetWithAsk21(): Aircraft
    {
        return Aircraft::create(['registration' => 'D-KABC', 'model' => 'ASK 21']);
    }

    private function directive(string $number, string $model): Directive
    {
        return Directive::create([
            'source' => 'manual',
            'number' => $number,
            'title' => 'Irgendetwas prüfen',
            'kind' => DirectiveKind::Tm,
            'bindingness' => Bindingness::Mandatory,
            'subject_kind' => SubjectKind::AircraftModel,
            'subject_model' => $model,
        ]);
    }

    private function manager(): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->givePermissionTo(Permissions::DIRECTIVES_MANAGE);

        return $user->fresh();
    }
}
