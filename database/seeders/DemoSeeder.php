<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Core\Access\AccessSetup;
use App\Core\Access\CoreRoles;
use App\Core\Access\PermissionRegistry;
use App\Core\Demo\DemoMode;
use App\Core\Models\Qualification;
use App\Core\Modules\ModuleManager;
use App\Core\Modules\ModuleRegistry;
use App\Core\Settings\Settings;
use App\Models\User;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * Der Datenbestand der Spielwiese.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * ER MUSS SPRECHEN. Eine leere Demo zeigt nichts -- wer sie öffnet, sieht
 * Formulare ohne Inhalt und weiss hinterher nicht, wofür das Programm gut ist.
 * Deshalb steht hier ein kleiner Verein, wie es ihn geben könnte: drei
 * Luftfahrzeuge (Segelflugzeug, Motorsegler, Flugzeug -- damit alle drei
 * Wägeblätter vorkommen), ein abgeschlossener Vorgang mit Befundbericht und
 * erteilter Freigabe, ein offener mit blockierendem Befund, eine Nachprüfung,
 * die demnächst abläuft, und ein Lager mit Losen, Mindestbeständen und einer
 * überfälligen Bestellung.
 *
 * ER MUSS EHRLICH SEIN. Erfundene Kennblattnummern wären die eine Sorte
 * Beispieldaten, die schadet: Sie sehen echt aus, und irgendwer schreibt sie
 * ab. Die Muster tragen deshalb ihre wirklichen Bezeichnungen und KEINE
 * Kennblattnummer -- was zugleich die Kennblattsuche vorführt, die man dafür
 * benutzt.
 *
 * ER LÄUFT ÜBER DIE AKTIONEN, nicht über Model::create. Nummernkreise,
 * Zustände, Unterschriften und Sperren entstehen dadurch so, wie sie im Betrieb
 * entstehen -- ein von Hand zusammengeschriebener Bestand hätte Zustände, die
 * es im echten Betrieb nie gibt, und die Demo führte etwas vor, das es nicht
 * gibt.
 * ─────────────────────────────────────────────────────────────────────────────
 */
final class DemoSeeder extends Seeder
{
    public function run(): void
    {
        app(AccessSetup::class)->run();

        $this->modules();

        /*
         * Erst die Module, dann die Rechte: Die Rechte der Module entstehen
         * mit ihnen. Beim zweiten Lauf von AccessSetup sind sie da und lassen
         * sich verteilen.
         */
        app(AccessSetup::class)->run();
        $this->rolePermissions();

        $this->settings();

        $konten = $this->accounts();

        /*
         * Ueber den Container geholt und die Konsole weitergereicht: Ohne
         * setCommand() schluckt ein Unterseeder seine Warnungen, und ein
         * halb gefuellter Bestand sieht aus wie ein voller.
         */
        foreach ([
            DemoFleetSeeder::class,
            DemoWarehouseSeeder::class,
            DemoWorkshopSeeder::class,
            DemoIdentitySeeder::class,
        ] as $seeder) {
            $unter = app($seeder);

            if ($this->command !== null) {
                $unter->setCommand($this->command);
            }

            $unter->run($konten);
        }
    }

    /**
     * Alles an. Eine Demo, in der die Hälfte der Module fehlt, zeigt die Hälfte
     * des Programms -- und die Modulverwaltung selbst ist eine der Funktionen,
     * die man ausprobieren soll: Abschalten geht, und der nächste Reset holt es
     * zurück.
     */
    private function modules(): void
    {
        $manager = app(ModuleManager::class);

        foreach (app(ModuleRegistry::class)->names() as $modul) {
            if (! $manager->canEnable($modul)->isRefused()) {
                $manager->enable($modul);
            }
        }

        $manager->forgetCache();
    }

