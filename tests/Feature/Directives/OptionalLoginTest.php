<?php

declare(strict_types=1);

namespace Tests\Feature\Directives;

use App\Modules\Directives\Filament\Pages\SourceCredentialsPage;
use App\Modules\Directives\Sources\Configured\ConfiguredSource;
use App\Modules\Directives\Sources\Configured\SourceSpec;
use App\Modules\Directives\Sources\SessionFetcher;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

/**
 * Ein Login, den die Quelle auch WEGLASSEN kann.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * C.E.A.P.R. ist der Anlass: Die Liste antwortet anonym vollständig (gemessen,
 * 286 Zeilen), aber hinter demselben Portal liegt ein Abonnentenbereich, und
 * der Feldtest verlangt den Zugang: „der ceapr login … sind noch nicht drin."
 *
 * Die Gefahr an einem nachgerüsteten Login ist die falsche Richtung: Ein
 * `auth:`-Profil machte die Quelle bisher UNBRAUCHBAR ohne Zugangsdaten --
 * damit hätte jeder Verein OHNE Abo eine funktionierende Quelle verloren, um
 * sie Vereinen MIT Abo zu geben. `login.optional` löst genau das auf, und
 * diese Tests halten beide Richtungen fest.
 * ─────────────────────────────────────────────────────────────────────────────
 */
final class OptionalLoginTest extends TestCase
{
    private const LOGIN = 'https://example.test/espace-client/login';

    private const TARGET = 'https://example.test/publications/liste';

    protected function tearDown(): void
    {
        config(['aeronance.directive_credentials' => []]);

        parent::tearDown();
    }

    #[Test]
    public function without_credentials_the_source_stays_anonymous_and_usable(): void
    {
        Http::fake([
            self::TARGET => Http::response($this->liste()),
        ]);

        $spec = $this->spec();
        $source = new ConfiguredSource($spec, new SessionFetcher($spec));

        // Nicht verriegelt: genau der Unterschied zu einer Pflicht-Anmeldung.
        $this->assertTrue($source->isUsable());

        $rows = $source->fetch(['model' => 'DR 400/180']);

        $this->assertNotEmpty($rows);
        $this->assertSame('SB 119', $rows[0]->number);

        // Und zwar OHNE einen einzigen Griff zur Anmeldeseite.
        Http::assertNotSent(fn (Request $r): bool => str_contains($r->url(), 'espace-client'));

        /*
         * Die Anfrage muss AUSSEHEN wie bisher: Der anonyme Abruf lief vor dem
         * Login-Umbau durch GuzzleFetcher, und dessen POST trägt JSON-zuerst
         * plus X-Requested-With -- gemessen wurde C.E.A.P.R. genau so. Das
         * Review fand den Verlust dieser Header beim Fetcher-Wechsel.
         */
        Http::assertSent(fn (Request $r): bool => $r->url() === self::TARGET
            && ($r->header('X-Requested-With')[0] ?? '') === 'XMLHttpRequest'
            && str_starts_with($r->header('Accept')[0] ?? '', 'application/json'));
    }

    #[Test]
    public function with_credentials_the_login_runs_first_and_the_cookie_carries(): void
    {
        config(['aeronance.directive_credentials' => [
            'DIRECTIVES_OPTTEST_USER' => 'verein',
            'DIRECTIVES_OPTTEST_PASSWORD' => 'abo-geheim',
        ]]);

        Http::fake([
            self::LOGIN => Http::sequence()
                ->push($this->loginSeite())
                ->push('', 303, [
                    'Location' => 'https://example.test/espace-client',
                    'Set-Cookie' => 'PHPSESSID=abo42; path=/',
                ]),
            'https://example.test/espace-client' => Http::response('<html>Espace</html>'),
            self::TARGET => Http::response($this->liste()),
        ]);

        $spec = $this->spec();
        $rows = (new ConfiguredSource($spec, new SessionFetcher($spec)))
            ->fetch(['model' => 'DR 400/180']);

        $this->assertNotEmpty($rows);

        /*
         * Das Formular trägt ein LEERES action -- wie bei C.E.A.P.R. Der POST
         * muss deshalb an die Anmeldeadresse selbst gehen; scheme://host allein
         * schickte die Zugangsdaten an die Wurzel der Seite. Und er imitiert
         * einen BROWSER, kein AJAX: X-Requested-With gehört auf die
         * Endpoint-POSTs, nicht auf das Anmeldeformular.
         */
        Http::assertSent(fn (Request $r): bool => $r->method() === 'POST'
            && $r->url() === self::LOGIN
            && str_contains($r->body(), 'login_ident=verein')
            && str_contains($r->body(), 'valid=')
            && ($r->header('X-Requested-With')[0] ?? '') === '');

        // Die Liste wird danach ALS ABONNENT gelesen: mit der Sitzung von eben
        // -- und NUR mit ihr. Das Abo-Passwort reist nicht als Basic-Header
        // auf jeder Listen-Anfrage mit (Review-Fund: authHeaders() kannte den
        // Unterschied zwischen Basic-Auth- und Formular-Login-Quellen nicht).
        Http::assertSent(fn (Request $r): bool => $r->url() === self::TARGET
            && str_contains($r->header('Cookie')[0] ?? '', 'PHPSESSID=abo42')
            && ($r->header('Authorization')[0] ?? '') === '');
    }

