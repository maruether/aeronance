<?php

declare(strict_types=1);

namespace Tests\Feature\Core;

use App\Core\Updates\ReleaseCheck;
use App\Core\Version;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * „Gibt es eine neuere Fassung?"
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * Vorgabe: „ich hätte gerne ein auto update das auf GitHub zugreift."
 *
 * WAS DIESE TESTS NICHT BEWEISEN KÖNNEN: dass es funktioniert. Am 2026-08-05
 * gab es weder einen GitHub-Spiegel noch einen einzigen Release-Tag — die
 * Gegenstelle existiert nicht. Geprüft ist deshalb das Verhalten gegen eine
 * NACHGEBILDETE Antwort in der Form, die die GitHub-API liefert. Der Endbeweis
 * kommt mit dem ersten echten Release.
 *
 * Der wichtigste Test hier ist `a_development_build_is_never_told_to_update`:
 * Eine Installation ohne eigene Versionsnummer darf nicht bei jeder
 * Veröffentlichung zum Update auffordern.
 * ─────────────────────────────────────────────────────────────────────────────
 */
final class UpdateCheckTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Version::forget();
        Cache::flush();

        config()->set('aeronance.updates.repository', 'maruether/aeronance');
        config()->set('aeronance.updates.check', true);
    }

    protected function tearDown(): void
    {
        $this->removeVersionFile();
        Version::forget();

        parent::tearDown();
    }

    #[Test]
    public function it_reads_the_latest_release(): void
    {
        $this->githubAnswers('v1.4.0');

        $this->assertSame('v1.4.0', app(ReleaseCheck::class)->latest());
    }

    #[Test]
    public function a_newer_release_is_reported(): void
    {
        $this->installedVersion('v1.2.0');
        $this->githubAnswers('v1.4.0');

        $this->assertTrue(app(ReleaseCheck::class)->updateAvailable());
        $this->assertSame(1, app(ReleaseCheck::class)->compare());
    }

    #[Test]
    public function the_same_release_is_not_an_update(): void
    {
        $this->installedVersion('v1.4.0');
        $this->githubAnswers('v1.4.0');

        $this->assertFalse(app(ReleaseCheck::class)->updateAvailable());
        $this->assertSame(0, app(ReleaseCheck::class)->compare());
    }

    /**
     * DER TEST, UM DEN ES GEHT.
     *
     * Ohne VERSION-Datei weiß die Installation nicht, was sie ist — das ist der
     * Entwicklungsstand. Jede Veröffentlichung als „neuer" zu melden hiesse,
     * jeden Entwickler und jedes von Hand ausgecheckte Repo zum Update
     * aufzufordern, und zwar für immer.
     */
    #[Test]
    public function a_development_build_is_never_told_to_update(): void
    {
        $this->removeVersionFile();
        Version::forget();
        $this->githubAnswers('v9.9.9');

        $this->assertNull(Version::current());
        $this->assertNull(app(ReleaseCheck::class)->compare(), 'Unbekannt ist nicht "veraltet".');
        $this->assertFalse(app(ReleaseCheck::class)->updateAvailable());
    }

    /**
     * Eine unbrauchbare VERSION-Datei ist wie keine.
     *
     * Geraten wird bei Versionsnummern nicht.
     */
    #[Test]
    public function a_nonsense_version_file_counts_as_none(): void
    {
        $this->installedVersion('irgendwas');

        $this->assertNull(Version::current());
    }

    /**
     * KEIN INTERNET IST KEIN FEHLER.
     *
     * Die Instanz sitzt hinter einem Filter, GitHub ist weg, es gibt noch keine
     * Veröffentlichung — alles normale Betriebszustände. Die Prüfung sagt dann
     * „weiß nicht" und wirft nicht.
     */
    #[Test]
    public function an_unreachable_github_is_not_an_error(): void
    {
        $this->installedVersion('v1.0.0');
        Http::fake(['api.github.com/*' => Http::response('', 503)]);

        $this->assertNull(app(ReleaseCheck::class)->latest());
        $this->assertNull(app(ReleaseCheck::class)->compare());
        $this->assertFalse(app(ReleaseCheck::class)->updateAvailable());
    }

    /**
     * Ein PRIVATES Repository antwortet mit 404 — und das ist kein Fehler.
     *
     * Die GitHub-API unterscheidet ohne Anmeldung nicht zwischen „gibt es
     * nicht" und „darfst du nicht sehen". Solange der Spiegel privat ist,
     * bleibt die Prüfung deshalb stumm; sie soll nicht raten.
     */
    #[Test]
    public function a_private_repository_is_not_an_error(): void
    {
        $this->installedVersion('v1.0.0');
        Http::fake(['api.github.com/*' => Http::response(['message' => 'Not Found'], 404)]);

        $this->assertNull(app(ReleaseCheck::class)->latest());
    }

    /**
     * Abgeschaltet heißt abgeschaltet — es geht keine Anfrage hinaus.
     *
     * Ein Verein, der nicht möchte, dass seine Installation nach draußen
     * telefoniert, hat das Recht dazu, und ein Schalter, der trotzdem fragt,
     * wäre ein Wortbruch.
     */
    #[Test]
    public function switched_off_means_no_request_at_all(): void
    {
        config()->set('aeronance.updates.check', false);
        Http::fake();

        $this->assertNull(app(ReleaseCheck::class)->latest());

        Http::assertNothingSent();
    }

    /**
     * Gefragt wird einmal, nicht bei jeder Seitenanzeige.
     *
     * Ohne Zwischenspeicher liefe das in die Ratenbegrenzung von GitHub — und
     * meldete einem Dritten den Betrieb jeder einzelnen Instanz.
     */
    #[Test]
    public function the_answer_is_cached(): void
    {
        $this->githubAnswers('v1.4.0');

        $check = app(ReleaseCheck::class);
        $check->latest();
        $check->latest();
        $check->latest();

        Http::assertSentCount(1);
    }

    #[Test]
    public function the_command_reports_a_newer_release(): void
    {
        $this->installedVersion('v1.2.0');
        $this->githubAnswers('v1.4.0');

        $this->artisan('aeronance:update-check')
            ->expectsOutputToContain('v1.4.0')
            ->assertFailed();
    }

    #[Test]
    public function the_command_is_content_when_up_to_date(): void
    {
        $this->installedVersion('v1.4.0');
        $this->githubAnswers('v1.4.0');

        $this->artisan('aeronance:update-check')->assertSuccessful();
    }

    /**
     * DIE HÖCHSTE FASSUNG GEWINNT, NICHT DIE ERSTE.
     *
     * GitHub sagt über die Reihenfolge seiner Tag-Liste nichts zu. Und
     * alphabetisch ist `v1.10.0` kleiner als `v1.9.0` — genau daran scheitern
     * solche Prüfungen beim zehnten Release, also lange nachdem jemand
     * hingesehen hat.
     */
    #[Test]
    public function the_highest_version_wins_not_the_first(): void
    {
        $this->installedVersion('v1.9.0');
        $this->githubAnswers('v1.9.0', 'v1.10.0', 'v1.2.0');

        $this->assertSame('v1.10.0', app(ReleaseCheck::class)->latest());
        $this->assertTrue(app(ReleaseCheck::class)->updateAvailable());
    }

    /**
     * Fremde Tags stören nicht.
     *
     * In einem Repository stehen auch Marken, die keine Fassung sind --
     * `nightly`, `stable`, der Name eines Zweigs. Sie zu übernehmen hiesse,
     * eine Installation auf etwas hinzuweisen, das kein Release ist.
     */
    #[Test]
    public function tags_that_are_not_versions_are_ignored(): void
    {
        $this->installedVersion('v1.0.0');
        $this->githubAnswers('nightly', 'stable', 'v1.1.0', 'irgendwas');

        $this->assertSame('v1.1.0', app(ReleaseCheck::class)->latest());
    }

    /**
     * Ein Repository ohne Tags meldet nichts — und das ist kein Fehler.
     *
     * Der Zustand am 2026-08-05: Es gab keinen einzigen Tag.
     */
    #[Test]
    public function a_repository_without_tags_reports_nothing(): void
    {
        $this->installedVersion('v1.0.0');
        Http::fake(['api.github.com/*' => Http::response([], 200)]);

        $this->assertNull(app(ReleaseCheck::class)->latest());
    }

    // ── Der Hinweis in der Oberfläche ────────────────────────────────────────

    /**
     * DER TEST, UM DEN ES BEIM WIDGET GEHT: Es fragt NICHT nach.
     *
     * Würde es selbst bei GitHub anfragen, müsste die erste Seitenanzeige nach
     * einem Neustart darauf warten — und wenn GitHub gerade nicht antwortet,
     * bis zur Zeitüberschreitung. Eine Werkstattverwaltung, die langsam
     * startet, weil sie nach Updates schaut, hat die Verhältnisse verkehrt.
     */
    #[Test]
    public function the_interface_never_asks_github_itself(): void
    {
        $this->installedVersion('v1.0.0');
        Http::fake();

        $check = app(ReleaseCheck::class);

        $this->assertNull($check->known(), 'Ohne gefuellten Zwischenspeicher gibt es nichts zu melden.');
        $this->assertFalse($check->updateKnown());

        Http::assertNothingSent();
    }

    /**
     * Was der nächtliche Lauf hinterlegt hat, sieht die Oberfläche.
     */
    #[Test]
    public function what_the_nightly_run_found_reaches_the_interface(): void
    {
        $this->installedVersion('v1.0.0');
        $this->githubAnswers('v1.4.0');

        // Der geplante Lauf -- er fuellt den Zwischenspeicher.
        app(ReleaseCheck::class)->latest();

        Http::fake();

        $check = app(ReleaseCheck::class);

        $this->assertSame('v1.4.0', $check->known());
        $this->assertTrue($check->updateKnown());

        Http::assertNothingSent();
    }

    /**
     * Und im Entwicklungsstand meldet die Oberfläche nichts.
     *
     * Sonst stünde bei jedem Entwickler und jedem von Hand ausgecheckten Repo
     * dauerhaft eine Aufforderung, die nicht zutrifft.
     */
    #[Test]
    public function a_development_build_shows_no_notice(): void
    {
        $this->removeVersionFile();
        Version::forget();
        $this->githubAnswers('v9.9.9');
        app(ReleaseCheck::class)->latest();

        $this->assertFalse(app(ReleaseCheck::class)->updateKnown());
    }

    /**
     * Die GitHub-Antwort auf `/tags`, nicht auf `/releases/latest`.
     *
     * Ein Push-Mirror überträgt Refs; ein GitHub-*Release* ist ein eigenes
     * Objekt, das dabei nie entsteht. Wer `/releases/latest` fragt, bekommt auf
     * einem Spiegel für immer 404.
     */
    private function githubAnswers(string ...$tags): void
    {
        Http::fake([
            'api.github.com/*' => Http::response(
                array_map(static fn (string $t): array => ['name' => $t], $tags),
                200,
            ),
        ]);
    }

    private function installedVersion(string $inhalt): void
    {
        file_put_contents(base_path('VERSION'), $inhalt."\n");
        Version::forget();
    }

    private function removeVersionFile(): void
    {
        if (is_file(base_path('VERSION'))) {
            unlink(base_path('VERSION'));
        }
    }
}
