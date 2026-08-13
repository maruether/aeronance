<?php

declare(strict_types=1);

namespace App\Providers;

use App\Core\Access\Authority;
use App\Core\Access\PermissionRegistry;
use App\Core\Console\BackupCommand;
use App\Core\Console\BreakGlassCommand;
use App\Core\Console\BreakGlassExpireCommand;
use App\Core\Console\BreakGlassRevokeCommand;
use App\Core\Console\MailTestCommand;
use App\Core\Console\RequirementsCommand;
use App\Core\Console\RestoreCommand;
use App\Core\Console\RetentionCommand;
use App\Core\Console\SyncAccessCommand;
use App\Core\Console\UpdateCheckCommand;
use App\Core\Contracts\AircraftDirectory;
use App\Core\Documents\ClamAvScanner;
use App\Core\Documents\ContentTypeVerifier;
use App\Core\Documents\DocumentIntake;
use App\Core\Documents\NullScanner;
use App\Core\Documents\VirusScanner;
use App\Core\Http\CertificateChainResolver;
use App\Core\Http\GuzzleFetcher;
use App\Core\Http\HttpFetcher;
use App\Core\Identity\IdentityProviderRegistry;
use App\Core\Listeners\SyncPermissionsOnModuleChange;
use App\Core\Modules\DependencyResolver;
use App\Core\Modules\Events\ModuleEnabled;
use App\Core\Modules\ModuleManager;
use App\Core\Modules\ModuleRegistry;
use App\Core\Settings\Settings;
use App\Modules\Directives\Airworthiness\OutstandingDirectives;
use App\Modules\Directives\Console\RefreshDirectivesCommand;
use App\Modules\Directives\Listeners\FetchDirectivesForNewType;
use App\Modules\Directives\Sources\Configured\ConfiguredSource;
use App\Modules\Directives\Sources\Configured\SpecRepository;
use App\Modules\Directives\Sources\CsvSource;
use App\Modules\Directives\Sources\ManualSource;
use App\Modules\Directives\Sources\Nfl\NflSource;
use App\Modules\Directives\Sources\SessionFetcher;
use App\Modules\Directives\Sources\SourceCredentials;
use App\Modules\Directives\Sources\SourceRegistry;
use App\Modules\Fleet\Airworthiness\AirworthinessCheck;
use App\Modules\Fleet\Events\AircraftTypeCreated;
use App\Modules\Fleet\Events\ComponentRemovedFromAircraft;
use App\Modules\Fleet\Listeners\FileReleaseAsAircraftDocument;
use App\Modules\Fleet\Listeners\RecordIssuedPartAsInstallation;
use App\Modules\Fleet\Support\FleetAircraftDirectory;
use App\Modules\Fleet\TypeCertificates\EasaSource;
use App\Modules\Fleet\TypeCertificates\Lba\LbaBlueBookSource;
use App\Modules\Fleet\TypeCertificates\TypeCertificateRegistry;
use App\Modules\Inspection\Listeners\OpenIncomingInspection;
use App\Modules\TaskCards\Airworthiness\OutstandingFindings;
use App\Modules\TaskCards\Events\ReleaseIssued;
use App\Modules\Vereinsflieger\Console\SyncCommand as VereinsfliegerSyncCommand;
use App\Modules\Warehouse\Console\ExpireStockCommand;
use App\Modules\Warehouse\Console\RemindOverdueOrdersCommand;
use App\Modules\Warehouse\Events\PartIssuedToAircraft;
use App\Modules\Warehouse\Events\StockReceived;
use App\Modules\Warehouse\Listeners\BookRemovedComponentIntoStock;
use AsyncAws\S3\S3Client;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\ServiceProvider;
use League\Flysystem\AsyncAwsS3\AsyncAwsS3Adapter;
use League\Flysystem\Filesystem as Flysystem;

