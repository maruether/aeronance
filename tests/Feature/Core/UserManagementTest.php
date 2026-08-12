<?php

declare(strict_types=1);

namespace Tests\Feature\Core;

use App\Core\Access\AccessSetup;
use App\Core\Access\CorePermissions;
use App\Core\Access\CoreRoles;
use App\Core\Access\PermissionRegistry;
use App\Core\Filament\Auth\EditProfile;
use App\Core\Filament\Resources\Roles\Pages\EditRole;
use App\Core\Filament\Resources\Roles\RoleResource;
use App\Core\Filament\Resources\Users\Pages\CreateUser;
use App\Core\Filament\Resources\Users\Pages\EditUser;
use App\Core\Filament\Resources\Users\RelationManagers\QualificationsRelationManager;
use App\Core\Filament\Resources\Users\UserResource;
use App\Core\Identity\ExternalIdentity;
use App\Core\Models\Qualification;
use App\Core\Modules\ModuleManager;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Administering people.
 *
 * Without these screens the E8 mechanism is unreachable: qualifications could
 * be modelled but never entered, so nobody could ever certify anything.
 */
final class UserManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        app(AccessSetup::class)->run();
    }

    #[Test]
    public function an_administrator_can_create_an_account(): void
    {
        $this->actingAs($this->administrator());

        Livewire::test(CreateUser::class)
            ->fillForm([
                'name' => 'Neue Person',
                'email' => 'neu@example.org',
                'password' => 'einhinreichendlangespasswort1',
                'is_active' => true,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $user = User::where('email', 'neu@example.org')->sole();
        $this->assertTrue($user->is_active);
        $this->assertTrue(Hash::check('einhinreichendlangespasswort1', $user->password));
    }

    #[Test]
    public function leaving_the_password_blank_keeps_the_current_one(): void
    {
        $admin = $this->administrator();
        $user = User::factory()->create(['password' => Hash::make('altespasswort123')]);

        $this->actingAs($admin);

        Livewire::test(EditUser::class, ['record' => $user->getKey()])
            ->fillForm(['name' => 'Neuer Name', 'password' => ''])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('Neuer Name', $user->fresh()->name);
        $this->assertTrue(
            Hash::check('altespasswort123', $user->fresh()->password),
            'An empty password field must not clear the password.',
        );
    }

    #[Test]
    public function a_weak_password_is_rejected(): void
    {
        $this->actingAs($this->administrator());

        Livewire::test(CreateUser::class)
            ->fillForm([
                'name' => 'Person',
                'email' => 'schwach@example.org',
                'password' => 'kurz',
            ])
            ->call('create')
            ->assertHasFormErrors(['password']);
    }

    #[Test]
    public function accounts_cannot_be_deleted(): void
    {
        // A member who leaves keeps their trace in the records; their name may
        // appear in a release that has to stay readable.
        $user = User::factory()->create();

        $this->actingAs($this->administrator());

        $this->assertFalse(UserResource::canDelete($user));
    }

    #[Test]
    public function viewing_and_managing_are_separate_permissions(): void
    {
        $viewer = User::factory()->create(['is_active' => true]);
        $viewer->givePermissionTo(CorePermissions::USERS_VIEW);

        $this->actingAs($viewer->fresh());

        $this->assertTrue(UserResource::canViewAny());
        $this->assertFalse(UserResource::canCreate());
    }

    #[Test]
    public function nobody_edits_an_account_mightier_than_their_own(): void
    {
        // The escalation this closes: the form carries a password field, so
        // "may manage users" once meant "may set an administrator's password
        // and sign in as them". Managing users must never hand out rights the
        // manager's own role deliberately lacks.
        $verwalter = User::factory()->create(['is_active' => true]);
        $verwalter->givePermissionTo(CorePermissions::USERS_MANAGE);

        $admin = User::factory()->create(['is_active' => true]);
        $admin->assignRole(CoreRoles::ADMIN);

        $this->actingAs($verwalter->fresh());

        $this->assertFalse(
            UserResource::canEdit($admin->fresh()),
            'A user manager must not be able to edit an administrator.',
        );

        // An ordinary member holds nothing the manager lacks -- editable.
        $mitglied = User::factory()->create(['is_active' => true]);

        $this->assertTrue(UserResource::canEdit($mitglied->fresh()));
    }

    #[Test]
    public function an_administrator_still_edits_another_administrator(): void
    {
        // Equal standing is allowed on purpose: otherwise no administrator
        // could maintain the other one's account.
        $einer = $this->administrator();
        $anderer = $this->administrator();

        $this->actingAs($einer);

        $this->assertTrue(UserResource::canEdit($anderer->fresh()));
    }

    #[Test]
    public function a_qualification_can_be_recorded_and_takes_effect(): void
    {
        // The whole point of the screen: before this, nobody could certify.
        $admin = $this->administrator();
        $mechanic = User::factory()->create(['is_active' => true]);

        $this->actingAs($admin);

        Qualification::create([
            'user_id' => $mechanic->id,
            'type' => Qualification::TYPE_PART66,
            'reference' => 'DE.66.12345',
            'category' => 'B1.2',
            'valid_from' => now()->subMonth()->toDateString(),
        ]);

        $this->assertCount(1, $mechanic->fresh()->validQualifications()->get());
    }

    /**
     * Das Formular OEFFNET sich auch -- nicht nur das Modell funktioniert.
     *
     * Auf test.aeronance.de starb genau dieser Dialog mit "Class
     * SpatieMediaLibraryFileUpload not found": Das Filament-Plugin zur
     * Medienbibliothek stand nie in composer.json, und kein Test hat das
     * Formular je gemountet -- der Test oben legt die Qualifikation direkt
     * am Modell an und blieb gruen.
     */
    #[Test]
    public function the_qualification_form_actually_opens(): void
    {
        $this->actingAs($this->administrator());

        $mechanic = User::factory()->create(['is_active' => true]);

        Livewire::test(QualificationsRelationManager::class, [
            'ownerRecord' => $mechanic,
            'pageClass' => EditUser::class,
        ])
            ->assertSuccessful()
            ->mountAction('create')
            ->assertSee(__('qualifications.field.reference'));
    }

    #[Test]
    public function a_pilot_owner_authorisation_needs_an_aircraft(): void
    {
        // It is valid for the one aircraft the person is entered against, not
        // in general -- which is the property that makes qualifications a
        // separate concept from roles.
        $mechanic = User::factory()->create(['is_active' => true]);

        $qualification = Qualification::create([
            'user_id' => $mechanic->id,
            'type' => Qualification::TYPE_PILOT_OWNER,
            'reference' => 'PO-2026-7',
            'scope' => 'D-KABC',
            'valid_from' => now()->subMonth()->toDateString(),
        ]);

        $this->assertSame('D-KABC', $qualification->scope);
        $this->assertTrue($qualification->isValidOn());
    }

    #[Test]
    public function the_role_editor_only_offers_permissions_of_active_modules(): void
    {
        $this->actingAs($this->administrator());

        $registry = app(PermissionRegistry::class);
        $withoutModules = array_keys($registry->grouped());

        $this->assertContains('core.people', $withoutModules);
        $this->assertNotContains('warehouse.stock', $withoutModules);

        app(ModuleManager::class)->enable('warehouse');
        app(ModuleManager::class)->forgetCache();
        $this->app->forgetInstance(PermissionRegistry::class);

        $withModules = array_keys(app(PermissionRegistry::class)->grouped());

        $this->assertContains('warehouse.stock', $withModules);
    }

    /**
     * Haken in ZWEI Gruppen, ein Speichern, beide sitzen.
     *
     * ─────────────────────────────────────────────────────────────────────────
     * Der Fall von test.aeronance.de: Alle Checkbox-Listen des Rollen-Editors
     * hiessen "permissions". Ihre Zustaende ueberschrieben einander, validiert
     * wurde gegen die Optionen der LETZTEN Gruppe -- ein Administrator, der in
     * einer anderen Gruppe einen Haken setzte, bekam "validation.in" und konnte
     * keine einzige Berechtigung vergeben. Kein Test hier hat je WIRKLICH
     * gespeichert; alle prueften nur Optionen und Labels.
     * ─────────────────────────────────────────────────────────────────────────
     */
    #[Test]
    public function the_role_editor_saves_checks_across_groups(): void
    {
        $this->actingAs($this->administrator());

        $role = Role::where('name', CoreRoles::MEMBER)->sole();

        $people = Permission::where('name', CorePermissions::USERS_VIEW)->sole();
        $system = Permission::where('name', CorePermissions::AUDIT_VIEW)->sole();

        Livewire::test(EditRole::class, ['record' => $role->getKey()])
            ->fillForm([
                'permissions__core__people' => [$people->id],
                'permissions__core__system' => [$system->id],
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $role->refresh();

        $this->assertTrue($role->hasPermissionTo(CorePermissions::USERS_VIEW));
        $this->assertTrue(
            $role->hasPermissionTo(CorePermissions::AUDIT_VIEW),
            'Der Haken aus der zweiten Gruppe muss das Speichern ueberleben -- '
            .'frueher gewann die letzte Liste und trug alle anderen aus.',
        );
    }

    #[Test]
    public function roles_cannot_be_deleted_through_the_interface(): void
    {
        // Deleting one silently strips its permissions from everybody who held
        // it, with no trace of what they used to be able to do.
        $this->actingAs($this->administrator());

        $role = Role::where('name', CoreRoles::MECHANIC)->sole();

        $this->assertFalse(RoleResource::canDelete($role));
    }

    #[Test]
    public function permission_labels_survive_the_dotted_names(): void
    {
        // "stock.quarantine" is a permission in its own right AND the prefix of
        // "stock.quarantine.certify". A nested language file cannot hold both.
        $labels = (array) trans('permissions.label');

        $this->assertSame('Vorsorglich sperren', $labels['stock.quarantine']);
        $this->assertSame('Zustand feststellen und freigeben', $labels['stock.quarantine.certify']);
    }

    /**
     * JEDES Recht jedes Moduls hat Label und Gruppentitel.
     *
     * Feldtest: "Es gibt noch einige berechtigungen ohne namen bei denen der
     * key angezeigt wird" -- die Sprachdatei endete bei der Lager-Aera, 26
     * Rechte aus fuenf spaeteren Modulen standen als rohe Schluessel im
     * Rollen-Editor. Die Schleife statt einer Stichprobe, aus demselben Grund
     * wie beim Einstellungs-Gruppen-Test: Ein Mensch sieht so etwas sofort,
     * ein Stichproben-Test nie.
     */
    #[Test]
    public function every_permission_of_every_module_has_a_label(): void
    {
        $labels = (array) trans('permissions.label');
        $definitionen = CorePermissions::all();

        foreach (glob(app_path('Modules/*/*Module.php')) as $datei) {
            $klasse = 'App\\Modules\\'.basename(dirname($datei)).'\\'.basename($datei, '.php');
            $modul = new $klasse;
            $definitionen = [...$definitionen, ...$modul->permissions()];
        }

        $this->assertNotEmpty($definitionen);

        foreach ($definitionen as $definition) {
            $this->assertArrayHasKey(
                $definition->name,
                $labels,
                sprintf('Dem Recht "%s" fehlt das Label in lang/de/permissions.php.', $definition->name),
            );

            // Flach wie in RoleForm -- Gruppennamen tragen Punkte, __() taugt
            // dafuer nicht (genau so fiel auf, dass die Titel nie ankamen).
            $this->assertArrayHasKey(
                $definition->group,
                (array) trans('permissions.group'),
                sprintf('Der Gruppe "%s" fehlt der Titel in lang/de/permissions.php.', $definition->group),
            );
        }
    }

    // ── Konten aus einem Provider ────────────────────────────────────────────

    /**
     * Was der Provider führt, lässt sich hier nicht ändern.
     *
     * ─────────────────────────────────────────────────────────────────────────
     * Vorgabe: „die über einen provider kommen dürfen nur angezeigt, aber nicht
     * verändert werden."
     *
     * Der Test schickt das Formular MIT geänderten Werten ab — so, wie es ein
     * manipuliertes Formular täte, in dem jemand das `disabled` entfernt hat.
     * Dass die Werte trotzdem stehen bleiben, ist der eigentliche Punkt: Die
     * Sperre sitzt auf dem Server, nicht im Browser.
     * ─────────────────────────────────────────────────────────────────────────
     */
    #[Test]
    public function a_provider_account_keeps_its_values_even_if_the_form_says_otherwise(): void
    {
        $this->actingAs($this->administrator());

        $user = $this->providerAccount();

        Livewire::test(EditUser::class, ['record' => $user->getKey()])
            ->fillForm([
                'name' => 'Von Hand Geändert',
                'email' => 'vonhand@example.org',
                'is_active' => false,
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $user->refresh();

        $this->assertSame('Erika Meier', $user->name, 'Der Name gehört dem Provider.');
        $this->assertSame('erika@example.org', $user->email, 'Die Adresse gehört dem Provider.');
        $this->assertTrue($user->is_active, '„Aktiv" führt der Abgleich.');
    }

    /**
     * Ein lokal angelegtes Konto bleibt unverändert bedienbar.
     *
     * Der Gegentest — ohne ihn würde ein Fehler in der Herkunftsprüfung, der
     * ALLE Konten sperrt, nicht auffallen.
     */
    #[Test]
    public function a_local_account_stays_editable(): void
    {
        $this->actingAs($this->administrator());

        $user = User::factory()->create([
            'name' => 'Lokal Angelegt',
            'email' => 'lokal@example.org',
            'is_active' => true,
        ]);

        Livewire::test(EditUser::class, ['record' => $user->getKey()])
            ->fillForm([
                'name' => 'Neuer Name',
                'email' => 'neu@example.org',
                'is_active' => false,
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $user->refresh();

        $this->assertSame('Neuer Name', $user->name);
        $this->assertSame('neu@example.org', $user->email);
        $this->assertFalse($user->is_active);
    }

    /**
     * Rollen bleiben auch bei einem Provider-Konto vergebbar — und das MUSS so.
     *
     * `certifying_staff` kommt nie von außen (Regel 4 in LinkExternalIdentity).
     * Wäre die Auswahl bei Provider-Konten gesperrt, könnte in einem Verein,
     * dessen Mitglieder alle aus Vereinsflieger kommen, niemand je eine
     * Freigabeberechtigung erteilen — die Freigabe wäre unerreichbar.
     */
    #[Test]
    public function roles_stay_assignable_on_a_provider_account(): void
    {
        $this->actingAs($this->administrator());

        $user = $this->providerAccount();
        $role = Role::where('name', CoreRoles::CERTIFYING_STAFF)->sole();

        Livewire::test(EditUser::class, ['record' => $user->getKey()])
            ->fillForm(['roles' => [$role->getKey()]])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertTrue($user->fresh()?->hasRole(CoreRoles::CERTIFYING_STAFF));
    }

    /**
     * Und dasselbe im eigenen Profil.
     *
     * Hier wiegt es schwerer als in der Benutzerverwaltung: Dort sitzt ein
     * Administrator, der weiß, woher die Konten kommen. Hier sitzt ein
     * Mitglied, das seine neue Adresse einträgt, eine Bestätigung bekommt —
     * und sich Wochen später wundert, warum nie eine Mail ankommt.
     */
    #[Test]
    public function a_member_from_a_provider_cannot_rewrite_their_own_profile(): void
    {
        $user = $this->providerAccount('einhinreichendlangespasswort1');
        $this->actingAs($user);

        Livewire::test(EditProfile::class)
            ->fillForm([
                'name' => 'Selbst Geändert',
                'email' => 'selbst@example.org',

                // Filament verlangt das aktuelle Passwort zum Speichern.
                'currentPassword' => 'einhinreichendlangespasswort1',
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $user->refresh();

        $this->assertSame('Erika Meier', $user->name);
        $this->assertSame('erika@example.org', $user->email);
    }

    /**
     * Ein lokales Konto darf sein Profil weiterhin ändern.
     */
    #[Test]
    public function a_local_account_can_still_change_its_own_profile(): void
    {
        $user = User::factory()->create([
            'name' => 'Lokal Angelegt',
            'email' => 'lokal@example.org',
            'is_active' => true,
            'password' => Hash::make('einhinreichendlangespasswort1'),
        ]);

        $this->actingAs($user);

        Livewire::test(EditProfile::class)
            ->fillForm([
                'name' => 'Selbst Geändert',
                'currentPassword' => 'einhinreichendlangespasswort1',
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('Selbst Geändert', $user->fresh()?->name);
    }

    /**
     * Ein Konto aus einem Provider, so wie der Abgleich es anlegt.
     */
    private function providerAccount(?string $passwort = null): User
    {
        $user = User::factory()->create([
            'name' => 'Erika Meier',
            'email' => 'erika@example.org',
            'is_active' => true,

            /*
             * Ohne Passwort, wie der Abgleich es anlegt -- ausser der Test
             * braucht eine angemeldete Person. Die hat naemlich eines: Wer
             * sich anmelden kann, hat seine Einladung eingeloest.
             */
            'password' => $passwort === null ? null : Hash::make($passwort),
        ]);

        ExternalIdentity::create([
            'user_id' => $user->getKey(),
            'provider' => 'vereinsflieger',
            'subject' => '4711',
            'username' => 'emeier',
            'last_seen_at' => now(),
        ]);

        return $user->fresh();
    }

    private function administrator(): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole(CoreRoles::ADMIN);

        return $user->fresh();
    }
}