    #[Test]
    public function a_redirect_to_another_host_is_refused_not_followed(): void
    {
        // Die Sitzung folgt keinem Hostwechsel: Der nächste Schritt trüge das
        // Cookie -- und auf dem Weg zu http:// trüge er es lesbar.
        config(['aeronance.directive_credentials' => [
            'DIRECTIVES_OPTTEST_USER' => 'verein',
            'DIRECTIVES_OPTTEST_PASSWORD' => 'abo-geheim',
        ]]);

        Http::fake([
            self::LOGIN => Http::sequence()
                ->push($this->loginSeite())
                ->push('', 303, [
                    'Location' => 'http://boese.example/espace-client',
                    'Set-Cookie' => 'PHPSESSID=abo42; path=/',
                ]),
        ]);

        $spec = $this->spec();

        try {
            (new SessionFetcher($spec))->post(self::TARGET, ['nav' => '']);
            $this->fail('Ein Hostwechsel in der Anmeldung muss abbrechen.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('another host', $e->getMessage());
        }

        Http::assertNotSent(fn (Request $r): bool => str_contains($r->url(), 'boese.example'));
    }

    #[Test]
    public function half_a_login_is_an_error_not_a_silent_fallback(): void
    {
        // Ein Benutzername ohne Passwort ist ein Versehen, das jemand bemerken
        // muss -- wer es eintrug, erwartet den Abonnenten-Abruf und bekäme
        // sonst wortlos den anonymen.
        config(['aeronance.directive_credentials' => [
            'DIRECTIVES_OPTTEST_USER' => 'verein',
        ]]);

        Http::fake();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/needs a login/i');

        $spec = $this->spec();
        (new SessionFetcher($spec))->post(self::TARGET, ['nav' => '']);
    }

    #[Test]
    public function ceapr_is_offered_on_the_credentials_page_schempp_is_demanded(): void
    {
        $profiles = SourceCredentialsPage::gatedSources();

        $this->assertArrayHasKey('ceapr', $profiles);
        $this->assertTrue($profiles['ceapr']['optional'], 'CEAPR ist ein Angebot, kein Zwang.');

        $this->assertArrayHasKey('schempp', $profiles);
        $this->assertFalse(
            $profiles['schempp']['optional'],
            'Schempp-Hirth verlangt den Zugang weiterhin -- optional gilt je Profil, nicht pauschal.',
        );
    }

    private function spec(): SourceSpec
    {
        // Die C.E.A.P.R.-Form, unter Testnamen: JSON nur auf POST, Login
        // optional. Das echte ceapr.yaml prüft der Test darüber am Registry.
        return SourceSpec::fromArray([
            'name' => 'optional-test',
            'label' => 'Optional (Test)',
            'type' => 'json',
            'auth' => 'opttest',
            'login' => [
                'url' => self::LOGIN,
                'user_field' => 'login_ident',
                'password_field' => 'mdp_ident',
                'extra' => ['valid' => ''],
                'optional' => true,
            ],
            'endpoint' => [
                'url' => self::TARGET,
                'method' => 'POST',
                'body' => ['nav' => ''],
            ],
            'columns' => [
                'number' => 'titre',
                'title' => 'descriptif',
                'model' => 'navigabilite',
                'document' => 'fic',
            ],
            'document_url' => 'https://example.test/fic/{document}',
        ], 'optional-test.yaml');
    }

    private function liste(): string
    {
        return (string) json_encode([
            '0' => [
                'titre' => 'SB 119',
                'descriptif' => 'Vérification du guignol de profondeur',
                'navigabilite' => 'DR 400/180',
                'fic' => '1000-sb119.pdf',
            ],
        ]);
    }

    private function loginSeite(): string
    {
        // Vier Formulare wie auf der echten Seite: drei Passwort-vergessen-Modale
        // OHNE action-Attribut, dann das Anmeldeformular mit LEEREM action.
        return '<html><body>'
            .'<form method="post" id="form_mdp_perdu"><input type="text" name="email_mdp_perdu"></form>'
            .'<form method="post" id="form_mdp_pieces"><input type="text" name="email_mdp_pieces"></form>'
            .'<form method="post" id="form_mdp_abo"><input type="text" name="email_mdp_abo"></form>'
            .'<form method="post" action="">'
            .'<input type="text" name="login_ident">'
            .'<input type="password" name="mdp_ident">'
            .'<button type="submit" name="valid">Valider</button>'
            .'<input type="hidden" name="langue" value="">'
            .'</form>'
            .'</body></html>';
    }
}