/**
 * Wires the module system into the container.
 *
 * All three services are singletons: the registry reads the shipped list once,
 * and the manager caches the active set for the request instead of asking the
 * database on every isEnabled() call.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * HIER WIRD GESTECKT, NICHT GERECHNET.
 *
 * Diese Datei ist der Kompositions-Root: die EINE Stelle, die alle Module beim
 * Namen kennen muss, um sie zu registrieren und zu verdrahten. Genau deshalb
 * scannt ModuleBoundaryTest sie nicht -- ein Test, der die Steckleiste flaggt,
 * bräuchte eine Ausnahme für exakt diese Datei und prüfte dann nichts mehr.
 *
 * Die Grenze, die stattdessen HIER gilt und die kein grep prüfen kann: Was
 * eine fachliche Entscheidung trifft, gehört ins Modul, nicht hierher. Wer an
 * dieser Datei mehr tut, als einen Baustein einzuhängen -- eine Bedingung
 * formuliert, einen Wert ausrechnet, eine Regel auslegt --, verschiebt das in
 * das Modul, dem die Regel gehört, und hängt hier nur das Ergebnis ein.
 * ─────────────────────────────────────────────────────────────────────────────
 */
final class ModuleServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        /*
         * ─────────────────────────────────────────────────────────────────────
         * DIE EINSTELLUNGEN DES VEREINS UEBER DIE KONFIGURATION LEGEN.
         *
         * Damit bleibt config() die einzige Leseschnittstelle im uebrigen Code:
         * BackupCommand, RetentionCommand, die Disks und der Virenscanner
         * muessen nicht wissen, ob ein Wert aus der Datenbank, der Umgebung oder
         * der Vorgabe kommt. Ohne diese eine Zeile haette der Umbau jeden
         * Aufrufer angefasst.
         *
         * Vor der ersten Migration gibt es die Tabelle noch nicht; Settings
         * faellt dann still auf Umgebung und Vorgabe zurueck -- siehe dort. Ein
         * Startfehler hier machte eine frische Installation unbedienbar, bevor
         * sie eingerichtet ist.
         *
         * IN register() UND NICHT IN boot(), und das ist eine Korrektur:
         * Filament baut sein Panel samt Routen im register() seines eigenen
         * Providers. Laravel ruft ERST alle register() auf, dann alle boot()
         * -- lag der Aufruf im boot(), sah das Panel die Einstellungen nie.
         *
         * Aufgefallen an einer Kleinigkeit mit grosser Reichweite: Der
         * „Passwort vergessen"-Link soll nur erscheinen, wenn Mail wirklich
         * rausgeht. Er erschien nie -- weil beim Panel-Bau noch die rohe
         * .env galt und nicht der eingetragene SMTP-Zugang. Das betraf JEDE
         * Panel-Entscheidung, die von einer Einstellung abhaengt, nicht nur
         * diese eine.
         * ─────────────────────────────────────────────────────────────────────
         */
        $this->app->make(Settings::class)->applyToConfig();

        $this->app->singleton(ModuleRegistry::class, static fn (Application $app): ModuleRegistry => new ModuleRegistry(
            $app['config']->get('aeronance.modules', []),
        ));

        $this->app->singleton(DependencyResolver::class, static fn (Application $app): DependencyResolver => new DependencyResolver(
            $app->make(ModuleRegistry::class),
        ));

        $this->app->singleton(ModuleManager::class, static fn (Application $app): ModuleManager => new ModuleManager(
            $app->make(ModuleRegistry::class),
            $app->make(DependencyResolver::class),
        ));

        $this->app->singleton(PermissionRegistry::class, static fn (Application $app): PermissionRegistry => new PermissionRegistry(
            $app->make(ModuleManager::class),
        ));

        $this->app->singleton(Authority::class, static fn (): Authority => new Authority);

        /*
         * The scanner is chosen once, here, from configuration -- so nothing
         * downstream ever has to ask "is scanning on" before deciding what to
         * do. It always has a scanner; sometimes that scanner is the one that
         * honestly reports it did not look.
         */
        $this->app->singleton(VirusScanner::class, static function (): VirusScanner {
            if (config('aeronance.documents.scanner') !== 'clamav') {
                return new NullScanner;
            }

            return new ClamAvScanner(
                socket: config('aeronance.documents.clamav.socket'),
                host: config('aeronance.documents.clamav.host'),
                port: (int) config('aeronance.documents.clamav.port', 3310),
                timeout: (int) config('aeronance.documents.clamav.timeout', 30),
                failClosed: (bool) config('aeronance.documents.clamav.fail_closed', true),
            );
        });

        /*
         * The airworthiness check is a singleton because modules register with
         * it during boot -- a fresh instance per resolution would collect an
         * empty list and answer "nothing found", which is the worst possible
         * wrong answer here.
         */
        $this->app->singleton(AirworthinessCheck::class, static function ($app): AirworthinessCheck {
            $check = new AirworthinessCheck($app);

            /*
             * The contributors are registered HERE, not from each module's
             * Filament plugin.
             *
             * ─────────────────────────────────────────────────────────────────
             * They used to be, and it meant the airworthiness list was complete
             * for a browser request and empty for everything else -- a queued
             * job, an artisan command, a test. The same mistake as the directive
             * sources, found the same way: a test asked an aircraft for its open
             * items and got none, because no panel had booted to fill the
             * registry.
             *
             * What an aircraft still has outstanding is not a panel's business.
             *
             * Each contributor asks the ModuleManager itself before answering,
             * so registering them unconditionally is safe -- exactly like the
             * module migrations (D1) and the module commands.
             * ─────────────────────────────────────────────────────────────────
             */
            $check->register(OutstandingFindings::class);
            $check->register(OutstandingDirectives::class);

            return $check;
        });

        /*
         * The directive source registry, built here rather than filled in from
         * the Filament plugin.
         *
         * ─────────────────────────────────────────────────────────────────────
         * It used to be an empty singleton that DirectivesModule::boot() filled.
         * That works for exactly one caller -- a browser request, which boots a
         * panel. Everything else got an empty registry: the scheduled refresh,
         * any queued job, an artisan command. The symptom was
         * "Keine abrufbare Quelle" from a console that could see the YAML files
         * perfectly well.
         *
         * Sources are not a panel concern. Building them in the container makes
         * them available wherever the application runs, and the panel simply
         * reads the registry like everybody else.
         * ─────────────────────────────────────────────────────────────────────
         */
        $this->app->singleton(SourceRegistry::class, static function ($app): SourceRegistry {
            $registry = new SourceRegistry;

            // The two that always exist. A manufacturer needs neither.
            $registry->register(new ManualSource);
            $registry->register(new CsvSource);

            /*
             * Manufacturer adapters, from configuration.
             *
             * Vorgabe: "bau das Modul so das ich den abrufmechanismus pro
             * hersteller per config file einspielen kann." So no manufacturer is
             * named in PHP anywhere -- each is a YAML in
             * resources/directive-sources/ (shipped) or
             * storage/app/directive-sources/ (the club's own, untouched by
             * updates).
             *
             * A broken spec is skipped rather than fatal: one manufacturer's
             * file must not take the others down. SpecRepository::problems()
             * reports what was skipped and why, because a silently missing
             * source looks exactly like a manufacturer with nothing new.
             */
            foreach ($app->make(SpecRepository::class)->all() as $spec) {
                /*
                 * A source that needs a form login gets its own fetcher, which
                 * reads the login form, answers it and keeps the session. One
                 * per spec, so two gated manufacturers never share a session --
                 * and a source without a login is untouched by any of it.
                 */
                $registry->register(new ConfiguredSource(
                    $spec,
                    $spec->needsLogin()
                    ? new SessionFetcher($spec, $app->make(CertificateChainResolver::class))
                    : $app->make(HttpFetcher::class),
                ));
            }

            /*
             * The gazette is not a manufacturer file: a JavaScript grid whose
             * rows point at PDFs, behind a session. See NflClient.
             */
            $registry->register(new NflSource);

            return $registry;
        });

        /*
         * Die Kennzeichen-Naht des Kerns. Bedingungslos gebunden wie die
         * Airworthiness-Beitraege: Die Implementierung fragt selbst beim
         * ModuleManager nach und antwortet leer, wenn die Flotte aus ist --
         * der Kern faellt dann auf Freitext zurueck (siehe AircraftDirectory).
         */
        $this->app->bind(AircraftDirectory::class, FleetAircraftDirectory::class);

        // The network seam a manufacturer adapter reaches through. Bound as an
        // interface so a test can hand over saved pages -- the parser is the part
        // that breaks, and it must be testable without the manufacturer's server.
        $this->app->bind(
            HttpFetcher::class,
            // With the chain resolver, so an UNGATED manufacturer with an
            // incomplete chain is repaired exactly like a gated one. See
            // GuzzleFetcher -- C.E.A.P.R. is the source that needed it.
            static fn ($app): GuzzleFetcher => new GuzzleFetcher(
                $app->make(CertificateChainResolver::class),
            ),
        );

        // Resolves a gated source's login: environment first, then whatever a
        // club stored through the panel. Singleton so one request does not read
        // the same encrypted row three times.
        // Ein Mal je Anfrage gelesen, nicht bei jedem Zugriff.
        /*
         * Die Naht fuer externe Identitaeten. Leer, solange kein Connector
         * eingetragen ist -- und genau das ist die Anforderung, dass der Kern
         * ohne jedes Modul lauffaehig sein muss: dann bleibt das lokale Login.
         */
        $this->app->singleton(IdentityProviderRegistry::class);

        $this->app->singleton(Settings::class);

        $this->app->singleton(SourceCredentials::class);

        /*
         * Completes an incomplete TLS chain by AIA-chasing, cached. A manufacturer
         * that ships only its server certificate (Schempp-Hirth does) would
         * otherwise need an intermediate dropped in by hand on every install.
         */
        $this->app->singleton(
            CertificateChainResolver::class,
            static fn (): CertificateChainResolver => new CertificateChainResolver(storage_path('app/ca/auto')),
        );

        /*
         * Manufacturer specs: shipped ones from the release, the club's own from
         * storage -- which CLAUDE.md already promises updates do not touch, and
         * which is exactly why local files go there.
         */
        $this->app->singleton(SpecRepository::class, static fn (): SpecRepository => new SpecRepository(
            resource_path('directive-sources'),
            storage_path('app/directive-sources'),
        ));

        // Type-certificate authorities, same shape as the directive sources.
        $this->app->singleton(TypeCertificateRegistry::class, static function ($app): TypeCertificateRegistry {
            $registry = new TypeCertificateRegistry;
            $registry->register(new EasaSource($app->make(HttpFetcher::class)));

            /*
             * The LBA's Blaues Buch. Registered after EASA but the better source
             * of the two: it lists the German Kennblatt number and the EASA
             * reference side by side, where the EASA library gives only the
             * second, one type per request. They are complementary -- the
             * Blaues Buch has no data sheet to link, and EASA does.
             */
            $registry->register(new LbaBlueBookSource($app->make(HttpFetcher::class)));

            return $registry;
        });

        $this->app->singleton(DocumentIntake::class, static fn ($app): DocumentIntake => new DocumentIntake(
            new ContentTypeVerifier,
            $app->make(VirusScanner::class),
            (int) config('aeronance.documents.max_size_mb', 20),
        ));
    }

    public function boot(): void
    {
        $this->loadModuleMigrations();

        Event::listen(ModuleEnabled::class, SyncPermissionsOnModuleChange::class);

        /*
         * The warehouse -> fleet handover.
         *
         * Registered unconditionally, like module migrations and commands (D1).
         * The listener asks the ModuleManager itself before doing anything, so
         * this line is inert in an installation that runs the warehouse alone --
         * and registering by enabled state would mean the wiring changes
         * whenever somebody toggles a module, which is the sort of thing that
         * works until it does not.
         */
        Event::listen(PartIssuedToAircraft::class, RecordIssuedPartAsInstallation::class);

        // And the return leg: a part taken off an aircraft lands on the shelf,
        // through the warehouse's own removal action so that every rule it
        // already enforces applies unchanged.

        Event::listen(ComponentRemovedFromAircraft::class, BookRemovedComponentIntoStock::class);

        /*
         * Goods in -> incoming inspection.
         *
         * Same shape as above and for the same reason: registered always,
         * inert unless the module is on. It has to be wired HERE rather than
         * in InspectionModule::boot(), because a Filament plugin boots for a
         * browser request and stock also gets booked in from the console --
         * an arrival that opened no inspection because it happened in a
         * command would be exactly the silent gap the module is against.
         */
        Event::listen(StockReceived::class, OpenIncomingInspection::class);

        // Und die Bescheinigung in die Lebenslaufakte: Eine erteilte Freigabe
        // (CRS) wird als Dokumentverweis am Luftfahrzeug abgelegt -- gleiche
        // Bauart, gleicher Grund, dritter Anwendungsfall.
        Event::listen(ReleaseIssued::class, FileReleaseAsAircraftDocument::class);

        // Ein neues Muster zieht seine Herstellerlisten an -- gebunden an den
        // Hersteller, nicht als Rundruf ueber alle Quellen.
        Event::listen(
            AircraftTypeCreated::class,
            FetchDirectivesForNewType::class,
        );

        /*
         * ─────────────────────────────────────────────────────────────────────
         * S3 -- ABER OHNE DAS AWS-SDK.
         *
         * Laravels eingebauter s3-Treiber verlangt league/flysystem-aws-s3-v3,
         * und das zieht aws/aws-sdk-php nach. Gemessen: 63 MB und 3483 Dateien,
         * vendor waechst von 152 auf 215 MB. Fuer ein Tarball, das vendor/ fertig
         * mitbringt, ist das der Loewenanteil des Downloads -- fuer einen
         * Ablageort, den viele Vereine nie benutzen.
         *
         * async-aws leistet dasselbe fuer 2,3 MB. Laravel kennt es nur nicht von
         * selbst, deshalb diese Anmeldung. Vorgabe: "s3 waere ein nice to have
         * muss aber echt nicht sein wenn es overhead erzeugt" -- so erzeugt es
         * keinen.
         *
         * Der Treiber heisst bewusst async-s3 und nicht s3: Wer das AWS-SDK
         * spaeter doch braucht, soll den eingebauten Treiber unveraendert
         * vorfinden.
         * ─────────────────────────────────────────────────────────────────────
         */
        Storage::extend('async-s3', static function ($app, array $config): Filesystem {
            $client = new S3Client(array_filter([
                'accessKeyId' => $config['key'] ?? null,
                'accessKeySecret' => $config['secret'] ?? null,
                'region' => $config['region'] ?? null,
                'endpoint' => $config['endpoint'] ?? null,
                'pathStyleEndpoint' => $config['use_path_style_endpoint'] ?? null,
            ], static fn ($v): bool => $v !== null));

            return new FilesystemAdapter(
                new Flysystem(
                    $adapter = new AsyncAwsS3Adapter($client, (string) ($config['bucket'] ?? '')),
                    $config,
                ),
                $adapter,
                $config,
            );
        });

        if ($this->app->runningInConsole()) {
            $this->commands([
                SyncAccessCommand::class,
                BreakGlassCommand::class,
                BreakGlassExpireCommand::class,
                BreakGlassRevokeCommand::class,
                RetentionCommand::class,
                RequirementsCommand::class,
                BackupCommand::class,
                RestoreCommand::class,
                MailTestCommand::class,
                UpdateCheckCommand::class,
                RemindOverdueOrdersCommand::class,

                // Module commands are registered unconditionally, exactly like
                // module migrations (D1): the command exists whether or not the
                // module is on, and asks the ModuleManager itself before doing
                // anything. Registering by enabled state instead would mean the
                // scheduler entry breaks the moment somebody switches a module
                // off, which is a worse failure than a command that politely
                // declines.
                ExpireStockCommand::class,
                RefreshDirectivesCommand::class,
                VereinsfliegerSyncCommand::class,
            ]);
        }
    }

    /**
     * Registers the migrations of EVERY shipped module -- active or not.
     *
     * Decision D1. All tables come into being at installation, so switching a
     * module on later is a flag rather than a maintenance window with a
     * migration run. There is one migration state instead of one per
     * combination of modules, and it avoids the nastiest failure mode: a module
     * enabled at version 1.2 whose migrations from 1.0 and 1.1 never ran.
     *
     * The price is empty tables for modules a club does not use, which at club
     * scale is nothing.
     *
     * The path follows from the module class, so a module needs no service
     * provider of its own just to be found.
     */
    private function loadModuleMigrations(): void
    {
        foreach ($this->app->make(ModuleRegistry::class)->all() as $module) {
            $directory = dirname((new \ReflectionClass($module))->getFileName()).'/Database/Migrations';

            if (is_dir($directory)) {
                $this->loadMigrationsFrom($directory);
            }
        }
    }
}
