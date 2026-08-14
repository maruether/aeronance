<?php

declare(strict_types=1);

namespace Tests\Feature\Modules;

use App\Core\Access\AccessSetup;
use App\Models\User;
use App\Modules\Directives\Filament\Pages\AircraftDirectivesPage;
use App\Modules\Directives\Filament\Pages\SourceCredentialsPage;
use App\Modules\Directives\Filament\Resources\Directives\DirectiveResource;
use App\Modules\Fleet\Filament\Pages\DuePage;
use App\Modules\Fleet\Filament\Resources\Aircraft\AircraftResource;
use App\Modules\Fleet\Filament\Resources\AircraftTypes\AircraftTypeResource;
use App\Modules\Fleet\Filament\Resources\ComponentTypes\ComponentTypeResource;
use App\Modules\Fleet\Filament\Resources\Holders\HolderResource;
use App\Modules\Fleet\Filament\Resources\MaintenanceManuals\MaintenanceManualResource;
use App\Modules\Fleet\Filament\Resources\Weighings\WeighingResource;
use App\Modules\Inspection\Filament\Resources\IncomingInspections\IncomingInspectionResource;
use App\Modules\Part66\Filament\Pages\ExperienceLogPage;
use App\Modules\TaskCards\Filament\Resources\Findings\FindingResource;
use App\Modules\TaskCards\Filament\Resources\WorkOrders\WorkOrderResource;
use App\Modules\Tooling\Filament\Resources\Tools\ToolResource;
use App\Modules\Warehouse\Filament\Pages\DisposalPage;
use App\Modules\Warehouse\Filament\Pages\IssueStockPage;
use App\Modules\Warehouse\Filament\Pages\ReceiveStockPage;
use App\Modules\Warehouse\Filament\Pages\RemovalPage;
use App\Modules\Warehouse\Filament\Pages\RepairPage;
use App\Modules\Warehouse\Filament\Pages\StocktakePage;
use App\Modules\Warehouse\Filament\Resources\PartTypes\PartTypeResource;
use App\Modules\Warehouse\Filament\Resources\RepairDispatches\RepairDispatchResource;
use App\Modules\Warehouse\Filament\Resources\StockLots\StockLotResource;
use App\Modules\Warehouse\Filament\Resources\StockMovements\StockMovementResource;
use App\Modules\Warehouse\Filament\Resources\StorageLocations\StorageLocationResource;
use App\Modules\Warehouse\Filament\Resources\Suppliers\SupplierResource;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;
use Tests\Support\RendersModulePages;
use Tests\TestCase;

/**
 * Jeder Bildschirm jedes Moduls -- einmal wirklich gebaut.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * Vorgabe: „ich hab das tool tatsächlich noch nie gesehen" -- und auf die Frage,
 * ob ich die Bildschirme jetzt pruefe oder er es spaeter tut: „ja, mach die
 * anderen module auch."
 *
 * Bis hierher hat in diesem Projekt KEIN Test je eine Modul-Ressource
 * gerendert. Filament-Ressourcen eines Moduls bekommen ihre Routen beim
 * Panel-Bau, und der laeuft, bevor ein Test ein Modul einschalten kann --
 * siehe RendersModulePages. Geprueft wurden Rechte und Zaehler, nie die Seite
 * selbst. Ein falscher Komponentenaufruf, eine fehlende Uebersetzung, eine
 * Methode, die es in Filament 5 nicht mehr gibt: alles unsichtbar, bis jemand
 * die Seite oeffnet.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * WAS DIESER TEST IST UND WAS NICHT.
 *
 * Ein Rauchmelder. Er prueft, dass jede Seite ANTWORTET -- nicht ob sie schoen
 * ist, nicht ob die Bedienung stimmt, nicht ob die richtigen Daten drinstehen.
 * Das steht in den fachlichen Tests der Module und gehoert dorthin.
 *
 * ALLE SEITEN EINES MODULS IN EINEM DURCHGANG, weil jeder Durchgang die App neu
 * baut: gemessen 67 Sekunden. Ein Test je Seite waere eine Viertelstunde
 * Laufzeit fuer dieselbe Aussage.
 *
 * DER BENUTZER BEKOMMT ALLE RECHTE DES MODULS. Wer was sehen darf, pruefen die
 * Rechtetests -- hier geht es darum, dass die Seite ueberhaupt entsteht, und
 * dafuer muss man sie aufrufen duerfen.
 * ─────────────────────────────────────────────────────────────────────────────
 */
