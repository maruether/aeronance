<?php

declare(strict_types=1);

namespace Tests\Feature\Access;

use App\Core\Access\AccessSetup;
use App\Core\Access\CorePermissions;
use App\Core\Access\CoreRoles;
use App\Core\Filament\Pages\AuditTrail;
use App\Core\Filament\Pages\ManageModules;
use App\Core\Filament\Pages\SettingsPage;
use App\Core\Filament\Resources\RoleMappings\RoleMappingResource;
use App\Core\Filament\Resources\Roles\RoleResource;
use App\Core\Filament\Resources\Users\UserResource;
use App\Core\Identity\RoleMapping;
use App\Models\User;
use App\Modules\Billing\Filament\Resources\Customers\CustomerResource;
use App\Modules\Billing\Filament\Resources\Invoices\InvoiceResource;
use App\Modules\Billing\Filament\Resources\LabourRates\LabourRateResource;
use App\Modules\Billing\Filament\Resources\PartPrices\PartPriceResource;
use App\Modules\Billing\Models\Customer;
use App\Modules\Billing\Models\Invoice;
use App\Modules\Billing\Models\LabourRate;
use App\Modules\Billing\Models\PartPrice;
use App\Modules\Billing\Permissions as BillingPermissions;
use App\Modules\Directives\Enums\DirectiveKind;
use App\Modules\Directives\Enums\SubjectKind;
use App\Modules\Directives\Filament\Pages\AircraftDirectivesPage;
use App\Modules\Directives\Filament\Pages\SourceCredentialsPage;
use App\Modules\Directives\Filament\Resources\Directives\DirectiveResource;
use App\Modules\Directives\Models\Directive;
use App\Modules\Directives\Permissions as DirectivePermissions;
use App\Modules\Fleet\Actions\SeedWeighingSheet;
use App\Modules\Fleet\Enums\ManualKind;
use App\Modules\Fleet\Enums\SheetVariant;
use App\Modules\Fleet\Enums\Undercarriage;
use App\Modules\Fleet\Filament\Pages\DuePage;
use App\Modules\Fleet\Filament\Resources\Aircraft\AircraftResource;
use App\Modules\Fleet\Filament\Resources\AircraftTypes\AircraftTypeResource;
use App\Modules\Fleet\Filament\Resources\ComponentTypes\ComponentTypeResource;
use App\Modules\Fleet\Filament\Resources\Holders\HolderResource;
use App\Modules\Fleet\Filament\Resources\MaintenanceManuals\MaintenanceManualResource;
use App\Modules\Fleet\Filament\Resources\Weighings\Pages\EditWeighing;
use App\Modules\Fleet\Filament\Resources\Weighings\WeighingResource;
use App\Modules\Fleet\Models\Aircraft;
use App\Modules\Fleet\Models\AircraftType;
use App\Modules\Fleet\Models\MaintenanceManual;
use App\Modules\Fleet\Models\Weighing;
use App\Modules\Fleet\Permissions as FleetPermissions;
use App\Modules\Inspection\Filament\Resources\IncomingInspections\IncomingInspectionResource;
use App\Modules\Inspection\Permissions as InspectionPermissions;
use App\Modules\Part66\Filament\Pages\ExperienceLogPage;
use App\Modules\TaskCards\Actions\ManageWorkOrder;
use App\Modules\TaskCards\Enums\ActivityKind;
use App\Modules\TaskCards\Filament\Resources\Findings\FindingResource;
use App\Modules\TaskCards\Filament\Resources\WorkOrders\WorkOrderResource;
use App\Modules\TaskCards\Permissions as CardPermissions;
use App\Modules\Tooling\Filament\Resources\Tools\ToolResource;
use App\Modules\Tooling\Models\Tool;
use App\Modules\Tooling\Permissions as ToolPermissions;
use App\Modules\Vereinsflieger\Filament\Pages\MemberStatusesPage;
use App\Modules\Vereinsflieger\Filament\Resources\AircraftLinks\AircraftLinkResource;
use App\Modules\Vereinsflieger\Filament\Resources\Connections\ConnectionResource;
use App\Modules\Vereinsflieger\Models\AircraftLink;
use App\Modules\Vereinsflieger\Models\Connection;
use App\Modules\Warehouse\Enums\PartClassification;
use App\Modules\Warehouse\Filament\Pages\DisposalPage;
use App\Modules\Warehouse\Filament\Pages\IssueStockPage;
use App\Modules\Warehouse\Filament\Pages\ReceiveStockPage;
use App\Modules\Warehouse\Filament\Pages\RemovalPage;
use App\Modules\Warehouse\Filament\Pages\RepairPage;
use App\Modules\Warehouse\Filament\Pages\StockOverview;
use App\Modules\Warehouse\Filament\Pages\StocktakePage;
use App\Modules\Warehouse\Filament\Resources\PartTypes\PartTypeResource;
use App\Modules\Warehouse\Filament\Resources\PurchaseOrders\PurchaseOrderResource;
use App\Modules\Warehouse\Filament\Resources\RepairDispatches\RepairDispatchResource;
use App\Modules\Warehouse\Filament\Resources\StockLots\StockLotResource;
use App\Modules\Warehouse\Filament\Resources\StockMovements\StockMovementResource;
use App\Modules\Warehouse\Filament\Resources\StorageLocations\StorageLocationResource;
use App\Modules\Warehouse\Filament\Resources\Suppliers\SupplierResource;
use App\Modules\Warehouse\Models\PartType;
use App\Modules\Warehouse\Models\PurchaseOrder;
use App\Modules\Warehouse\Models\StockLot;
use App\Modules\Warehouse\Models\StorageLocation;
use App\Modules\Warehouse\Models\Supplier;
use App\Modules\Warehouse\Permissions as StockPermissions;
use Filament\Facades\Filament;
use Filament\Resources\Resource;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Symfony\Component\Finder\Finder;
use Tests\Support\RendersModulePages;
use Tests\TestCase;

