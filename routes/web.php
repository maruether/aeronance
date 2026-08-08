<?php

declare(strict_types=1);

use App\Core\Http\LogoController;
use App\Core\Setup\SetupController;
use App\Http\Middleware\BlockSetupWhenInstalled;
use App\Http\Middleware\RedirectToSetupWhenNotInstalled;
use App\Http\Middleware\RequireSetupAuthority;
use App\Modules\Directives\Http\OverviewController;
use App\Modules\Fleet\Http\AircraftRecordController;
use App\Modules\Part66\Http\ExperienceLogController;
use App\Modules\TaskCards\Http\ReleaseController;
use App\Modules\Warehouse\Http\CountingListController;
use App\Modules\Warehouse\Http\DocumentController;
use App\Modules\Warehouse\Http\InventoryReportController;
use App\Modules\Warehouse\Http\LabelController;
use App\Modules\Warehouse\Http\QuarantineTagController;
use Illuminate\Support\Facades\Route;

/*
 * The setup wizard exists only while the installation is unfinished.
 *
 * Two guards, doing different jobs. BlockSetupWhenInstalled removes the whole
 * thing once the marker is written. RequireSetupAuthority protects the steps
 * that change something for the case where the marker went missing on a live
 * system: once an administrator exists, only that administrator may continue.
 */
Route::middleware(BlockSetupWhenInstalled::class)
    ->prefix('setup')
    ->name('setup.')
    ->group(function (): void {
        // Creating the administrator guards itself: it refuses outright once
        // one exists, which is the case RequireSetupAuthority cannot help with
        // because nobody is logged in yet at that point.
        Route::post('/administrator', [SetupController::class, 'createAdministrator'])->name('administrator');

        Route::middleware(RequireSetupAuthority::class)->group(function (): void {
            /*
             * Index und Migrationen standen eine Zeit lang AUSSERHALB dieser
             * Gruppe -- "lesend bzw. idempotent". Beides stimmt, nur ist auf
             * einem Livesystem mit verlorenem Marker schon der Anblick des
             * Assistenten Auskunft, und fremd ausgeloeste Migrationen sind
             * kein Leserecht. Fuer die frische Installation aendert die
             * Gruppe nichts: Solange kein Administrator existiert, laesst
             * RequireSetupAuthority alles durch.
             */
            Route::get('/', [SetupController::class, 'index'])->name('index');
            Route::post('/datenbank', [SetupController::class, 'configureDatabase'])->name('database');
            Route::post('/migrate', [SetupController::class, 'migrate'])->name('migrate');

            Route::post('/organisation', [SetupController::class, 'configureOrganisation'])->name('organisation');
            Route::post('/module', [SetupController::class, 'selectModules'])->name('modules');
            Route::post('/fertig', [SetupController::class, 'finish'])->name('finish');
        });
    });

Route::middleware(RedirectToSetupWhenNotInstalled::class)->get('/', function () {
    return redirect('/verwaltung');
});

/*
 * Nachweisdokumente.
 *
 * Liegen auf einer privaten Disk ausserhalb des Webroots; dies ist der einzige
 * Weg an sie heran. Die Pruefungen stehen im Controller, damit sie nicht in der
 * Routendefinition uebersehen werden koennen.
 */
Route::middleware(['web', 'auth'])
    ->get('/nachweise/{lot}/{media}', DocumentController::class)
    ->name('warehouse.document');

/*
 * Sperrzettel zum Ausdrucken.
 *
 * Bewusst HTML statt PDF: die uebliche PDF-Bibliothek ist hier doppelt
 * blockiert -- ihre ersten beiden Hauptversionen tragen eine lange Liste von
 * Sicherheitshinweisen, die dritte laeuft noch nicht auf PHP 8.5. Der Browser
 * druckt, der Kalibrierbogen faengt eine skalierende Druckereinstellung ab.
 */
Route::middleware(['web', 'auth'])
    ->prefix('sperrzettel')
    ->name('warehouse.tag.')
    ->group(function (): void {
        Route::get('/bogen', [QuarantineTagController::class, 'sheet'])->name('sheet');
        Route::get('/kalibrierung', [QuarantineTagController::class, 'calibration'])->name('calibration');
        Route::get('/{change}', [QuarantineTagController::class, 'single'])->name('single');
    });

