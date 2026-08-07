<?php

declare(strict_types=1);

namespace Tests\Feature\Fleet;

use App\Core\Access\AccessSetup;
use App\Core\Modules\ModuleManager;
use App\Modules\Fleet\Actions\RecordManualRevision;
use App\Modules\Fleet\Enums\ManualKind;
use App\Modules\Fleet\Models\Aircraft;
use App\Modules\Fleet\Models\AircraftType;
use App\Modules\Fleet\Models\MaintenanceManual;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Wartungsunterlagen mit Revisionsstand.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * DER TEST, UM DEN ES GEHT: `a_new_revision_supersedes_instead_of_overwriting`.
 *
 * Der bequeme Entwurf wäre ein Feld „Revision", das man ändert. Damit wäre die
 * Frage „nach welchem Stand wurde im Mai gearbeitet?" nicht mehr zu
 * beantworten — und sie ist die einzige, die im Ernstfall zählt.
 * ─────────────────────────────────────────────────────────────────────────────
 */
final class MaintenanceManualTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        app(AccessSetup::class)->run();
        app(ModuleManager::class)->enable('fleet');
        app(ModuleManager::class)->forgetCache();
    }

    /**
     * DER TEST, UM DEN ES GEHT.
     */
    #[Test]
    public function a_new_revision_supersedes_instead_of_overwriting(): void
    {
        $alt = $this->manual('Rev. 11');

        $neu = app(RecordManualRevision::class)->supersede(
            previous: $alt,
            revision: 'Rev. 12',
            revisionDate: now()->toDateString(),
        );

        // Der alte Stand bleibt STEHEN und ist als abgelöst erkennbar.
        $this->assertFalse($alt->fresh()->isCurrent());
        $this->assertSame($neu->id, $alt->fresh()->superseded_by_id);
        $this->assertNotNull($alt->fresh()->superseded_at);
        $this->assertSame('Rev. 11', $alt->fresh()->revision);

        $this->assertTrue($neu->isCurrent());
        $this->assertSame('Rev. 12', $neu->revision);

        // Titel, Art und Dokumentnummer wandern mit.
        $this->assertSame($alt->title, $neu->title);
        $this->assertSame($alt->reference, $neu->reference);
        $this->assertSame($alt->kind, $neu->kind);
    }

    #[Test]
    public function only_one_revision_is_current(): void
    {
        $alt = $this->manual('Rev. 11');
        app(RecordManualRevision::class)->supersede($alt, 'Rev. 12');

        $this->assertSame(1, MaintenanceManual::query()->current()->count());
    }

    /**
     * Derselbe Stand zweimal ist ein Tippfehler, keine Revision.
     */
    #[Test]
    public function the_same_revision_twice_is_refused(): void
    {
        $alt = $this->manual('Rev. 11');

        $this->expectException(InvalidArgumentException::class);

        app(RecordManualRevision::class)->supersede($alt, 'Rev. 11');
    }

    /**
     * Ein Handbuch ohne Revisionsstand sieht aus wie ein aktuelles.
     */
    #[Test]
    public function a_manual_without_a_revision_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        app(RecordManualRevision::class)->add(
            for: $this->type(),
            kind: ManualKind::Maintenance,
            title: 'Wartungshandbuch',
            revision: '   ',
        );
    }

    /**
     * Was nicht mehr gilt, wird nicht noch einmal abgelöst.
     */
    #[Test]
    public function a_superseded_manual_cannot_be_superseded_again(): void
    {
        $alt = $this->manual('Rev. 11');
        app(RecordManualRevision::class)->supersede($alt, 'Rev. 12');

        $this->expectException(InvalidArgumentException::class);

        app(RecordManualRevision::class)->supersede($alt->fresh(), 'Rev. 13');
    }

    #[Test]
    public function withdrawing_needs_a_reason(): void
    {
        $handbuch = $this->manual('Rev. 11');

        $this->expectException(InvalidArgumentException::class);

        app(RecordManualRevision::class)->withdraw($handbuch, '  ');
    }

    #[Test]
    public function a_withdrawn_manual_no_longer_counts(): void
    {
        $handbuch = $this->manual('Rev. 11');

        app(RecordManualRevision::class)->withdraw($handbuch, 'Gerät ausgebaut.');

        $this->assertFalse($handbuch->fresh()->isCurrent());
        $this->assertSame(0, MaintenanceManual::query()->current()->count());
    }

    /**
     * Ein Luftfahrzeug sieht die Unterlagen seines Musters UND die eigenen.
     *
     * Wer an der Karte steht, muss nicht wissen, ob das Handbuch am Muster oder
     * am einzelnen Flugzeug hängt.
     */
    #[Test]
    public function an_aircraft_sees_its_own_and_its_types_manuals(): void
    {
        $muster = $this->type();
        $flugzeug = Aircraft::create([
            'registration' => 'D-KABC',
            'model' => 'ASK 21',
            'is_active' => true,
            'aircraft_type_id' => $muster->getKey(),
        ]);

        app(RecordManualRevision::class)->add(
            for: $muster, kind: ManualKind::Maintenance, title: 'Wartungshandbuch', revision: 'Rev. 12',
        );
        app(RecordManualRevision::class)->add(
            for: $flugzeug, kind: ManualKind::Programme, title: 'Instandhaltungsprogramm', revision: 'A',
        );

        $gefunden = MaintenanceManual::query()->for($flugzeug)->pluck('title')->all();

        $this->assertContains('Wartungshandbuch', $gefunden);
        $this->assertContains('Instandhaltungsprogramm', $gefunden);
    }

    /**
     * Der Abdruck für die Arbeitskarte trägt den Stand mit.
     */
    #[Test]
    public function the_snapshot_carries_the_revision(): void
    {
        $handbuch = $this->manual('Rev. 12');

        $abdruck = $handbuch->snapshot();

        $this->assertStringContainsString('Rev. 12', $abdruck);
        $this->assertStringContainsString('Wartungshandbuch', $abdruck);
    }

    private function type(): AircraftType
    {
        return AircraftType::create(['designation' => 'ASK 21', 'manufacturer' => 'Schleicher']);
    }

    private function manual(string $revision): MaintenanceManual
    {
        return app(RecordManualRevision::class)->add(
            for: $this->type(),
            kind: ManualKind::Maintenance,
            title: 'Wartungshandbuch',
            revision: $revision,
            reference: 'WHB-ASK21',
        );
    }
}