/**
 * Deny by default, proven at the address bar -- for every screen in the panel.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * CLAUDE.md: "Rechte, die nur im UI versteckt sind, gelten als nicht vorhanden"
 * and "mindestens AuthZ-Negativtests pro Resource". The second half was owed
 * since the first module: the resources checked their permissions, but no test
 * ever typed a URL as the wrong person and looked at the status code. Each
 * module had a few such tests for its own screens; nothing guaranteed that a
 * NEW screen got one.
 *
 * This test does not know the screens by name. It asks the panel what it
 * mounts when every module is on, and refuses to pass unless every resource
 * and every page has a rule here -- so adding a screen without deciding who
 * may open it fails the suite, which is the point.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * THREE PEOPLE PER SCREEN, and the positive ones are not decoration.
 *
 *   nobody   -- logged in, active, not a single permission. Must get 403.
 *   viewer   -- exactly what the resource names for seeing the list. Must get
 *               200 on the list, 403 on create and edit where those need more.
 *   manager  -- what create and edit demand. Must get 200 there.
 *
 * Without the 200s a 403 proves nothing: a route that is broken for everyone
 * also returns an error to the wrong person. The viewer opening the list is
 * what shows that the permission in the rule is the one the resource actually
 * checks, and the manager opening the edit form is what shows that the record
 * recipe below produces something the page can load.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * ONE CLASS, FEW METHODS, ALL MODULES: each method boots the application once
 * with the full module set (see RendersModulePages), so the cost is a handful
 * of schema loads rather than one per screen. In the main suite on purpose,
 * not in the rendering group: that group runs on tags and merge requests, and a
 * permission hole should turn master red the day it is pushed.
 * ─────────────────────────────────────────────────────────────────────────────
 */
final class PanelDenyByDefaultTest extends TestCase
{
    use RendersModulePages;

    /** @var list<string> */
    private array $violations = [];