/*
 * Losaufkleber -- das Etikett am Teil.
 *
 * Zwei Betriebsarten aus derselben Sicht: `roll` fuer Etikettendrucker, bei
 * denen die Seite das Etikett IST (Brother QL, Zebra, Dymo), und `sheet` fuer
 * A4-Etikettenboegen. Welche, sagt der Parameter `layout`.
 *
 * Anders als beim Sperrzettel gibt es kein "alles noch nicht Gedruckte": Ein
 * Etikett wird nachgedruckt, wenn es kaputtgeht oder ein Los umgepackt wird.
 * Die Lose stehen deshalb ausdruecklich in `lots`.
 */
Route::middleware(['web', 'auth'])
    ->prefix('losaufkleber')
    ->name('warehouse.label.')
    ->group(function (): void {
        Route::get('/', [LabelController::class, 'lots'])->name('print');
        Route::get('/lagerorte', [LabelController::class, 'locations'])->name('locations');
        Route::get('/kalibrierung', [LabelController::class, 'calibration'])->name('calibration');
    });

/*
 * Zaehlliste zum Ausdrucken -- der Zettel, mit dem man tatsaechlich durchs
 * Lager geht. Sortiert nach Lagerort, weil das die Reihenfolge des Rundgangs
 * ist.
 */
Route::middleware(['web', 'auth'])
    ->get('/zaehlliste', CountingListController::class)
    ->name('warehouse.counting-list');

/*
 * Inventurbericht zum Stichtag.
 *
 * Die Frage, die der Vorgaenger nicht beantworten konnte: Was war am 31.12. da?
 * Weil der Bestand die Summe der Bewegungen ist und nie ueberschrieben wird,
 * ist die Zahl exakt berechenbar statt geschaetzt.
 */
Route::middleware(['web', 'auth'])
    ->get('/inventurbericht', InventoryReportController::class)
    ->name('warehouse.inventory-report');

/*
 * Luftfahrzeug-Formulare zum Ausdrucken.
 *
 * Zwei Sichten auf dieselben Zeilen -- die BWLV trennt sie nur, weil Papier das
 * nicht anders kann. Pruefungen im Controller, damit sie in der Routendefinition
 * nicht uebersehen werden koennen.
 */
Route::middleware(['web', 'auth'])->group(function (): void {
    Route::get('/luftfahrzeug/{aircraft}/ausruestungsverzeichnis', [AircraftRecordController::class, 'equipment'])
        ->name('fleet.equipment-list');

    Route::get('/luftfahrzeug/{aircraft}/betriebszeiten', [AircraftRecordController::class, 'operatingTimes'])
        ->name('fleet.operating-times');

    Route::get('/waegung/{weighing}', [AircraftRecordController::class, 'weighing'])
        ->name('fleet.weighing');

    /*
     * Erfahrungsnachweis nach Part-66.
     *
     * Das eigene Logbuch darf jeder abrufen; fremde brauchen eine Berechtigung.
     * Die Pruefung steht im Controller, damit sie in der Routendefinition nicht
     * uebersehen werden kann.
     */
    Route::get('/logbuch', ExperienceLogController::class)->name('part66.log');

    // Freigabebescheinigung als Papier fuer die Bordunterlagen.
    Route::get('/freigabe/{release}', ReleaseController::class)->name('taskcards.release');

    // LTA/TM-Uebersicht eines Luftfahrzeugs als Papier fuer die Bordunterlagen.
    Route::get('/lta/{aircraft}', OverviewController::class)->name('directives.overview');
});

/*
 * Das Logo der Organisation.
 *
 * Ohne Anmeldung, weil es auf der Anmeldeseite steht -- siehe LogoController.
 * Ueber eine Route statt aus public/, damit es in allen drei
 * Auslieferungskanaelen gleich funktioniert und niemand storage:link vergessen
 * kann.
 */
Route::get('/logo', LogoController::class)->name('organisation.logo');
