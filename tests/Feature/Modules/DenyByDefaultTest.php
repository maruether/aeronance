<?php

declare(strict_types=1);

namespace Tests\Feature\Modules;

use App\Core\Access\AccessSetup;
use App\Core\Filament\Auth\EditProfile;
use App\Models\User;
use App\Modules\Part66\Filament\Pages\ExperienceLogPage;
use Filament\Facades\Filament;
use Filament\Pages\Dashboard;
use Filament\Pages\Page;
use Filament\Resources\Resource;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\RendersModulePages;
use Tests\TestCase;
use Throwable;

/**
 * Jeder Bildschirm, den das Panel kennt — und wer ihn NICHT sehen darf.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * DIE LEITPLANKE: „AuthZ deny-by-default: Jede Filament-Resource, Route und
 * Action hat eine Policy. Rechte, die nur im UI versteckt sind, gelten als
 * nicht vorhanden." Und: „mindestens AuthZ-Negativtests pro Resource."
 *
 * Es GAB solche Tests — je Modul einen, mit einer von Hand gepflegten Liste.
 * Genau daran ist es gescheitert: Der Lagertest zählte vier Ressourcen auf,
 * das Modul hat sechs. `RepairDispatchResource` und `StockMovementResource`
 * standen in keiner Negativprüfung, nicht aus Nachlässigkeit, sondern weil sie
 * nach dem Test dazukamen. Eine handgepflegte Liste driftet immer, und sie
 * driftet still: Der Test bleibt grün, während die Lücke wächst.
 *
 * DESHALB ZÄHLT DIESER TEST NICHT AUF, SONDERN FRAGT DAS PANEL. Was registriert
 * ist, wird geprüft — eine neue Ressource ist am Tag ihrer Entstehung abgedeckt,
 * ohne dass jemand daran denken muss.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * WAS ER NICHT PRÜFT, und das gehört gesagt statt verschwiegen: Bearbeiten-
 * und Ansehen-Bildschirme brauchen einen Datensatz in der Adresse. Für jede
 * der 19 Ressourcen einen zu bauen — mit allen Pflichtfeldern und Beziehungen
 * — ist ein eigener Test je Modul und steht dort, wo die Fachlichkeit steht.
 * Abgedeckt sind Liste und Anlegen jeder Ressource sowie jede Seite: die
 * Fläche, die man ohne Vorwissen erreicht. Wer eine Datensatz-Adresse rät,
 * braucht erst eine gültige ID.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * GEPRÜFT WIRD MIT EINEM KONTO OHNE JEDES RECHT: angemeldet, aktiv, aber ohne
 * Rolle und ohne Berechtigung. Das ist der Angreifer, den es realistisch gibt —
 * ein Vereinsmitglied, das eine Adresse errät oder aus einem Lesezeichen
 * aufruft. Nicht der Unangemeldete: Den weist schon die Anmeldung ab, und
 * dieser Test soll die Schicht DAHINTER prüfen.
 * ─────────────────────────────────────────────────────────────────────────────
 */
#[Group('rendering')]
final class DenyByDefaultTest extends TestCase
{
    use RendersModulePages;

    /**
     * Bildschirme, die jedes angemeldete Konto sehen darf — mit Begründung.
     *
     * Diese Liste ist der einzige handgepflegte Teil, und das ist Absicht: Was
     * hier steht, ist eine bewusste Entscheidung und muss eine haben. Alles
     * andere ist automatisch verboten.
     *
     * @var array<class-string, string>
     */
    private const OFFEN_FUER_ALLE = [
        Dashboard::class => 'Die Startseite. Sie zeigt nur Widgets, '
            .'und die entscheiden selbst, wer sie sieht.',
        EditProfile::class => 'Das eigene Profil. Wer sich '
            .'anmelden kann, muss sein Passwort ändern und den zweiten Faktor '
            .'einschalten können.',

        /*
         * Das eigene Erfahrungslogbuch gehört einem selbst — es ist die
         * Auswertung der eigenen Arbeit, kein fremder Datenbestand. Die Sperre
         * sitzt hier nicht auf der SEITE, sondern auf der Frage, WESSEN Logbuch:
         * `person()` fällt auf den Betrachter zurück, `peopleOptions()` gibt
         * ohne `part66.logs.view_all` eine leere Liste, und der Druckweg im
         * Controller bricht mit 403 ab. Geprüft in LogAccessTest, unter anderem
         * mit einem manipulierten Parameter.
         *
         * Diese Zeile steht hier, weil dieser Test sie erzwungen hat: Vorher
         * stand die Entscheidung nur im Docblock einer Seite.
         */
        ExperienceLogPage::class => 'Das eigene Logbuch darf jeder sehen; die '
            .'Sperre liegt auf WESSEN Logbuch, nicht auf der Seite.',
    ];

    /**
     * @return list<string>
     */
    protected function modulesUnderTest(): array
    {
        return ['warehouse', 'fleet', 'taskcards', 'part66', 'directives', 'vereinsflieger', 'inspection', 'tooling'];
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->bootWithModules();

        app(AccessSetup::class)->run();
    }