#[Group('rendering')]
final class ModuleScreensRenderTest extends TestCase
{
    use RendersModulePages;

    /**
     * Alle Module auf einmal: Der App-Neustart kostet gleich viel, egal wie
     * viele Module dabei sind -- und die Seiten stoeren einander nicht.
     *
     * @return list<string>
     */
    protected function modulesUnderTest(): array
    {
        return ['warehouse', 'fleet', 'taskcards', 'part66', 'directives', 'inspection', 'tooling'];
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->bootWithModules();

        app(AccessSetup::class)->run();
    }

    /**
     * Lager: sechs Buchungsbildschirme und sechs Stammdatenlisten.
     */
    #[Test]
    public function the_warehouse_screens_build(): void
    {
        $this->actingAs($this->userWithModulePermissions('stock.', 'parts.', 'storage.', 'suppliers.'));

        $this->assertScreensRespond([
            PartTypeResource::class,
            StockLotResource::class,
            StockMovementResource::class,
            SupplierResource::class,
            StorageLocationResource::class,
            RepairDispatchResource::class,
        ], [
            ReceiveStockPage::class,
            IssueStockPage::class,
            StocktakePage::class,
            DisposalPage::class,
            RepairPage::class,
            RemovalPage::class,
        ]);

        /*
         * Und die Lose-Liste hat KEINEN Anlegen-Knopf: Lose entstehen nur
         * ueber Buchungen. Der Knopf stand da (Copy-Paste) und fuehrte in
         * ein leeres Modal -- Feldtest: "erstellen Formular ist leer".
         */
        $this->get(StockLotResource::getUrl('index'))
            ->assertSuccessful()
            ->assertDontSee(__('filament-actions::create.single.label'));
    }

    #[Test]
    public function the_fleet_screens_build(): void
    {
        $this->actingAs($this->userWithModulePermissions('fleet.', 'aircraft.', 'components.', 'counters.', 'programme.', 'reviews.', 'external_work.'));

        $this->assertScreensRespond([
            AircraftResource::class,
            AircraftTypeResource::class,
            ComponentTypeResource::class,
            HolderResource::class,
            WeighingResource::class,
            MaintenanceManualResource::class,
        ], [
            DuePage::class,
        ]);
    }

    #[Test]
    public function the_task_card_screens_build(): void
    {
        $this->actingAs($this->userWithModulePermissions('workorders.'));

        $this->assertScreensRespond([
            WorkOrderResource::class,
            FindingResource::class,
        ], []);

        // Die leere Befundliste erklaert sich selbst -- Feldtest: "nix kann
        // angelegt werden. was soll der reiter?" Absicht braucht Worte.
        $this->get(FindingResource::getUrl('index'))
            ->assertSuccessful()
            ->assertSee(__('taskcards.finding.empty.heading'));
    }

    #[Test]
    public function the_part66_and_directive_screens_build(): void
    {
        $this->actingAs($this->userWithModulePermissions('part66.', 'directives.'));

        $this->assertScreensRespond([
            DirectiveResource::class,
        ], [
            ExperienceLogPage::class,
            AircraftDirectivesPage::class,
            SourceCredentialsPage::class,
        ]);
    }