    protected function modulesUnderTest(): array
    {
        return ['warehouse', 'fleet', 'directives', 'taskcards', 'inspection', 'tooling', 'part66', 'vereinsflieger', 'billing'];
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootWithModules();
        app(AccessSetup::class)->run();
    }

    /**
     * Who may see the list, who may create and edit, and how to get a record.
     *
     * `manage` left out means viewing IS managing: whoever may open the list
     * may do everything, and the only person to refuse is the one with nothing.
     * `record` left out means the resource has no page that takes a record.
     * `edit_is_the_view` means the edit page is the only way to LOOK at the
     * record, so the viewer gets in and the page itself refuses to write --
     * which then needs its own test below, because a lock that only hides a
     * button is no lock.
     *
     * @return array<class-string<resource>, array{view: list<string>, manage?: list<string>, record?: callable(): Model, edit_is_the_view?: bool}>
     */
    private function rules(): array
    {
        return [
            // ── Core ─────────────────────────────────────────────────────────
            RoleMappingResource::class => [
                'view' => [CorePermissions::ROLES_MANAGE],
                'record' => fn (): Model => RoleMapping::create([
                    'provider' => 'vereinsflieger',
                    'kind' => RoleMapping::KIND_GROUP,
                    'value' => 'werkstatt',
                    'role_id' => Role::findByName(CoreRoles::MECHANIC)->id,
                ]),
            ],
            RoleResource::class => [
                'view' => [CorePermissions::ROLES_MANAGE],
                'record' => fn (): Model => Role::findByName(CoreRoles::MEMBER),
            ],
            UserResource::class => [
                'view' => [CorePermissions::USERS_VIEW],
                'manage' => [CorePermissions::USERS_MANAGE],
                // A plain account: editing one that may do MORE than the editor
                // is refused on purpose (see UserResource::canEdit), and that is
                // a different test.
                'record' => fn (): Model => User::factory()->create(['is_active' => true]),
            ],

            // ── Billing ──────────────────────────────────────────────────────
            CustomerResource::class => [
                'view' => [BillingPermissions::VIEW],
                'manage' => [BillingPermissions::MANAGE],
                'record' => fn (): Model => Customer::create([
                    'name' => 'Kunde '.Str::random(4),
                    'address' => "Weg 1\n79100 Freiburg",
                ]),
            ],
            InvoiceResource::class => [
                'view' => [BillingPermissions::VIEW],
                'manage' => [BillingPermissions::MANAGE],
                'record' => fn (): Model => $this->draftInvoice(),
            ],
            PartPriceResource::class => [
                'view' => [BillingPermissions::VIEW],
                'manage' => [BillingPermissions::MANAGE],
                'record' => fn (): Model => PartPrice::create([
                    'part_number' => 'AN3-'.Str::random(3),
                    'net_price' => '1.50',
                    'valid_from' => now()->toDateString(),
                ]),
            ],
            LabourRateResource::class => [
                'view' => [BillingPermissions::VIEW],
                'manage' => [BillingPermissions::MANAGE],
                'record' => fn (): Model => LabourRate::create([
                    'activity_kind' => ActivityKind::Maintenance,
                    'net_rate_per_hour' => '80.00',
                    'valid_from' => now()->toDateString(),
                ]),
            ],

            // ── Directives ───────────────────────────────────────────────────
            DirectiveResource::class => [
                'view' => [DirectivePermissions::DIRECTIVES_VIEW],
                'manage' => [DirectivePermissions::DIRECTIVES_MANAGE],
                'record' => fn (): Model => Directive::create([
                    'source' => 'manual',
                    'number' => 'LTA-1',
                    'title' => 'Von Hand erfasst',
                    'kind' => DirectiveKind::Lta,
                    'subject_kind' => SubjectKind::AircraftModel,
                ]),
            ],

            // ── Fleet ────────────────────────────────────────────────────────
            AircraftResource::class => [
                'view' => [FleetPermissions::FLEET_VIEW],
                'manage' => [FleetPermissions::FLEET_MANAGE],
                'record' => fn (): Model => $this->aircraft(),
            ],
            AircraftTypeResource::class => [
                'view' => [FleetPermissions::FLEET_VIEW],
                'manage' => [FleetPermissions::FLEET_MANAGE],
            ],
            ComponentTypeResource::class => [
                'view' => [FleetPermissions::FLEET_VIEW],
                'manage' => [FleetPermissions::COMPONENTS_MANAGE],
            ],
            HolderResource::class => [
                'view' => [FleetPermissions::FLEET_VIEW],
                'manage' => [FleetPermissions::FLEET_MANAGE],
            ],
            MaintenanceManualResource::class => [
                'view' => [FleetPermissions::FLEET_VIEW],
                'manage' => [FleetPermissions::FLEET_MANAGE],
                'record' => fn (): Model => MaintenanceManual::create([
                    'aircraft_type_id' => AircraftType::create(['designation' => 'ASK 21'])->id,
                    'kind' => ManualKind::Maintenance,
                    'title' => 'Wartungshandbuch ASK 21',
                    'revision' => 'Rev. 12',
                ]),
            ],
            WeighingResource::class => [
                'view' => [FleetPermissions::FLEET_VIEW],
                'manage' => [FleetPermissions::REVIEWS_RECORD],
                // The sheet has no view page: the edit page IS the sheet, and a
                // viewer may read it (EditWeighing::mount checks canViewAny).
                // Writing is gated inside the page -- see the dedicated test.
                'edit_is_the_view' => true,
                'record' => fn (): Model => $this->weighing(),
            ],

            // ── Inspection ───────────────────────────────────────────────────
            IncomingInspectionResource::class => [
                'view' => [InspectionPermissions::INSPECTION_VIEW],
            ],

            // ── Task cards ───────────────────────────────────────────────────
            FindingResource::class => [
                'view' => [CardPermissions::WORK_ORDERS_VIEW],
            ],
            WorkOrderResource::class => [
                'view' => [CardPermissions::WORK_ORDERS_VIEW],
                'manage' => [CardPermissions::WORK_ORDERS_MANAGE],
                'record' => fn (): Model => app(ManageWorkOrder::class)->open(
                    $this->aircraft(),
                    'Jahresnachprüfung',
                    $this->userWith(CardPermissions::WORK_ORDERS_MANAGE),
                ),
            ],

            // ── Tooling ──────────────────────────────────────────────────────
            ToolResource::class => [
                'view' => [ToolPermissions::TOOLS_VIEW],
                'manage' => [ToolPermissions::TOOLS_MANAGE],
                'record' => fn (): Model => Tool::create([
                    'inventory_number' => 'WZ-'.Str::random(6),
                    'name' => 'Drehmomentschlüssel',
                ]),
            ],

            // ── Vereinsflieger ───────────────────────────────────────────────
            AircraftLinkResource::class => [
                'view' => [CorePermissions::SETTINGS_MANAGE],
                'record' => fn (): Model => AircraftLink::create([
                    'connection_id' => $this->connection()->id,
                    'aircraft_id' => $this->aircraft()->id,
                    'callsign' => 'D-KABC',
                    'is_active' => true,
                ]),
            ],
            ConnectionResource::class => [
                'view' => [CorePermissions::SETTINGS_MANAGE],
                'record' => fn (): Model => $this->connection(),
            ],

            // ── Warehouse ────────────────────────────────────────────────────
            PartTypeResource::class => [
                'view' => [StockPermissions::PART_TYPES_MANAGE],
                'record' => fn (): Model => $this->partType(),
            ],
            PurchaseOrderResource::class => [
                'view' => [StockPermissions::ORDERS_MANAGE],
                'record' => fn (): Model => PurchaseOrder::create([
                    'supplier_id' => Supplier::create(['name' => 'Firma'])->getKey(),
                    'ordered_at' => '2026-08-01',
                ]),
            ],
            RepairDispatchResource::class => [
                'view' => [StockPermissions::STOCK_VIEW],
            ],
            StockLotResource::class => [
                'view' => [StockPermissions::STOCK_VIEW],
                'record' => fn (): Model => StockLot::create([
                    'part_type_id' => $this->partType()->getKey(),
                    'lot_number' => '260101-001',
                    'received_at' => '2026-01-01',
                ]),
            ],
            StockMovementResource::class => [
                'view' => [StockPermissions::STOCK_VIEW],
            ],
            StorageLocationResource::class => [
                'view' => [StockPermissions::LOCATIONS_MANAGE],
                'record' => fn (): Model => StorageLocation::create(['name' => 'Regal B-12']),
            ],
            SupplierResource::class => [
                'view' => [StockPermissions::SUPPLIERS_MANAGE],
                'record' => fn (): Model => Supplier::create(['name' => 'Lange Aviation']),
            ],
        ];
    }