    /**
     * Kein Bildschirm antwortet einem Konto ohne Rechte.
     *
     * ALLES IN EINER METHODE, und das ist keine Bequemlichkeit: Jeder
     * Durchgang baut die Anwendung neu, damit die Modulrouten entstehen
     * (siehe RendersModulePages) — gemessen rund 70 Sekunden. Vier Methoden
     * wären vier Neustarts für dieselbe Aussage.
     *
     * Drei Fragen je Ressource, und alle müssen nein sagen:
     *
     *   canViewAny()  — was die Navigation fragt. Sagt sie ja, steht der
     *                   Eintrag im Menü.
     *   die Liste     — was ein Mensch tut, der die Adresse kennt. DIESE Frage
     *                   ist die eigentliche: Ein fehlender Menüeintrag bei
     *                   einer Seite, die antwortet, ist keine Sicherung.
     *   das Anlegen   — eine Schreibfläche mit eigener Adresse. Wer nur die
     *                   Liste sichert, hat die Hälfte gesichert.
     *
     * Seiten sind der leisere Fall: Eine fehlende Ressource fällt auf, eine
     * Buchungsseite wie „Einlagern" nicht. Ihre Wirkung ist dieselbe — dort
     * wird gebucht.
     */
    #[Test]
    public function no_screen_answers_an_account_without_permissions(): void
    {
        $ressourcen = $this->resources();
        $seiten = $this->pages();

        /*
         * ZUERST DER GEGENBEWEIS. Ohne ihn wäre dieser Test auch dann grün,
         * wenn das Panel gar keine Bildschirme hätte oder jede Adresse ins
         * Leere liefe -- „überall verboten" ist bei null Bildschirmen trivial
         * wahr.
         */
        $this->assertGreaterThan(15, count($ressourcen), 'Zu wenige Ressourcen — stimmt der Panel-Bau?');
        $this->assertGreaterThan(8, count($seiten), 'Zu wenige Seiten — stimmt der Panel-Bau?');

        $this->actingAs($this->accountWithoutAnyPermission());

        $luecken = [];

        foreach ($ressourcen as $resource) {
            if (array_key_exists($resource, self::OFFEN_FUER_ALLE)) {
                continue;
            }

            if ($resource::canViewAny()) {
                $luecken[] = class_basename($resource).' -> canViewAny() sagt ja';
            }

            $luecken = [...$luecken, ...$this->refused(
                class_basename($resource),
                fn (): string => $resource::getUrl('index'),
            )];

            if (array_key_exists('create', $resource::getPages())) {
                if ($resource::canCreate()) {
                    $luecken[] = class_basename($resource).' -> canCreate() sagt ja';
                }

                $luecken = [...$luecken, ...$this->refused(
                    class_basename($resource).' (anlegen)',
                    fn (): string => $resource::getUrl('create'),
                )];
            }
        }

        foreach ($seiten as $page) {
            if (array_key_exists($page, self::OFFEN_FUER_ALLE)) {
                continue;
            }

            if ($page::canAccess()) {
                $luecken[] = class_basename($page).' -> canAccess() sagt ja';
            }

            $luecken = [...$luecken, ...$this->refused(
                class_basename($page),
                fn (): string => $page::getUrl(),
            )];
        }

        sort($luecken);

        $this->assertSame([], $luecken, sprintf(
            "Diese Bildschirme antworten einem Konto ohne jedes Recht:\n  %s",
            implode("\n  ", $luecken),
        ));
    }

    /**
     * Ruft die Adresse auf und meldet, wenn sie ANTWORTET.
     *
     * Erwartet wird eine Abweisung: 403, oder eine Umleitung fort von der Seite.
     * Eine Ausnahme beim Aufbau der Adresse zählt NICHT als Abweisung — sie
     * verdeckte einen Fehler, statt eine Sicherung zu belegen.
     *
     * @param  callable(): string  $url
     * @return list<string>
     */
    private function refused(string $name, callable $url): array
    {
        try {
            $adresse = $url();
        } catch (Throwable $e) {
            return [sprintf('%s -> Adresse nicht baubar: %s', $name, $e->getMessage())];
        }

        try {
            $antwort = $this->get($adresse);
        } catch (Throwable $e) {
            /*
             * Eine geworfene Ausnahme IST eine Abweisung, solange sie aus der
             * Rechteschicht kommt. Filament wirft bei fehlendem Zugriff je nach
             * Bildschirm entweder eine 403-Antwort oder eine Ausnahme.
             */
            return $this->looksLikeDenial($e)
                ? []
                : [sprintf('%s -> %s: %s', $name, class_basename($e), $e->getMessage())];
        }

        if ($antwort->isSuccessful()) {
            return [sprintf('%s -> HTTP 200 (%s)', $name, $adresse)];
        }

        return [];
    }

    private function looksLikeDenial(Throwable $e): bool
    {
        return $e instanceof HttpException
            && $e->getStatusCode() === 403;
    }

    /**
     * @return list<class-string<resource>>
     */
    private function resources(): array
    {
        return array_values(Filament::getPanel('admin')->getResources());
    }

    /**
     * @return list<class-string<Page>>
     */
    private function pages(): array
    {
        return array_values(Filament::getPanel('admin')->getPages());
    }

    /**
     * Angemeldet, aktiv — und sonst nichts.
     */
    private function accountWithoutAnyPermission(): User
    {
        return User::factory()->create(['is_active' => true]);
    }
}
