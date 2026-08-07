<?php

declare(strict_types=1);

namespace Tests\Feature\Core;

use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Die HTTP-Härtung und der zweite Faktor.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * Beide standen als offene Punkte in docs/INFRASTRUKTUR.md, und beide waren
 * keine Bauarbeit, sondern eine vergessene Zeile: Filament bringt 2FA mit, und
 * die Header setzte bisher ein einzelner Controller -- der Dokumentenausgang
 * des Lagers. Sie galten damit für einen von Dutzenden Endpunkten.
 * ─────────────────────────────────────────────────────────────────────────────
 */
final class HardeningTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function every_response_carries_the_security_headers(): void
    {
        $response = $this->get('/up');

        $response->assertHeader('X-Frame-Options', 'DENY');
        $response->assertHeader('X-Content-Type-Options', 'nosniff');
        $response->assertHeader('Referrer-Policy', 'same-origin');

        $csp = (string) $response->headers->get('Content-Security-Policy');

        $this->assertStringContainsString("default-src 'self'", $csp);
        $this->assertStringContainsString("frame-ancestors 'none'", $csp);
        $this->assertStringContainsString("object-src 'none'", $csp);
    }

    #[Test]
    public function scripts_may_never_be_inline(): void
    {
        /*
         * ─────────────────────────────────────────────────────────────────────
         * DIE EINE ZEILE, DIE DIE GANZE CSP WERTLOS MACHT. `unsafe-inline` bei
         * script-src ist genau der Weg, den ein eingeschleustes Skript nimmt --
         * mit ihm schützt die Richtlinie vor nichts mehr.
         *
         * Sie steht erfahrungsgemäss schnell drin, wenn irgendwo etwas nicht
         * lädt. Deshalb hält dieser Test sie fest, statt sich auf die Absicht im
         * Kommentar zu verlassen. Bei style-src ist sie in Ordnung: Filament
         * setzt Stilattribute inline, und ein Style führt keinen Code aus.
         * ─────────────────────────────────────────────────────────────────────
         */
        $csp = (string) $this->get('/up')->headers->get('Content-Security-Policy');

        preg_match('/script-src ([^;]+)/', $csp, $scripts);

        $this->assertNotEmpty($scripts, 'Es muss eine script-src-Regel geben.');
        $this->assertStringNotContainsString('unsafe-inline', $scripts[1]);

        /*
         * ─────────────────────────────────────────────────────────────────────
         * HIER STAND EINMAL AUCH `unsafe-eval` -- UND DER TEST ERZWANG DAMIT
         * EINE RICHTLINIE, DIE DIE EIGENE OBERFLAECHE LAHMLEGT.
         *
         * Alpine, gebuendelt in Livewire, baut seinen Ausdrucks-Auswerter mit
         * `new Function` und `new AsyncFunction` -- nachgelesen im
         * ausgelieferten Bundle. Ohne diese Direktive wirft jedes `x-data`,
         * `x-show`, `x-on:click`: Dropdowns, Modale, Reiter, die gesamte
         * Bedienbarkeit von Filament.
         *
         * Aufgefallen war es nie, weil ein blockiertes Skript keine 500
         * erzeugt. Die Seite baut sich, der Test ist gruen, und der Schaden
         * steht in der Browserkonsole.
         *
         * `unsafe-inline` bleibt verboten -- dafuer gibt es die Hashes in
         * config/csp.php, siehe CspScriptHashesTest.
         * ─────────────────────────────────────────────────────────────────────
         */
        $this->assertStringContainsString(
            'unsafe-eval',
            $scripts[1],
            'Ohne unsafe-eval laeuft kein Alpine -- und damit kein Filament.',
        );
    }

    /**
     * Die Kamera ist frei — für diese Herkunft und für nichts sonst.
     *
     * ─────────────────────────────────────────────────────────────────────────
     * WARUM DAS EIN EIGENER TEST IST: Ein `camera=()` schaltet die Kamera für
     * die ganze Anwendung ab, und zwar SO, DASS DIE ERLAUBNIS DES NUTZERS
     * NICHTS MEHR NÜTZT. Der Mensch am Regal klickt „erlauben", und es
     * passiert trotzdem nichts — ein Fehler, den niemand am Bildschirm
     * versteht und den kein Rendering-Test findet.
     *
     * Genauso wichtig ist die Gegenrichtung: Mikrofon und Standort bleiben
     * aus. Wer die Kamera freischaltet, ist einen Handgriff davon entfernt,
     * versehentlich alles freizuschalten.
     * ─────────────────────────────────────────────────────────────────────────
     */
    #[Test]
    public function the_camera_is_allowed_but_nothing_else_is(): void
    {
        $policy = (string) $this->get('/up')->headers->get('Permissions-Policy');

        $this->assertStringContainsString('camera=(self)', $policy, 'Ohne das scannt niemand.');

        foreach (['microphone', 'geolocation', 'payment'] as $abgeschaltet) {
            $this->assertStringContainsString(
                $abgeschaltet.'=()',
                $policy,
                sprintf('%s hat in einer Werkstattverwaltung nichts zu suchen.', $abgeschaltet),
            );
        }
    }

    #[Test]
    public function hsts_is_only_sent_over_https(): void
    {
        /*
         * Über eine unverschlüsselte Verbindung ist der Header wirkungslos --
         * im lokalen Betrieb ohne Zertifikat nagelte er einen Entwickler
         * dagegen für ein Jahr auf HTTPS fest, localhost eingeschlossen.
         */
        /*
         * BEIDE ADRESSEN AUSGESCHRIEBEN, und das ist der Punkt: ob ein
         * relatives "/up" als sicher gilt, entscheidet APP_URL -- hier http,
         * in der CI https. Der Test fiel deshalb genau dort um, wo er etwas
         * beweisen sollte. Eine Zusicherung über das Schema muss das Schema
         * nennen.
         */
        $this->assertFalse(
            $this->get('http://aeronance.test/up')->headers->has('Strict-Transport-Security'),
        );

        $secure = $this->get('https://aeronance.test/up');

        $this->assertStringContainsString(
            'max-age=31536000',
            (string) $secure->headers->get('Strict-Transport-Security'),
        );
    }

    #[Test]
    public function the_second_factor_is_stored_encrypted(): void
    {
        /*
         * ─────────────────────────────────────────────────────────────────────
         * EIN TOTP-GEHEIMNIS IST KEIN HASH. Wer es liest, erzeugt jeden Code,
         * den der Benutzer erzeugt -- ein Datenbank-Abzug hebelte damit den
         * zweiten Faktor für alle Konten aus, ohne ein einziges Passwort zu
         * brechen.
         *
         * Geprüft wird deshalb an der Datenbank vorbei am Modell: der Wert in
         * der Spalte darf nicht der Wert sein, den das Modell zurückgibt.
         * ─────────────────────────────────────────────────────────────────────
         */
        $user = User::factory()->create();

        $user->saveAppAuthenticationSecret('JBSWY3DPEHPK3PXP');
        $user->saveAppAuthenticationRecoveryCodes(['erster-code', 'zweiter-code']);

        $stored = (array) \DB::table('users')->where('id', $user->id)->first();

        $this->assertNotSame('JBSWY3DPEHPK3PXP', $stored['app_authentication_secret']);
        $this->assertStringNotContainsString('JBSWY3DPEHPK3PXP', (string) $stored['app_authentication_secret']);
        $this->assertStringNotContainsString('erster-code', (string) $stored['app_authentication_recovery_codes']);

        // Und über das Modell kommt es unverändert zurück.
        $fresh = $user->fresh();
        $this->assertSame('JBSWY3DPEHPK3PXP', $fresh->getAppAuthenticationSecret());
        $this->assertSame(['erster-code', 'zweiter-code'], $fresh->getAppAuthenticationRecoveryCodes());
    }

    #[Test]
    public function the_panel_offers_the_second_factor_without_forcing_it(): void
    {
        /*
         * Angeboten, nicht erzwungen: ein Verein, der es für alle
         * scharfschaltet, sperrt beim Erstkontakt jeden aus, der kein Smartphone
         * dabei hat. Wer muss, setzt isRequired -- diese Zusicherung hält fest,
         * dass die Vorgabe eine Entscheidung war.
         */
        $panel = Filament::getPanel('admin');

        $this->assertNotEmpty($panel->getMultiFactorAuthenticationProviders());
        $this->assertFalse($panel->isMultiFactorAuthenticationRequired());
    }
}