    /**
     * Custom pages and the one permission that opens each.
     *
     * `null` means any logged-in person may open it; there is exactly one such
     * page, and its reason stands in LogAccessTest: your own experience log
     * is a record of your own Saturdays.
     *
     * @return array<class-string, string|null>
     */
    private function pageRules(): array
    {
        return [
            AuditTrail::class => CorePermissions::AUDIT_VIEW,
            ManageModules::class => CorePermissions::MODULES_MANAGE,
            SettingsPage::class => CorePermissions::SETTINGS_MANAGE,
            AircraftDirectivesPage::class => DirectivePermissions::DIRECTIVES_VIEW,
            SourceCredentialsPage::class => DirectivePermissions::DIRECTIVES_MANAGE,
            DuePage::class => FleetPermissions::FLEET_VIEW,
            ExperienceLogPage::class => null,
            MemberStatusesPage::class => CorePermissions::ROLES_MANAGE,
            DisposalPage::class => StockPermissions::STOCK_SCRAP,
            IssueStockPage::class => StockPermissions::STOCK_ISSUE,
            ReceiveStockPage::class => StockPermissions::STOCK_RECEIVE,
            RemovalPage::class => StockPermissions::STOCK_RECEIVE,
            RepairPage::class => StockPermissions::STOCK_REPAIR,
            StockOverview::class => StockPermissions::STOCK_VIEW,
            StocktakePage::class => StockPermissions::STOCK_RECEIVE,
        ];
    }