    /**
     * Die Rechte auf die Rollen verteilen -- gruppenweise.
     *
     * ─────────────────────────────────────────────────────────────────────────
     * WARUM DAS HIER SEIN MUSS: Ab Werk hält nur der Administrator die Rechte
     * der Module. Das ist richtig so -- wer was darf, ist Sache des Vereins und
     * nicht des Programms. Eine Demo, in der alle ausser dem Administrator vor
     * verschlossenen Türen stehen, führt aber nichts vor.
     *
     * ÜBER GRUPPEN und nicht über einzelne Rechtenamen: Kommt ein Modul mit
     * einem neuen Recht, wandert es mit seiner Gruppe automatisch an die
     * passenden Rollen. Eine Liste einzelner Namen wäre nach dem nächsten
     * Feature stumm veraltet -- und das faellt in einer Demo niemandem auf.
     * ─────────────────────────────────────────────────────────────────────────
     */
    private function rolePermissions(): void
    {
        $nachRolle = [
            CoreRoles::WORKSHOP_MANAGER => [
                'core.people', 'fleet.aircraft', 'fleet.airworthiness', 'taskcards.work',
                'taskcards.report', 'warehouse.stock', 'warehouse.master_data',
                'directives', 'inspection', 'tooling', 'part66.logs',
            ],
            CoreRoles::CERTIFYING_STAFF => [
                'fleet.aircraft', 'fleet.airworthiness', 'taskcards.work', 'taskcards.certify',
                'taskcards.report', 'warehouse.stock', 'directives', 'inspection', 'tooling',
            ],
            CoreRoles::MECHANIC => [
                'fleet.aircraft', 'taskcards.work', 'taskcards.report',
                'warehouse.stock', 'directives', 'inspection', 'tooling',
            ],
            /*
             * Das Mitglied liest -- und darf melden. „Ein Befundbericht sollte
             * durch jeden P/O oder höher angelegt werden können": Genau dafür
             * gibt es taskcards.report als eigene Gruppe.
             */
            CoreRoles::MEMBER => ['taskcards.report'],
        ];

        $gruppen = [];

        foreach (app(PermissionRegistry::class)->active() as $definition) {
            $gruppen[$definition->group][] = $definition->name;
        }

        foreach ($nachRolle as $rolle => $ausgewaehlte) {
            $role = Role::query()->where('name', $rolle)->where('guard_name', 'web')->first();

            if ($role === null) {
                continue;
            }

            foreach ($ausgewaehlte as $gruppe) {
                foreach ($gruppen[$gruppe] ?? [] as $recht) {
                    if (! $role->hasPermissionTo($recht)) {
                        $role->givePermissionTo($recht);
                    }
                }
            }
        }

        /*
         * Einzelne Rechte quer zur Gruppe -- weil zwei Fälle es verlangen:
         *
         * BEFUNDE ERFASSEN steht im System in der Gruppe „certify", denn ein
         * Befund ist eine Aussage über die Lufttüchtigkeit. Die Werkstatt
         * schreibt sie trotzdem auf, und ein Verein verteilt das üblicherweise
         * so: melden und zurückstellen ja, abzeichnen nein. Genau diese
         * Aufteilung zeigt die Demo -- die Mechanikerin nimmt den Befundbericht
         * auf, unterschreiben kann sie nichts.
         *
         * NUR LESEN heisst wirklich nur lesen: Das Mitglied sieht Flotte,
         * Lager und Vorgänge, ändert aber nichts.
         */
        $einzeln = [
            CoreRoles::WORKSHOP_MANAGER => ['workorders.findings.record', 'workorders.findings.defer'],
            CoreRoles::MECHANIC => ['workorders.findings.record'],
            CoreRoles::MEMBER => ['fleet.view', 'stock.view', 'workorders.view', 'directives.view'],
        ];

        foreach ($einzeln as $rolle => $rechte) {
            $role = Role::query()->where('name', $rolle)->where('guard_name', 'web')->first();

            if ($role === null) {
                continue;
            }

            foreach ($rechte as $recht) {
                if (Permission::query()->where('name', $recht)->exists() && ! $role->hasPermissionTo($recht)) {
                    $role->givePermissionTo($recht);
                }
            }
        }
    }

    private function settings(): void
    {
        $settings = app(Settings::class);

        $settings->set('organisation.name', 'Luftsportverein Musterhausen e.V.');
        $settings->set('organisation.timezone', 'Europe/Berlin');
        $settings->applyToConfig();
    }

    /**
     * Die festen Konten -- Name, Rolle und die dazu passende Qualifikation.
     *
     * Vorgabe: „die dummy logins haben zu ihren tätigkeiten passende dummy
     * lizenzen." Das ist mehr als Zierde: Ohne Lizenz kann der
     * Freigabeberechtigte nichts abzeichnen, und der halbe Rundgang durch die
     * Demo endete an einer Fehlermeldung.
     *
     * @return array<string, User>
     */
    private function accounts(): array
    {
        $konten = [];

        foreach (DemoMode::ACCOUNTS as $anmeldung => $angaben) {
            $user = User::create([
                'name' => $angaben['name'],
                'email' => DemoMode::email($anmeldung),
                'password' => DemoMode::PASSWORD,
                'is_active' => true,
            ]);

            $user->assignRole($angaben['role']);

            $konten[$anmeldung] = $user->fresh();
        }

        // Die Part-66-Lizenz des Freigabeberechtigten -- ohne sie zeichnet er
        // nichts ab, und die Freigabe liesse sich nicht vorfuehren.
        Qualification::create([
            'user_id' => $konten['freigabeberechtigter']->id,
            'type' => Qualification::TYPE_PART66,
            'reference' => 'DE.66.DEMO.0815',
            'category' => 'B1',
            'categories' => ['B1.2'],
            'valid_from' => now()->subYears(3)->toDateString(),
            'valid_until' => now()->addYears(2)->toDateString(),
            'note' => 'Beispiellizenz der Demo -- frei erfunden.',
        ]);

        // Die Mechanikerin hat keine Lizenz, aber einen Schulungsnachweis:
        // Genau der Unterschied, den das System macht -- geschult ist nicht
        // freigabeberechtigt.
        Qualification::create([
            'user_id' => $konten['mechaniker']->id,
            'type' => Qualification::TYPE_TRAINING,
            'subject' => 'Kleben und Faserverbund',
            'reference' => 'SCH-2024-118',
            'issuer' => 'Beispiel-Schulungsbetrieb',
            'valid_from' => now()->subYears(2)->toDateString(),
            'note' => 'Beispielnachweis der Demo -- frei erfunden.',
        ]);

        return $konten;
    }
}