    /**
     * Die beiden Part-145-Bausteine.
     *
     * Zusammen, weil beide je eine Liste haben und der App-Neustart der teure
     * Teil ist. Die Eingangspruefung braucht die Lagerrechte MIT: Ihre
     * Annahme geht durch die Lagerfreigabe, und eine Seite, die man nur mit
     * halben Rechten aufruft, beweist wenig.
     */
    #[Test]
    public function the_part145_screens_build(): void
    {
        $this->actingAs($this->userWithModulePermissions('inspection.', 'tools.', 'stock.'));

        $this->assertScreensRespond([
            IncomingInspectionResource::class,
            ToolResource::class,
        ], []);

        /*
         * Der Anlegen-Knopf der Werkzeugliste STEHT auf der Seite. Formular,
         * Seite und Route existierten von Anfang an -- nur fuehrte nichts
         * hin. Feldtest: "nix kann angelegt werden."
         */
        $this->get(ToolResource::getUrl('index'))
            ->assertSuccessful()
            ->assertSee(__('filament-actions::create.single.label'));
    }

    /**
     * Ruft jede Seite auf und sammelt, was nicht antwortet.
     *
     * GESAMMELT UND NICHT BEIM ERSTEN ABBRUCH GEMELDET: Bei zwoelf Seiten will
     * man wissen, ob eine kaputt ist oder alle -- das ist ein Unterschied
     * zwischen einem Tippfehler und einem falschen Grundgeruest.
     *
     * @param  list<class-string>  $resources
     * @param  list<class-string>  $pages
     */
    private function assertScreensRespond(array $resources, array $pages): void
    {
        $kaputt = [];

        foreach ($resources as $resource) {
            try {
                $antwort = $this->get($resource::getUrl('index'));

                if (! $antwort->isSuccessful()) {
                    $kaputt[] = sprintf(
                        '%s -> HTTP %d%s',
                        class_basename($resource),
                        $antwort->getStatusCode(),
                        self::reason($antwort),
                    );
                }
            } catch (\Throwable $e) {
                $kaputt[] = sprintf('%s -> %s: %s', class_basename($resource), class_basename($e), $e->getMessage());
            }
        }

        foreach ($pages as $page) {
            try {
                $antwort = $this->get($page::getUrl());

                if (! $antwort->isSuccessful()) {
                    $kaputt[] = sprintf(
                        '%s -> HTTP %d%s',
                        class_basename($page),
                        $antwort->getStatusCode(),
                        self::reason($antwort),
                    );
                }
            } catch (\Throwable $e) {
                $kaputt[] = sprintf('%s -> %s: %s', class_basename($page), class_basename($e), $e->getMessage());
            }
        }

        $this->assertSame([], $kaputt, "Diese Bildschirme bauen sich nicht:\n  ".implode("\n  ", $kaputt));
    }

    /**
     * Die Begruendung aus der abgefangenen Ausnahme.
     *
     * Ohne das meldete der Test nur "HTTP 500" -- und wer das liest, muss die
     * Seite von Hand aufrufen, um zu erfahren warum. Genau das soll er ja
     * nicht muessen.
     */
    private static function reason(TestResponse $antwort): string
    {
        $ausnahme = $antwort->exception ?? null;

        if ($ausnahme === null) {
            return '';
        }

        return sprintf(' -- %s: %s', class_basename($ausnahme), mb_substr($ausnahme->getMessage(), 0, 220));
    }

    /**
     * Ein Benutzer mit allen Rechten, deren Name mit einem der Praefixe beginnt.
     *
     * Ueber die Praefixe statt ueber die Konstanten: So faellt eine spaeter
     * hinzugefuegte Berechtigung von selbst hinein, und der Test veraltet
     * nicht still.
     */
    private function userWithModulePermissions(string ...$prefixes): User
    {
        $user = User::factory()->create(['is_active' => true]);

        $rechte = Permission::query()
            ->where(function ($q) use ($prefixes): void {
                foreach ($prefixes as $prefix) {
                    $q->orWhere('name', 'like', $prefix.'%');
                }
            })
            ->pluck('name');

        foreach ($rechte as $recht) {
            $user->givePermissionTo($recht);
        }

        return $user->fresh();
    }
}