    // ── The guard: nothing in the panel escapes this file ─────────────────────

    #[Test]
    public function every_screen_the_panel_mounts_has_a_rule_here_and_every_resource_in_the_code_is_mounted(): void
    {
        $mountedResources = $this->mountedResources();
        $mountedPages = $this->mountedPages();

        // A resource class that exists but is not mounted with every module on
        // is one no test can reach -- and one that may have fallen out of a
        // plugin by accident.
        $this->assertEqualsCanonicalizing(
            $this->classesOnDisk('*Resource.php', 'Filament/Resources'),
            $mountedResources,
            'Resources on disk and resources mounted with all modules on differ.',
        );
        $this->assertEqualsCanonicalizing(
            $this->classesOnDisk('*.php', 'Filament/Pages'),
            $mountedPages,
            'Pages on disk and pages mounted with all modules on differ.',
        );

        $this->assertEqualsCanonicalizing(
            $mountedResources,
            array_keys($this->rules()),
            'Every mounted resource needs a rule in this test: who views, who manages, how to get a record.',
        );
        $this->assertEqualsCanonicalizing(
            $mountedPages,
            array_keys($this->pageRules()),
            'Every mounted page needs a rule in this test: which permission opens it.',
        );

        foreach ($this->rules() as $resource => $rule) {
            $pages = $resource::getPages();
            $takesRecord = isset($pages['edit']) || isset($pages['view']);

            if ($takesRecord && ! isset($rule['record'])) {
                $this->violations[] = "$resource has an edit or view page but no record recipe.";
            }
        }

        $this->assertNoViolations();
    }

