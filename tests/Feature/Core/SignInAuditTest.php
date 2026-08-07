<?php

declare(strict_types=1);

namespace Tests\Feature\Core;

use App\Core\Access\AccessSetup;
use App\Core\Models\Activity;
use App\Models\User;
use Filament\Auth\Pages\Login;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Anmeldeversuche im Protokoll — und was dort auf keinen Fall stehen darf.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * Die Leitplanke verlangt „fehlgeschlagene Logins ins Audit-Log". Der Grund ist
 * einfach: Ein Angriff auf ein Passwort besteht fast nur aus Fehlversuchen.
 *
 * GETESTET WIRD ÜBER DIE ECHTE ANMELDESEITE, nicht durch Auslösen der
 * Ereignisse. Nur so ist mitgeprüft, was Filament tatsächlich tut — etwa dass
 * `canAccessPanel()` innerhalb des Anmeldeversuchs geprüft wird und ein
 * gesperrtes Konto deshalb als FEHLVERSUCH erscheint und nicht als Anmeldung.
 * ─────────────────────────────────────────────────────────────────────────────
 */
final class SignInAuditTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORT = 'einhinreichendlangespasswort1';

    protected function setUp(): void
    {
        parent::setUp();

        app(AccessSetup::class)->run();
    }

    /**
     * DER TEST, UM DEN ES GEHT: Das Passwort landet nirgends.
     *
     * ─────────────────────────────────────────────────────────────────────────
     * Das `Failed`-Ereignis trägt die vollständigen Anmeldedaten — E-Mail UND
     * PASSWORT IM KLARTEXT. Wer sie unbesehen protokolliert, hat eine Tabelle
     * gebaut, in der die Passwörter aller stehen, die sich einmal vertippt
     * haben: unverschlüsselt, in jeder Sicherung, lesbar für jeden mit
     * Protokollrecht — und in dem einen Verzeichnis, das niemand löschen darf.
     *
     * Geprüft wird deshalb nicht das Feld, sondern die GANZE ZEILE als Text.
     * Ein Test, der nur `properties['password']` prüft, ginge davon aus, dass
     * man weiß, wo es stünde.
     * ─────────────────────────────────────────────────────────────────────────
     */
    #[Test]
    public function the_password_never_reaches_the_log(): void
    {
        $geheim = 'ThisIsTheSecretPassword42';

        User::factory()->create([
            'email' => 'erika@example.org',
            'password' => Hash::make(self::PASSWORT),
            'is_active' => true,
        ]);

        $this->attemptSignIn('erika@example.org', $geheim);

        $zeilen = DB::table('activity_log')->get();

        $this->assertNotEmpty($zeilen, 'Ohne Eintrag beweist dieser Test nichts.');

        foreach ($zeilen as $zeile) {
            $this->assertStringNotContainsString(
                $geheim,
                json_encode($zeile, JSON_THROW_ON_ERROR),
                'Das eingegebene Passwort steht im Protokoll.',
            );
        }
    }

    #[Test]
    public function a_failed_attempt_is_recorded(): void
    {
        User::factory()->create([
            'email' => 'erika@example.org',
            'password' => Hash::make(self::PASSWORT),
            'is_active' => true,
        ]);

        $this->attemptSignIn('erika@example.org', 'falsch');

        $eintrag = Activity::query()->where('description', 'login_failed')->sole();

        $this->assertSame('auth', $eintrag->log_name);
        $this->assertSame('erika@example.org', $eintrag->properties['identifier'] ?? null);
        $this->assertTrue($eintrag->properties['account_exists'] ?? null);
    }

    /**
     * Bei einer unbekannten Adresse steht das ausdrücklich dabei.
     *
     * Der Unterschied zwischen „Adresse geraten" und „Passwort falsch" ist
     * genau die Frage, die jemand stellt, der wissen will, ob hier ein Angriff
     * läuft oder sich jemand vertippt hat.
     */
    #[Test]
    public function an_unknown_address_is_marked_as_such(): void
    {
        $this->attemptSignIn('niemand@example.org', 'egal');

        $eintrag = Activity::query()->where('description', 'login_failed')->sole();

        $this->assertFalse($eintrag->properties['account_exists'] ?? null);
        $this->assertNull($eintrag->subject_id, 'Zu einer unbekannten Adresse gibt es kein Konto.');
        $this->assertSame('niemand@example.org', $eintrag->properties['identifier'] ?? null);
    }

    /**
     * Ein GESPERRTES Konto erscheint als Fehlversuch — nicht als Anmeldung.
     *
     * Filament prüft `canAccessPanel()` innerhalb des Anmeldeversuchs. Wer
     * gesperrt ist, hat sich also nicht angemeldet, und genau so steht es im
     * Protokoll. Damit wird der Not-Aus sichtbar: Versucht es jemand weiter,
     * sieht man das.
     */
    #[Test]
    public function a_locked_account_shows_up_as_a_failure(): void
    {
        $user = User::factory()->create([
            'email' => 'erika@example.org',
            'password' => Hash::make(self::PASSWORT),
            'is_active' => true,
        ]);

        $user->lockAccess('Notebook abhanden gekommen');

        // Das RICHTIGE Passwort.
        $this->attemptSignIn('erika@example.org', self::PASSWORT);

        $this->assertSame(1, Activity::query()->where('description', 'login_failed')->count());
        $this->assertSame(0, Activity::query()->where('description', 'login_succeeded')->count());
    }

    /**
     * Und ein deaktiviertes ebenso.
     */
    #[Test]
    public function a_deactivated_account_shows_up_as_a_failure(): void
    {
        User::factory()->create([
            'email' => 'erika@example.org',
            'password' => Hash::make(self::PASSWORT),
            'is_active' => false,
        ]);

        $this->attemptSignIn('erika@example.org', self::PASSWORT);

        $this->assertSame(1, Activity::query()->where('description', 'login_failed')->count());
        $this->assertSame(0, Activity::query()->where('description', 'login_succeeded')->count());
    }

    /**
     * Die geglückte Anmeldung steht auch da — sonst bliebe die Frage offen.
     *
     * Ohne sie beantwortet das Protokoll nicht, was nach fünf Fehlversuchen als
     * Erstes jemand wissen will: Ist er dann hineingekommen?
     */
    #[Test]
    public function a_successful_sign_in_is_recorded_with_the_person(): void
    {
        $user = User::factory()->create([
            'email' => 'erika@example.org',
            'password' => Hash::make(self::PASSWORT),
            'is_active' => true,
        ]);

        $this->attemptSignIn('erika@example.org', self::PASSWORT);

        $eintrag = Activity::query()->where('description', 'login_succeeded')->sole();

        $this->assertSame('auth', $eintrag->log_name);
        $this->assertSame($user->getKey(), $eintrag->causer_id);
    }

    /**
     * Eine übergroße Eingabe sprengt den Eintrag nicht.
     *
     * Das Kennungsfeld kommt aus einem Formular; niemand hindert jemanden
     * daran, ein Megabyte hineinzuschreiben. Ein Protokolleintrag darf keine
     * Angriffsfläche für die Datenbank sein.
     */
    #[Test]
    public function an_oversized_identifier_is_cut_down(): void
    {
        $this->attemptSignIn(str_repeat('a', 5000).'@example.org', 'egal');

        $eintrag = Activity::query()->where('description', 'login_failed')->sole();

        $this->assertLessThanOrEqual(200, mb_strlen((string) ($eintrag->properties['identifier'] ?? '')));
    }

    private function attemptSignIn(string $email, string $password): void
    {
        Livewire::test(Login::class)
            ->fillForm([
                'email' => $email,
                'password' => $password,
            ])
            ->call('authenticate');
    }
}
