<?php

declare(strict_types=1);

namespace Tests\Feature\Core;

use App\Core\Identity\ExternalSubject;
use App\Core\Identity\LinkExternalIdentity;
use App\Core\Mail\InvitationMail;
use App\Core\Mail\Postman;
use App\Core\Mail\SendInvitation;
use App\Core\Settings\Settings;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Mailversand — und was ohne ihn passiert.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * Vorgabe (F4): „mail kommt noch, gehört in den core, details später."
 *
 * EIN VEREIN OHNE MAILSERVER IST DER NORMALFALL. Deshalb ist der Versand keine
 * Voraussetzung, sondern eine Eigenschaft, die man abfragt — und alles, was
 * Mail braucht, fragt vorher.
 * ─────────────────────────────────────────────────────────────────────────────
 */
final class MailTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function without_a_server_nothing_is_sent(): void
    {
        config()->set('mail.mailers.smtp.host', null);
        config()->set('mail.from.address', null);

        $this->assertFalse(Postman::configured());
        $this->assertFalse(Postman::canSend());
    }

    /**
     * Der log-Mailer ist KEIN Versand.
     *
     * Er schreibt in die Logdatei — im Test und in der Entwicklung richtig,
     * aber die Oberfläche darf ihn nicht für Versand halten. Sonst steht dort
     * ein Link, der eine Mail verspricht, die in einer Datei landet.
     */
    #[Test]
    public function the_log_mailer_does_not_count_as_sending(): void
    {
        config()->set('mail.mailers.smtp.host', 'smtp.example.org');
        config()->set('mail.from.address', 'werkstatt@example.org');
        config()->set('mail.default', 'log');

        $this->assertTrue(Postman::configured());
        $this->assertFalse(Postman::canSend());
    }

    /**
     * Ein eingetragener Zugang schaltet den Versand ein.
     *
     * Ohne das blieben die Einstellungen daneben wirkungslos: Wer Server,
     * Benutzer und Passwort einträgt, meint damit, dass verschickt wird — und
     * würde stattdessen Mails in einer Logdatei sammeln.
     */
    #[Test]
    public function entering_a_server_switches_the_mailer_on(): void
    {
        config()->set('mail.default', 'log');

        $settings = app(Settings::class);
        $settings->set('mail.host', 'smtp.example.org');
        $settings->set('mail.from_address', 'werkstatt@example.org');
        $settings->applyToConfig();

        $this->assertSame('smtp', config('mail.default'));
        $this->assertTrue(Postman::canSend());
    }

    /**
     * Der Absendername fällt auf die Organisation zurück.
     *
     * Sonst steht „Laravel" im Postfach der Mitglieder.
     */
    #[Test]
    public function the_sender_name_falls_back_to_the_organisation(): void
    {
        config()->set('aeronance.organisation.name', 'Akaflieg Freiburg');
        config()->set('mail.from.name', '');

        $this->assertSame('Akaflieg Freiburg', Postman::fromName());
    }

    #[Test]
    public function an_entered_sender_name_wins(): void
    {
        config()->set('aeronance.organisation.name', 'Akaflieg Freiburg');
        config()->set('mail.from.name', 'Werkstatt');

        $this->assertSame('Werkstatt', Postman::fromName());
    }

    // ── Einladungen ──────────────────────────────────────────────────────────

    /**
     * Der Knopf verschickt — und die Einladung trägt einen gültigen Link.
     */
    #[Test]
    public function an_invitation_carries_a_working_link(): void
    {
        Mail::fake();
        $this->withMailer();

        $user = User::factory()->create(['email' => 'erika@example.org']);

        $this->assertSame(SendInvitation::SENT, app(SendInvitation::class)->handle($user));

        Mail::assertSent(InvitationMail::class, function (InvitationMail $mail): bool {
            // Der Link muss zum Zurücksetzen führen, nicht irgendwohin.
            return str_contains($mail->url, 'password-reset');
        });
    }

    #[Test]
    public function without_a_mailer_no_invitation_is_claimed(): void
    {
        Mail::fake();
        config()->set('mail.mailers.smtp.host', null);

        $user = User::factory()->create(['email' => 'erika@example.org']);

        $this->assertSame(SendInvitation::NO_MAILER, app(SendInvitation::class)->handle($user));
        Mail::assertNothingSent();
    }

    /**
     * Eine Platzhalter-Adresse ist keine Adresse.
     *
     * Konten aus einem Provider können `@invalid.local` tragen — dorthin zu
     * senden hieße, eine Zustellung zu behaupten, die nicht stattfindet.
     */
    #[Test]
    public function a_placeholder_address_is_refused(): void
    {
        Mail::fake();
        $this->withMailer();

        $user = User::factory()->create(['email' => 'emeier@invalid.local']);

        $this->assertSame(SendInvitation::NO_ADDRESS, app(SendInvitation::class)->handle($user));
        Mail::assertNothingSent();
    }

    /**
     * Der Haken ist ab Werk aus.
     *
     * Beim ersten Mitgliederabgleich entstehen auf einen Schlag hunderte
     * Konten. Ob die alle sofort eine Mail bekommen, ist eine Entscheidung.
     */
    #[Test]
    public function automatic_invitations_are_off_by_default(): void
    {
        $this->assertFalse((bool) config('aeronance.mail.invite_automatically'));
    }

    /**
     * Ein Konto aus dem Abgleich hat KEIN Passwort.
     *
     * Vorgabe: „wenn ein konto neu angelegt wird hat es bitte gar kein passwort.
     * dieses entsteht erst durch einen aktiven passwort reset durch den user."
     */
    #[Test]
    public function an_account_from_the_sync_has_no_password(): void
    {
        $ergebnis = app(LinkExternalIdentity::class)->handle('vereinsflieger', new ExternalSubject(
            id: '4711',
            username: 'emeier',
            name: 'Erika Meier',
            email: 'erika@example.org',
        ));

        $this->assertNull($ergebnis['user']->password);
    }

    /**
     * Und ohne Adresse bekommt es einen Platzhalter, an den nie gesendet wird.
     *
     * GEMESSEN: 26 von 394 Mitgliedern haben in Vereinsflieger keine
     * Mailadresse. Der Platzhalter ist damit kein Notnagel für einen seltenen
     * Fall, sondern der Normalzustand für 7 % — und diese Menschen kommen nur
     * über einen Administrator hinein.
     */
    #[Test]
    public function a_member_without_an_address_cannot_be_invited(): void
    {
        Mail::fake();
        $this->withMailer();

        $ergebnis = app(LinkExternalIdentity::class)->handle('vereinsflieger', new ExternalSubject(
            id: '4712',
            username: 'ohnemail',
            name: 'Ohne Mail',
            email: null,
        ));

        $this->assertStringEndsWith('@invalid.local', (string) $ergebnis['user']->email);
        $this->assertSame(SendInvitation::NO_ADDRESS, app(SendInvitation::class)->handle($ergebnis['user']));

        Mail::assertNothingSent();
    }

    /**
     * Eine nachgetragene Adresse kommt beim nächsten Lauf an.
     *
     * Vorgabe: „bei VF usern einfach den wert aus dem VF nehmen und automatisch
     * aktualisieren. Wer seine mail nicht eingibt kommt halt nicht ins system."
     *
     * Also braucht es keinen Sonderweg für die 26 ohne Adresse: Sobald jemand
     * sie in Vereinsflieger nachträgt, ist das Konto einladbar.
     */
    #[Test]
    public function an_address_added_later_reaches_the_account(): void
    {
        Mail::fake();
        $this->withMailer();

        $verknuepfen = app(LinkExternalIdentity::class);

        // Erster Lauf: ohne Adresse.
        $ohne = $verknuepfen->handle('vereinsflieger', new ExternalSubject(
            id: '4713', username: 'spaeter', name: 'Kommt Später', email: null,
        ));

        $this->assertSame(SendInvitation::NO_ADDRESS, app(SendInvitation::class)->handle($ohne['user']));

        // Zweiter Lauf: die Adresse steht jetzt in Vereinsflieger.
        $mit = $verknuepfen->handle('vereinsflieger', new ExternalSubject(
            id: '4713', username: 'spaeter', name: 'Kommt Später', email: 'spaeter@example.org',
        ));

        $this->assertSame($ohne['user']->id, $mit['user']->id, 'Dasselbe Konto, kein zweites.');
        $this->assertSame('spaeter@example.org', $mit['user']->email);
        $this->assertSame(SendInvitation::SENT, app(SendInvitation::class)->handle($mit['user']));
    }

    // ── Der Testversand ──────────────────────────────────────────────────────

    #[Test]
    public function the_test_command_refuses_without_a_server(): void
    {
        config()->set('mail.mailers.smtp.host', null);

        $this->artisan('aeronance:mail-test', ['empfaenger' => 'wer@example.org'])
            ->expectsOutputToContain('Kein SMTP-Zugang')
            ->assertFailed();
    }

    #[Test]
    public function the_test_command_refuses_a_bad_address(): void
    {
        $this->artisan('aeronance:mail-test', ['empfaenger' => 'keine-adresse'])
            ->assertFailed();
    }

    #[Test]
    public function the_test_command_sends(): void
    {
        Mail::fake();

        config()->set('mail.mailers.smtp.host', 'smtp.example.org');
        config()->set('mail.from.address', 'werkstatt@example.org');

        $this->artisan('aeronance:mail-test', ['empfaenger' => 'wer@example.org'])
            ->assertSuccessful();

        Mail::assertSentCount(1);
    }

    private function withMailer(): void
    {
        config()->set('mail.mailers.smtp.host', 'smtp.example.org');
        config()->set('mail.from.address', 'werkstatt@example.org');
    }
}