    // ── Lists ─────────────────────────────────────────────────────────────────

    #[Test]
    public function the_list_of_every_resource_refuses_nobody_and_opens_for_its_viewer(): void
    {
        $nobody = $this->nobody();

        foreach ($this->rules() as $resource => $rule) {
            $url = $resource::getUrl('index');

            $this->expect($nobody, $url, 403, "$resource index as nobody");
            $this->expect($this->userWith(...$rule['view']), $url, 200, "$resource index as viewer");
        }

        $this->assertNoViolations();
    }

    // ── Create, edit, view ────────────────────────────────────────────────────

    #[Test]
    public function the_create_and_edit_pages_refuse_the_viewer_where_managing_needs_more(): void
    {
        $nobody = $this->nobody();

        foreach ($this->rules() as $resource => $rule) {
            $pages = $resource::getPages();
            $viewer = $this->userWith(...$rule['view']);
            $manager = $this->userWith(...$rule['view'], ...($rule['manage'] ?? []));
            $viewingIsManaging = ! isset($rule['manage']);

            if (isset($pages['create'])) {
                $url = $resource::getUrl('create');

                $this->expect($nobody, $url, 403, "$resource create as nobody");
                $this->expect($viewer, $url, $viewingIsManaging ? 200 : 403, "$resource create as viewer");
                $this->expect($manager, $url, 200, "$resource create as manager");
            }

            if (isset($pages['edit'])) {
                $record = ($rule['record'])();
                $url = $resource::getUrl('edit', ['record' => $record]);
                $viewerGetsIn = $viewingIsManaging || ($rule['edit_is_the_view'] ?? false);

                $this->expect($nobody, $url, 403, "$resource edit as nobody");
                $this->expect($viewer, $url, $viewerGetsIn ? 200 : 403, "$resource edit as viewer");
                $this->expect($manager, $url, 200, "$resource edit as manager");
            }

            if (isset($pages['view'])) {
                $record = ($rule['record'])();
                $url = $resource::getUrl('view', ['record' => $record]);

                $this->expect($nobody, $url, 403, "$resource view as nobody");
                $this->expect($viewer, $url, 200, "$resource view as viewer");
            }
        }

        $this->assertNoViolations();
    }

    // ── The one edit page a viewer may open ───────────────────────────────────

    #[Test]
    public function the_weighing_sheet_lets_a_viewer_read_but_not_save(): void
    {
        $blatt = $this->weighing();
        $viewer = $this->userWith(FleetPermissions::FLEET_VIEW);

        // Not the button -- the Livewire call behind it, which anyone with the
        // page open can send regardless of what is rendered.
        Livewire::actingAs($viewer)
            ->test(EditWeighing::class, ['record' => $blatt->getKey()])
            ->assertOk()
            ->set('kopf.max_mass_kg', '999')
            ->call('speichern')
            ->assertNotified(__('fleet.weighing.locked'));

        $this->assertNull($blatt->fresh()->max_mass_kg, 'A viewer saved the sheet.');

        // The second way to write: the header action that swaps the sheet
        // variant. Hidden for a viewer -- and in Filament a hidden action
        // counts as disabled when somebody calls it anyway (CanBeDisabled),
        // so hidden is the whole guard here. The test helper refuses to call
        // a hidden action at all, which is why this asserts the state rather
        // than the outcome.
        Livewire::actingAs($viewer)
            ->test(EditWeighing::class, ['record' => $blatt->getKey()])
            ->assertActionHidden('blattart');
    }

    // ── Pages ─────────────────────────────────────────────────────────────────

