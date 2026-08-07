<?php

declare(strict_types=1);

namespace Tests\Feature\Core;

use App\Core\Access\AccessSetup;
use App\Core\Access\CorePermissions;
use App\Core\Access\CoreRoles;
use App\Core\Filament\Resources\Users\UserResource;
use App\Core\Identity\ExternalIdentity;
use App\Core\Identity\ExternalSubject;
use App\Core\Identity\LinkExternalIdentity;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

/**
 * Der Not-Aus — eine Sperre, die kein Abgleich aufhebt.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * Vorgabe: „ja, bau den not-aus ein."
 *
 * Vorgeschichte: Konten aus einem Provider lassen sich nicht mehr von Hand
 * deaktivieren, weil der nächtliche Abgleich „Aktiv" führt und jede Änderung um
 * 2 Uhr morgens still zurücknimmt. Für den geordneten Fall ist das richtig —
 * wer austritt, verschwindet über den Provider.
 *
 * FÜR DEN UNGEORDNETEN NICHT. Streit, verlorenes Notebook, Verdacht: Dann muss
 * der Zugang in dieser Minute weg sein und weg bleiben.
 *
 * Der wichtigste Test dieser Datei ist deshalb `a_lock_survives_the_sync`.
 * Alles andere ist Beiwerk; wenn der fällt, ist die Sperre eine Zusage, die
 * nachts von selbst aufgeht.
 * ─────────────────────────────────────────────────────────────────────────────
 */
