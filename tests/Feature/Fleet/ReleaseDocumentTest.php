<?php

declare(strict_types=1);

namespace Tests\Feature\Fleet;

use App\Core\Modules\ModuleManager;
use App\Modules\Fleet\Enums\DocumentType;
use App\Modules\Fleet\Listeners\FileReleaseAsAircraftDocument;
use App\Modules\Fleet\Models\Aircraft;
use App\Modules\Fleet\Models\AircraftDocument;
use App\Modules\TaskCards\Events\ReleaseIssued;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Die Freigabebescheinigung landet in der Lebenslaufakte -- als Verweis.
 *
 * Feldtest: "Freigaben landen nicht als pdf in den dokumenten." Das Projekt
 * druckt per Browser; die Akte zeigt deshalb auf die Bescheinigung in der
 * Werkstatt (Titel wird zum Link), statt eine Zweitdatei zu fuehren, die von
 * ihr abweichen koennte.
 */
final class ReleaseDocumentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        app(ModuleManager::class)->enable('fleet');
        app(ModuleManager::class)->forgetCache();
    }

    #[Test]
    public function an_issued_release_files_itself_as_a_document_link(): void
    {
        $aircraft = Aircraft::create(['registration' => 'D-KABC', 'model' => 'ASK 21']);

        app(FileReleaseAsAircraftDocument::class)->handle(new ReleaseIssued(
            aircraftId: $aircraft->id,
            releaseNumber: 'CRS-2026-001',
            releasedAt: '2026-08-12',
            printUrl: 'https://test.example/freigabe/1',
        ));

        $dokument = AircraftDocument::sole();

        $this->assertSame(DocumentType::Crs, $dokument->type);
        $this->assertSame('CRS-2026-001', $dokument->reference);
        $this->assertSame('https://test.example/freigabe/1', $dokument->link);
        $this->assertFalse($dokument->expires(), 'Eine Bescheinigung laeuft nicht ab.');
    }

    #[Test]
    public function a_correction_adds_a_second_line_instead_of_replacing(): void
    {
        // Die Akte zeigt, was es GAB -- der Ausdruck der abgeloesten
        // Bescheinigung traegt ohnehin das SUPERSEDED-Banner.
        $aircraft = Aircraft::create(['registration' => 'D-KABC', 'model' => 'ASK 21']);

        foreach (['CRS-2026-001', 'CRS-2026-002'] as $nummer) {
            app(FileReleaseAsAircraftDocument::class)->handle(new ReleaseIssued(
                aircraftId: $aircraft->id,
                releaseNumber: $nummer,
                releasedAt: '2026-08-12',
                printUrl: 'https://test.example/freigabe/'.$nummer,
            ));
        }

        $this->assertSame(2, AircraftDocument::count());
    }

    #[Test]
    public function an_unknown_aircraft_is_left_alone(): void
    {
        // Leise, wie beim Vorbild: Dieser Code laeuft hinter fremder Arbeit.
        app(FileReleaseAsAircraftDocument::class)->handle(new ReleaseIssued(
            aircraftId: 999999,
            releaseNumber: 'CRS-X',
            releasedAt: '2026-08-12',
            printUrl: 'https://test.example/x',
        ));

        $this->assertSame(0, AircraftDocument::count());
    }
}
