<?php

declare(strict_types=1);

namespace Tests\Feature\Core;

use App\Core\Access\AccessSetup;
use App\Core\Access\CoreRoles;
use App\Core\Filament\Pages\SettingsPage;
use App\Core\Mail\TestMail;
use App\Core\Settings\Settings;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Der Testversand aus der Oberfläche.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * DER FALL, DEN DER KNOPF VERHINDERT: Ein Verein trägt SMTP-Daten ein, jemand
 * vertippt sich beim Passwort, und niemand merkt es. Wochen später braucht ein
 * Mitglied ein neues Passwort, drückt „vergessen", bekommt eine Bestätigung —
 * und wartet. Der Fehler steht im Log, das keiner liest.
 *
 * Der wichtigste Test hier ist `unsaved_input_is_what_gets_tested`: Müsste man
 * erst speichern, um zu prüfen, hätte man im Fehlerfall einen kaputten Zugang
 * in der Datenbank stehen.
 * ─────────────────────────────────────────────────────────────────────────────
 */
final class MailTestButtonTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        app(AccessSetup::class)->run();
        $this->actingAs($this->administrator());
    }

    /**
     * DER TEST, UM DEN ES GEHT: Geprüft wird, was im Formular steht.
     *
     * Nicht das Gespeicherte — sonst wäre der Knopf erst nach dem Speichern
     * nützlich, und das ist der falsche Zeitpunkt.
     */
    #[Test]
    public function unsaved_input_is_what_gets_tested(): void
    {
        Mail::fake();

        /*
         * In der DATENBANK steht nichts. Nicht ueber get() geprueft: Das
         * liefert den WIRKSAMEN Wert, und der kommt hier aus der Umgebung
         * (MAIL_HOST). Die Herkunft ist die Frage, um die es geht.
         */
        $this->assertNotSame('datenbank', app(Settings::class)->sourceOf('mail.host'));

        Livewire::test(SettingsPage::class)
            ->fillForm([
                'mail__host' => 'smtp.example.org',
                'mail__port' => 587,
                'mail__from_address' => 'werkstatt@example.org',
            ])
            ->callAction('testMail', ['empfaenger' => 'wer@example.org']);

        Mail::assertSent(TestMail::class);

        // Und gespeichert wurde dabei nichts.
        $this->assertNotSame(
            'datenbank',
            app(Settings::class)->sourceOf('mail.host'),
            'Der Testversand darf nichts in die Datenbank schreiben.',
        );
    }

    /**
     * Ohne SMTP-Server wird nichts verschickt — und das wird auch gesagt.
     *
     * Ein Knopf, der still nichts tut, ist schlimmer als keiner.
     */
    #[Test]
    public function without_a_server_nothing_is_sent(): void
    {
        Mail::fake();

        Livewire::test(SettingsPage::class)
            ->fillForm(['mail__host' => null, 'mail__from_address' => null])
            ->callAction('testMail', ['empfaenger' => 'wer@example.org']);

        Mail::assertNothingSent();
    }

    /**
     * Ein leeres Passwortfeld heißt „nicht ändern", nicht „leeres Passwort".
     *
     * Geheimnisse werden nie zurückgezeigt, das Feld ist also im Regelfall
     * leer. Es als leeres Passwort zu senden hiesse, einen Fehlschlag zu
     * melden, den es nicht gibt.
     */
    #[Test]
    public function an_empty_secret_field_falls_back_to_the_stored_one(): void
    {
        Mail::fake();

        $settings = app(Settings::class);
        $settings->set('mail.host', 'smtp.example.org');
        $settings->set('mail.from_address', 'werkstatt@example.org');
        $settings->set('mail.password', 'das-gespeicherte');

        Livewire::test(SettingsPage::class)
            // Passwortfeld bleibt leer -- wie nach jedem Seitenaufruf.
            ->callAction('testMail', ['empfaenger' => 'wer@example.org']);

        Mail::assertSent(TestMail::class);
        $this->assertSame('das-gespeicherte', config('mail.mailers.smtp.password'));
    }

    /**
     * Und wer keine Einstellungen verwalten darf, sieht die Seite gar nicht.
     */
    #[Test]
    public function the_page_needs_the_permission(): void
    {
        $this->actingAs(User::factory()->create(['is_active' => true]));

        $this->assertFalse(SettingsPage::canAccess());
    }

    private function administrator(): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole(CoreRoles::ADMIN);

        return $user->fresh();
    }
}