    #[Test]
    public function every_page_refuses_nobody_and_opens_for_the_permission_it_names(): void
    {
        $nobody = $this->nobody();

        foreach ($this->pageRules() as $page => $permission) {
            $url = $page::getUrl();

            if ($permission === null) {
                $this->expect($nobody, $url, 200, "$page as nobody (open to everyone logged in)");

                continue;
            }

            $this->expect($nobody, $url, 403, "$page as nobody");
            $this->expect($this->userWith($permission), $url, 200, "$page with $permission");
        }

        $this->assertNoViolations();
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function expect(User $user, string $url, int $status, string $what): void
    {
        $actual = $this->actingAs($user)->get($url)->getStatusCode();

        if ($actual !== $status) {
            $this->violations[] = sprintf('%s: expected %d, got %d (%s)', $what, $status, $actual, $url);
        }
    }

    private function assertNoViolations(): void
    {
        $this->assertSame([], $this->violations, "\n - ".implode("\n - ", $this->violations));
    }

    /** @return list<class-string<resource>> */
    private function mountedResources(): array
    {
        return array_values(Filament::getPanel('admin')->getResources());
    }

    /** @return list<class-string> */
    private function mountedPages(): array
    {
        // Filament's own pages (dashboard, profile) are not ours to rule on.
        return array_values(array_filter(
            Filament::getPanel('admin')->getPages(),
            static fn (string $page): bool => str_starts_with($page, 'App\\'),
        ));
    }

    /**
     * Every class under app/ whose path ends in the given folder, by file.
     *
     * @return list<class-string>
     */
    private function classesOnDisk(string $pattern, string $folder): array
    {
        $classes = [];

        foreach ((new Finder)->files()->in(base_path('app'))->name($pattern)->path($folder) as $file) {
            $relative = $file->getRelativePathname();

            // Only the classes directly in the folder: pages of a resource live
            // one level deeper (Resources/X/Pages/…) and are not mounted on
            // their own.
            if (! preg_match('#(?:^|/)'.preg_quote($folder, '#').'/[^/]+\.php$#', $relative)
                && ! preg_match('#(?:^|/)'.preg_quote($folder, '#').'/[^/]+/[^/]+Resource\.php$#', $relative)) {
                continue;
            }

            $classes[] = 'App\\'.str_replace('/', '\\', substr($relative, 0, -4));
        }

        sort($classes);

        return $classes;
    }

    private function nobody(): User
    {
        return User::factory()->create(['is_active' => true])->fresh();
    }

    private function userWith(string ...$permissions): User
    {
        $user = User::factory()->create(['is_active' => true]);

        foreach ($permissions as $permission) {
            $user->givePermissionTo($permission);
        }

        return $user->fresh();
    }

    private function aircraft(): Aircraft
    {
        return Aircraft::create([
            'registration' => 'D-'.strtoupper(Str::random(4)),
            'model' => 'ASK 21',
        ]);
    }

    private function draftInvoice(): Invoice
    {
        $customer = Customer::create(['name' => 'Kunde '.Str::random(4), 'address' => "Weg 1\n79100 Freiburg"]);

        return Invoice::create([
            'customer_id' => $customer->getKey(),
            'customer_name' => $customer->name,
            'customer_address' => $customer->address,
        ]);
    }

    private function weighing(): Weighing
    {
        return app(SeedWeighingSheet::class)->handle(Weighing::create([
            'aircraft_id' => $this->aircraft()->id,
            'kind' => SheetVariant::Glider->kind(),
            'sheet_variant' => SheetVariant::Glider,
            'undercarriage' => Undercarriage::TailwheelOneMain,
            'weighed_at' => now()->toDateString(),
        ]));
    }

    private function connection(): Connection
    {
        return Connection::create([
            'name' => 'Testverein '.Str::random(4),
            'username' => 'test',
            'password' => 'geheim',
            'app_key' => 'schluessel',
            'provides_identities' => false,
        ]);
    }

    private function partType(): PartType
    {
        return PartType::create([
            'name' => 'Niete '.Str::random(4),
            'classification' => PartClassification::StandardPart,
            'unit' => 'Stk',
        ]);
    }
}
