<?php

declare(strict_types=1);

namespace Tests\Feature\Fleet;

use App\Core\Modules\ModuleManager;
use App\Models\User;
use App\Modules\Fleet\Models\Aircraft;
use App\Modules\Fleet\Models\ComponentLimit;
use App\Modules\Fleet\Models\ComponentType;
use App\Modules\Fleet\Models\ComponentTypeLimit;
use App\Modules\Fleet\Models\Installation;
use App\Modules\Warehouse\Actions\IssueStock;
use App\Modules\Warehouse\Actions\ReceiveStock;
use App\Modules\Warehouse\Enums\PartClassification;
use App\Modules\Warehouse\Models\PartType;
use App\Modules\Warehouse\Models\StockLot;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Der Einbau aus dem Lager erbt die Laufzeiten des Musters.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * Feldtest: "Eine schleppkupplung oder ein hoehenmesser konnen beides sein.
 * Kopplung zwischen beiden?" -- die Freigabe: Muster kennt seinen Bauteiltyp,
 * der Einbau bekommt die Muster-Laufzeiten.
 *
 * GEERBT WIRD ALS KOPIE, NIE ALS VERWEIS (E7): Der Einbau lief unter den
 * Grenzen von heute; wer morgen die Vorlage am Muster aendert, aendert keinen
 * bestehenden Einbau. Und ohne Verknuepfung bleibt alles exakt wie vorher --
 * kein Katalogeintrag, keine Laufzeiten, kein Fehler.
 * ─────────────────────────────────────────────────────────────────────────────
 */
final class LimitInheritanceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        app(ModuleManager::class)->enable('warehouse');
        app(ModuleManager::class)->enable('fleet');
        app(ModuleManager::class)->forgetCache();
    }

    #[Test]
    public function a_linked_type_hands_its_template_limits_to_the_installation(): void
    {
        $this->aircraft();
        $teil = $this->couplingPart();
        $muster = $this->linkedType($teil, [
            ['kind' => 'calendar_months', 'value' => 24, 'source' => 'TBO Tost'],
            ['kind' => 'starts', 'value' => 500, 'source' => 'TBO Tost'],
        ]);

        $this->issue($teil);

        $installation = Installation::sole();

        $this->assertSame($muster->id, $installation->component_type_id,
            'Der Einbau aus dem Lager muss katalogisiert sein.');

        $grenzen = ComponentLimit::where('installation_id', $installation->id)
            ->orderBy('kind')->get();

        $this->assertCount(2, $grenzen);
        $this->assertSame(['calendar_months', 'starts'], $grenzen->pluck('kind')->map(fn ($k) => $k->value ?? $k)->all());
        $this->assertNotNull($installation->fresh()->nextDue(), 'Die Faelligkeit muss antworten.');
    }

    #[Test]
    public function an_unlinked_part_type_changes_nothing(): void
    {
        // Der Status quo, ausdruecklich: siehe HandoverTest::no_life_limits_are_invented.
        $this->aircraft();
        $teil = $this->couplingPart();

        $this->issue($teil);

        $installation = Installation::sole();
        $this->assertNull($installation->component_type_id);
        $this->assertSame(0, ComponentLimit::count());
    }

    #[Test]
    public function the_installation_keeps_its_copy_when_the_template_changes(): void
    {
        // E7: Der Einbau lief unter den Grenzen von HEUTE.
        $this->aircraft();
        $teil = $this->couplingPart();
        $muster = $this->linkedType($teil, [['kind' => 'starts', 'value' => 500]]);

        $this->issue($teil);

        $muster->limits()->first()->update(['value' => 300]);
        ComponentTypeLimit::create(['component_type_id' => $muster->id, 'kind' => 'calendar_months', 'value' => 12]);

        $grenzen = ComponentLimit::all();
        $this->assertCount(1, $grenzen, 'Nachtraegliche Vorlagen erreichen keinen bestehenden Einbau.');
        $this->assertSame('500.00', (string) $grenzen->first()->value);
    }

    #[Test]
    public function every_installation_gets_its_own_copies(): void
    {
        $this->aircraft('D-KABC');
        $this->aircraft('D-KXYZ');
        $teil = $this->couplingPart();
        $this->linkedType($teil, [['kind' => 'starts', 'value' => 500]]);

        app(ReceiveStock::class)->handle($teil, 4, '2026-07-01', lotData: [
            'document_type' => StockLot::DOCUMENT_FORM_ONE,
            'document_reference' => 'F1-1',
        ]);
        $lot = StockLot::sole();
        app(IssueStock::class)->handle($teil->fresh(), 1, $lot, $this->mechanic(), aircraftReference: 'D-KABC');
        app(IssueStock::class)->handle($teil->fresh(), 1, $lot->fresh(), $this->mechanic(), aircraftReference: 'D-KXYZ');

        $this->assertSame(2, Installation::count());
        $this->assertSame(2, ComponentLimit::count(), 'Je Einbau eine eigene Kopie.');
        $this->assertSame(2, ComponentLimit::distinct('installation_id')->count('installation_id'));
    }

    #[Test]
    public function two_types_cannot_claim_the_same_part_type(): void
    {
        // Zwei Muster fuer denselben Bauteiltyp waeren zwei Wahrheiten ueber
        // dieselben Laufzeiten -- die Datenbank selbst lehnt ab, nicht nur
        // das Formular.
        $teil = $this->couplingPart();
        $this->linkedType($teil, []);

        $this->expectException(QueryException::class);

        ComponentType::create([
            'designation' => 'Zweite Wahrheit',
            'kind' => 'other',
            'part_type_id' => $teil->id,
        ]);
    }

    private function aircraft(string $registration = 'D-KABC'): Aircraft
    {
        return Aircraft::create(['registration' => $registration, 'model' => 'ASK 21']);
    }

    private function couplingPart(): PartType
    {
        return PartType::create([
            'name' => 'Schleppkupplung Tost E 85',
            'classification' => PartClassification::Component,
            'unit_of_measure' => 'St',
            'requires_form_one' => true,
        ]);
    }

    /** @param  list<array<string, mixed>>  $vorlagen */
    private function linkedType(PartType $teil, array $vorlagen): ComponentType
    {
        $muster = ComponentType::create([
            'designation' => 'Tost E 85',
            'manufacturer' => 'Tost',
            'kind' => 'other',
            'part_type_id' => $teil->id,
        ]);

        foreach ($vorlagen as $vorlage) {
            ComponentTypeLimit::create($vorlage + ['component_type_id' => $muster->id]);
        }

        return $muster;
    }

    private function issue(PartType $teil): void
    {
        app(ReceiveStock::class)->handle($teil, 2, '2026-07-01', lotData: [
            'document_type' => StockLot::DOCUMENT_FORM_ONE,
            'document_reference' => 'F1-2026-1',
        ]);

        app(IssueStock::class)->handle(
            $teil->fresh(), 1, StockLot::sole(), $this->mechanic(),
            aircraftReference: 'D-KABC',
        );
    }

    private function mechanic(): User
    {
        return User::factory()->create(['is_active' => true]);
    }
}