final class AccessLockTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        app(AccessSetup::class)->run();
    }

    /**
     * DER TEST, UM DEN ES GEHT.
     *
     * Der Abgleich meldet die Person als aktives Mitglied — und trotzdem
     * kommt sie nicht herein.
     */
    #[Test]
    public function a_lock_survives_the_sync(): void
    {
        $subject = new ExternalSubject(
            id: '4711',
            username: 'emeier',
            name: 'Erika Meier',
            email: 'erika@example.org',
        );

        $user = app(LinkExternalIdentity::class)->handle('vereinsflieger', $subject)['user'];
        $user->lockAccess('Notebook abhanden gekommen');

        $this->assertFalse($user->fresh()?->hasAccess());

        // Und jetzt die Nacht: Der Abgleich läuft, die Person ist dort aktiv.
        $erneut = app(LinkExternalIdentity::class)->handle('vereinsflieger', $subject)['user'];

        $this->assertTrue($erneut->is_active, 'Der Provider meldet weiterhin aktiv.');
        $this->assertTrue($erneut->isLocked(), 'Die Sperre steht trotzdem noch.');
        $this->assertFalse($erneut->hasAccess(), 'Und sie wirkt.');
        $this->assertSame('Notebook abhanden gekommen', $erneut->lock_reason);
    }

    /**
     * Auch über ein Aus und Wieder-Ein im Provider hinweg.
     *
     * Wer ausgetreten und wieder eingetreten ist, bekommt sein Konto zurück —
     * aber nicht seine Sperre geschenkt.
     */
    #[Test]
    public function a_lock_survives_leaving_and_returning(): void
    {
        $user = app(LinkExternalIdentity::class)->handle('vereinsflieger', new ExternalSubject(
            id: '4711', username: 'emeier', name: 'Erika Meier', email: 'erika@example.org',
        ))['user'];

        $user->lockAccess('Verdacht');

        // Ausgetreten …
        app(LinkExternalIdentity::class)->handle('vereinsflieger', new ExternalSubject(
            id: '4711', username: 'emeier', name: 'Erika Meier', email: 'erika@example.org',
            active: false,
        ));

        // … und wieder da.
        $zurueck = app(LinkExternalIdentity::class)->handle('vereinsflieger', new ExternalSubject(
            id: '4711', username: 'emeier', name: 'Erika Meier', email: 'erika@example.org',
        ))['user'];

        $this->assertTrue($zurueck->isLocked());
        $this->assertFalse($zurueck->hasAccess());
    }

    /**
     * Eine Sperre nimmt ALLE Rechte — nicht nur den Weg ins Panel.
     *
     * Derselbe Fehler, den ein feindseliger Test schon bei `is_active` gefunden
     * hat: Das Panel wies ab, aber eine einzelne Seite antwortete auf ihre
     * eigene Rechtefrage weiterhin mit ja.
     */
    #[Test]
    public function a_lock_withdraws_every_permission(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole(CoreRoles::ADMIN);

        $this->assertTrue($user->can(CorePermissions::USERS_MANAGE));

        $user->lockAccess('Streit');

        $this->assertFalse($user->fresh()?->can(CorePermissions::USERS_MANAGE));
        $this->assertFalse($user->fresh()?->canAccessPanel(Filament::getPanel('admin')));
    }

    /**
     * Die laufende Sitzung endet sofort.
     *
     * Ohne das bliebe jemand, der gerade angemeldet ist, es bis er selbst geht
     * — und „beim nächsten Klick" ist für einen Not-Aus die falsche Zusage.
     */
    #[Test]
    public function locking_ends_a_running_session(): void
    {
        /*
         * Die Suite läuft mit SESSION_DRIVER=array -- dann gibt es keine
         * Sitzungstabelle, und lockAccess() lässt sie zu Recht in Ruhe.
         * Geprüft werden soll hier der Weg, der PRODUKTIV gilt, und der ist
         * laut config/session.php der Datenbank-Treiber.
         *
         * Ohne diese Zeile war der Test grün gegen eine Methode, die gar nichts
         * tat -- er ist genau daran hängengeblieben, und das ist der Grund,
         * warum die Zeile hier steht und nicht in setUp() verschwindet.
         */
        config()->set('session.driver', 'database');

        $user = User::factory()->create(['is_active' => true]);

        DB::table('sessions')->insert([
            'id' => 'sitzung-eins',
            'user_id' => $user->getKey(),
            'ip_address' => '127.0.0.1',
            'user_agent' => 'test',
            'payload' => '',
            'last_activity' => time(),
        ]);

        $user->lockAccess('Notebook weg');

        $this->assertSame(0, DB::table('sessions')->where('user_id', $user->getKey())->count());
    }

    /**
     * Fremde Sitzungen bleiben unberührt.
     */
    #[Test]
    public function locking_leaves_other_peoples_sessions_alone(): void
    {
        config()->set('session.driver', 'database');

        $user = User::factory()->create(['is_active' => true]);
        $andere = User::factory()->create(['is_active' => true]);

        foreach ([[$user, 'a'], [$andere, 'b']] as [$wer, $id]) {
            DB::table('sessions')->insert([
                'id' => $id,
                'user_id' => $wer->getKey(),
                'ip_address' => '127.0.0.1',
                'user_agent' => 'test',
                'payload' => '',
                'last_activity' => time(),
            ]);
        }

        $user->lockAccess('Grund');

        $this->assertSame(1, DB::table('sessions')->where('user_id', $andere->getKey())->count());
    }

    /**
     * Ohne Datenbank-Sitzungen bleibt die Tabelle in Ruhe — und die Sperre wirkt trotzdem.
     *
     * Wer Redis oder Dateien als Sitzungsspeicher nutzt, hat diese Tabelle
     * nicht. Ein Zugriff darauf wäre ein Fehler bei jedem Sperren; die Sperre
     * selbst greift ohnehin beim nächsten Aufruf, weil hasAccess() an jeder
     * Rechtefrage hängt.
     */
    #[Test]
    public function locking_works_without_database_sessions(): void
    {
        config()->set('session.driver', 'redis');

        $user = User::factory()->create(['is_active' => true]);
        $user->lockAccess('Grund');

        $this->assertFalse($user->fresh()?->hasAccess());
    }

    #[Test]
    public function unlocking_gives_access_back(): void
    {
        $user = User::factory()->create(['is_active' => true]);

        $user->lockAccess('Verdacht');
        $this->assertFalse($user->fresh()?->hasAccess());

        $user->unlockAccess();

        $user->refresh();
        $this->assertTrue($user->hasAccess());
        $this->assertNull($user->lock_reason, 'Ein aufgehobener Grund bleibt nicht stehen.');
        $this->assertNull($user->locked_by_id);
    }

    /**
     * Ein deaktiviertes Konto bleibt deaktiviert, auch wenn die Sperre fällt.
     *
     * Beide Aussagen sind getrennt, und das Aufheben der einen darf die andere
     * nicht mitbeantworten.
     */
    #[Test]
    public function unlocking_does_not_reactivate_a_deactivated_account(): void
    {
        $user = User::factory()->create(['is_active' => false]);

        $user->lockAccess('Grund');
        $user->unlockAccess();

        $this->assertFalse($user->fresh()?->hasAccess());
    }

    /**
     * Sperre und Aufhebung stehen im Audit-Log — mit Grund und Urheber.
     *
     * Ein Betrieb, der einem Menschen den Zugang entzieht, muss sagen können,
     * wer das entschieden hat. Spätestens wenn der Betroffene fragt.
     */
    #[Test]
    public function both_the_lock_and_its_release_are_recorded(): void
    {
        $handelnder = User::factory()->create(['is_active' => true]);
        $user = User::factory()->create(['is_active' => true]);

        $user->lockAccess('Notebook abhanden gekommen', $handelnder);

        $eintrag = Activity::query()->where('description', 'access_locked')->sole();
        $this->assertSame($user->getKey(), $eintrag->subject_id);
        $this->assertSame($handelnder->getKey(), $eintrag->causer_id);
        $this->assertSame('Notebook abhanden gekommen', $eintrag->properties['reason'] ?? null);

        $user->unlockAccess($handelnder);

        $frei = Activity::query()->where('description', 'access_unlocked')->sole();
        $this->assertSame(
            'Notebook abhanden gekommen',
            $frei->properties['previous_reason'] ?? null,
            'Der Grund verschwindet aus dem Konto, aber nicht aus dem Log.',
        );
    }

    /**
     * Wer sperrt, steht am Konto — bis er selbst nicht mehr da ist.
     *
     * Und dann bleibt die SPERRE, nur der Name geht. Andersherum wäre es eine
     * Katastrophe mit Ansage.
     */
    #[Test]
    public function the_lock_outlives_the_person_who_set_it(): void
    {
        $handelnder = User::factory()->create(['is_active' => true]);
        $user = User::factory()->create(['is_active' => true]);

        $user->lockAccess('Grund', $handelnder);
        $this->assertSame($handelnder->getKey(), $user->fresh()?->locked_by_id);

        $handelnder->forceDelete();

        $user->refresh();
        $this->assertTrue($user->isLocked(), 'Die Sperre bleibt.');
        $this->assertNull($user->locked_by_id, 'Nur der Name geht.');
    }

    // ── Wer darf sperren ─────────────────────────────────────────────────────

    /**
     * Niemand sich selbst.
     *
     * Der naheliegendste Unfall: Der einzige Administrator sperrt sich aus und
     * kann die Sperre danach nicht mehr aufheben, weil das Aufheben genau das
     * Recht braucht, das er sich gerade genommen hat.
     */
    #[Test]
    public function nobody_can_lock_themselves_out(): void
    {
        $admin = User::factory()->create(['is_active' => true]);
        $admin->assignRole(CoreRoles::ADMIN);

        $this->actingAs($admin);

        $this->assertFalse(UserResource::canLock($admin->fresh()));
    }

    #[Test]
    public function an_administrator_can_lock_somebody_else(): void
    {
        $admin = User::factory()->create(['is_active' => true]);
        $admin->assignRole(CoreRoles::ADMIN);
        $andere = User::factory()->create(['is_active' => true]);

        $this->actingAs($admin);

        $this->assertTrue(UserResource::canLock($andere));
    }

    /**
     * Und wer keine Benutzer verwalten darf, sperrt auch niemanden.
     */
    #[Test]
    public function a_mechanic_cannot_lock_anybody(): void
    {
        $mechaniker = User::factory()->create(['is_active' => true]);
        $mechaniker->assignRole(CoreRoles::MECHANIC);
        $andere = User::factory()->create(['is_active' => true]);

        $this->actingAs($mechaniker);

        $this->assertFalse(UserResource::canLock($andere));
    }

    /**
     * Ein gesperrter Administrator kann niemanden mehr sperren.
     *
     * Folgt aus hasAccess(), aber es ist die Frage, die jemand stellt, der
     * wissen will, ob eine Sperre wirklich alles nimmt.
     */
    #[Test]
    public function a_locked_administrator_can_no_longer_lock_anyone(): void
    {
        $admin = User::factory()->create(['is_active' => true]);
        $admin->assignRole(CoreRoles::ADMIN);
        $andere = User::factory()->create(['is_active' => true]);

        $admin->lockAccess('Verdacht');

        $this->actingAs($admin->fresh());

        $this->assertFalse(UserResource::canLock($andere));
    }

    /**
     * Ein Konto ohne Provider lässt sich weiterhin ganz normal deaktivieren.
     *
     * Der Not-Aus ist eine Ergänzung, kein Ersatz.
     */
    #[Test]
    public function deactivation_still_works_on_its_own(): void
    {
        $user = User::factory()->create(['is_active' => false]);

        $this->assertFalse($user->hasAccess());
        $this->assertFalse($user->isLocked());
    }

    /**
     * Und ein Konto aus einem Provider ist nicht von vornherein gesperrt.
     */
    #[Test]
    public function a_fresh_account_is_not_locked(): void
    {
        $user = app(LinkExternalIdentity::class)->handle('vereinsflieger', new ExternalSubject(
            id: '4711', username: 'emeier', name: 'Erika Meier', email: 'erika@example.org',
        ))['user'];

        $this->assertFalse($user->isLocked());
        $this->assertTrue($user->hasAccess());
        $this->assertNotNull(ExternalIdentity::query()->where('user_id', $user->getKey())->first());
    }
}
