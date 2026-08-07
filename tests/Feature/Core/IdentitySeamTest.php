<?php

declare(strict_types=1);

namespace Tests\Feature\Core;

use App\Core\Access\AccessSetup;
use App\Core\Access\CoreRoles;
use App\Core\Identity\ExternalIdentity;
use App\Core\Identity\ExternalSubject;
use App\Core\Identity\LinkExternalIdentity;
use App\Core\Identity\RoleMapping;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Die Kern-Naht für externe Identitäten.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * Vorgabe (E4): "VF ist ein Modul zur Anbindung, die eigentlichen Rechte werden
 * im System gebaut und dann werden die User (bei VF ggf. die Funktionen) auf
 * die systeminternen Rollen gematcht. Da gibt es ja auch Samba oder sonst was."
 *
 * Geprüft wird hier ohne jeden Connector -- genau das ist der Punkt: Die Regeln
 * hängen nicht am Protokoll, und wenn sie stimmen, ist der erste Connector nur
 * noch Fleissarbeit.
 * ─────────────────────────────────────────────────────────────────────────────
 */
final class IdentitySeamTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        app(AccessSetup::class)->run();
    }

    #[Test]
    public function a_new_subject_becomes_an_account(): void
    {
        $ergebnis = $this->link($this->subject());

        $this->assertTrue($ergebnis['created']);
        $this->assertSame('Erika Meier', $ergebnis['user']->name);
        $this->assertSame('erika@example.org', $ergebnis['user']->email);
        $this->assertTrue($ergebnis['user']->is_active);
    }

    #[Test]
    public function a_group_becomes_a_role(): void
    {
        $this->map(RoleMapping::KIND_GROUP, 'Werkstatt', CoreRoles::MECHANIC);

        $ergebnis = $this->link($this->subject(groups: ['Werkstatt']));

        $this->assertTrue($ergebnis['user']->hasRole(CoreRoles::MECHANIC));
        $this->assertSame([CoreRoles::MECHANIC], $ergebnis['granted']);
    }

    #[Test]
    public function a_single_subject_can_be_mapped_too(): void
    {
        // die "die User (bei VF ggf. die Funktionen)": beide Ebenen, sonst
        // passt entweder VF oder LDAP nicht ins Schema.
        $this->map(RoleMapping::KIND_USER, '4711', CoreRoles::WORKSHOP_MANAGER);

        $ergebnis = $this->link($this->subject());

        $this->assertTrue($ergebnis['user']->hasRole(CoreRoles::WORKSHOP_MANAGER));
    }

    #[Test]
    public function a_locally_granted_role_survives_the_sync(): void
    {
        /*
         * ─────────────────────────────────────────────────────────────────────
         * DER SCHADEN, DEN DIE DRITTE TABELLE VERHINDERT.
         *
         * Ein Abgleich, der stumpf neu setzt, nimmt einem Mechaniker die lokal
         * erteilte Rolle wieder weg -- lautlos, irgendwann nachts. Auffallen
         * würde es ihm am Samstag vor dem Hangar.
         *
         * Der Provider darf genau das zurücknehmen, was er selbst vergeben hat.
         * ─────────────────────────────────────────────────────────────────────
         */
        $ergebnis = $this->link($this->subject());
        $ergebnis['user']->assignRole(CoreRoles::MECHANIC);

        // Zweiter Lauf, ohne passende Zuordnung.
        $zweiter = $this->link($this->subject());

        $this->assertTrue($zweiter['user']->hasRole(CoreRoles::MECHANIC), 'Von Hand vergeben, bleibt.');
        $this->assertSame([], $zweiter['revoked']);
    }

    #[Test]
    public function what_the_provider_gave_the_provider_takes_back(): void
    {
        $this->map(RoleMapping::KIND_GROUP, 'Werkstatt', CoreRoles::MECHANIC);

        $this->link($this->subject(groups: ['Werkstatt']));

        // Aus der Gruppe geflogen.
        $zweiter = $this->link($this->subject(groups: []));

        $this->assertFalse($zweiter['user']->hasRole(CoreRoles::MECHANIC));
        $this->assertSame([CoreRoles::MECHANIC], $zweiter['revoked']);
    }

    #[Test]
    public function certifying_staff_never_comes_from_a_provider(): void
    {
        /*
         * ─────────────────────────────────────────────────────────────────────
         * DIE REGEL, DIE STRUKTURELL SEIN MUSS.
         *
         * Analyse §6.4: Vereinsfunktion und Werkstattqualifikation sind zwei
         * verschiedene Dinge. Wer "Werkstattleiter" heisst, ist eine
         * Organisationsaussage; freigabeberechtigt zu sein ist eine
         * Qualifikationsaussage mit Lizenznachweis, Recency und Haftungsfolge.
         *
         * Selbst wenn jemand die Zuordnung anlegt -- etwa aus einer alten
         * Sicherung --, greift sie nicht. Ein Audit fragt bei genau dieser Rolle
         * nach dem Nachweis.
         * ─────────────────────────────────────────────────────────────────────
         */
        // Erster Riegel: die Zuordnung laesst sich gar nicht erst anlegen.
        try {
            $this->map(RoleMapping::KIND_GROUP, 'Vorstand', CoreRoles::CERTIFYING_STAFF);
            $this->fail('Die Zuordnung haette abgewiesen werden muessen.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('Qualifikationsaussage', $e->getMessage());
        }

        /*
         * Zweiter Riegel: Selbst wenn sie doch in der Tabelle steht -- aus einer
         * Sicherung, die aelter ist als diese Regel, oder weil die Rolle
         * nachtraeglich umbenannt wurde -- greift sie nicht.
         */
        DB::table('role_mappings')->insert([
            'provider' => 'ldap',
            'kind' => RoleMapping::KIND_GROUP,
            'value' => 'Vorstand',
            'role_id' => Role::findByName(CoreRoles::CERTIFYING_STAFF)->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $ergebnis = $this->link($this->subject(groups: ['Vorstand']));

        $this->assertFalse($ergebnis['user']->hasRole(CoreRoles::CERTIFYING_STAFF));
        $this->assertSame([], $ergebnis['granted']);
    }

    #[Test]
    public function a_departed_member_is_deactivated_and_not_deleted(): void
    {
        $this->map(RoleMapping::KIND_GROUP, 'Werkstatt', CoreRoles::MECHANIC);

        $erster = $this->link($this->subject(groups: ['Werkstatt']));
        $id = $erster['user']->id;

        $zweiter = $this->link($this->subject(groups: ['Werkstatt'], active: false));

        $this->assertSame($id, $zweiter['user']->id, 'Dasselbe Konto.');
        $this->assertFalse($zweiter['user']->is_active);
        $this->assertFalse($zweiter['user']->hasRole(CoreRoles::MECHANIC));
        $this->assertNotNull(User::find($id), 'Das Konto bleibt -- die Nachweiskette haengt daran.');
    }

    #[Test]
    public function a_changed_email_does_not_create_a_second_account(): void
    {
        /*
         * Gefunden wird über die Kennung des Providers. Hinge es an der E-Mail,
         * bekäme derselbe Mensch nach einer Adressänderung ein zweites Konto --
         * ohne seine Rollen und ohne seine Vergangenheit.
         */
        $erster = $this->link($this->subject());

        $zweiter = $this->link(new ExternalSubject(
            id: '4711',
            username: 'emeier',
            name: 'Erika Meier',
            email: 'erika.meier@example.org',
        ));

        $this->assertSame($erster['user']->id, $zweiter['user']->id);
        $this->assertSame('erika.meier@example.org', $zweiter['user']->email);
        $this->assertSame(1, ExternalIdentity::count());
    }

    #[Test]
    public function an_existing_local_account_is_adopted_rather_than_duplicated(): void
    {
        // Ein Verein, der lokal gepflegt hat und dann einen Provider anschaltet,
        // soll nicht für jeden Menschen ein zweites Konto bekommen.
        $lokal = User::factory()->create(['email' => 'erika@example.org', 'is_active' => true]);

        $ergebnis = $this->link($this->subject());

        $this->assertSame($lokal->id, $ergebnis['user']->id);
        $this->assertFalse($ergebnis['created']);
        $this->assertSame(1, User::count());
    }

    #[Test]
    public function two_providers_do_not_take_each_others_roles_away(): void
    {
        /*
         * Ein Betrieb kann LDAP und Vereinsflieger nebeneinander fahren. Nimmt
         * der eine zurück, was der andere gab, sind die Rechte von der
         * Reihenfolge der nächtlichen Läufe abhängig -- und damit zufällig.
         */
        $this->map(RoleMapping::KIND_GROUP, 'Werkstatt', CoreRoles::MECHANIC, 'ldap');

        $ldap = (new LinkExternalIdentity)->handle('ldap', $this->subject(groups: ['Werkstatt']));

        $vf = (new LinkExternalIdentity)->handle('vereinsflieger', new ExternalSubject(
            id: '99',
            username: 'emeier',
            name: 'Erika Meier',
            email: 'erika@example.org',
        ));

        $this->assertSame($ldap['user']->id, $vf['user']->id);
        $this->assertSame([], $vf['revoked']);
        $this->assertTrue($vf['user']->hasRole(CoreRoles::MECHANIC));
    }

    // ── Hilfsmittel ─────────────────────────────────────────────────────────

    /** @param list<string> $groups */
    private function subject(array $groups = [], bool $active = true): ExternalSubject
    {
        return new ExternalSubject(
            id: '4711',
            username: 'emeier',
            name: 'Erika Meier',
            email: 'erika@example.org',
            groups: $groups,
            active: $active,
        );
    }

    /** @return array{user: User, created: bool, granted: list<string>, revoked: list<string>} */
    private function link(ExternalSubject $subject): array
    {
        return (new LinkExternalIdentity)->handle('ldap', $subject);
    }

    private function map(string $kind, string $value, string $role, string $provider = 'ldap'): void
    {
        RoleMapping::create([
            'provider' => $provider,
            'kind' => $kind,
            'value' => $value,
            'role_id' => Role::findByName($role)->id,
        ]);
    }
}
