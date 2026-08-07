<?php

declare(strict_types=1);

namespace Tests\Feature\Core;

use App\Core\Identity\DiscoveredGroup;
use App\Core\Identity\ExternalGroup;
use App\Core\Identity\RememberExternalGroups;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Die gefundenen Gruppen -- gespeichert, damit die Auswahl dynamisch ist.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * Vorgabe: „es geht bei der zuordnung um die funktionen die der verein angelegt
 * hat. die ui muss entsprechend dynamisch sein."
 *
 * Geprüft wird hier ohne Connector: Was ein Provider meldet, ist ein Argument.
 * ─────────────────────────────────────────────────────────────────────────────
 */
final class ExternalGroupDiscoveryTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function found_groups_are_remembered(): void
    {
        $ergebnis = $this->remember([
            new DiscoveredGroup('Werkstattleiter', memberCount: 2),
            new DiscoveredGroup('Vorstand', memberCount: 3),
        ]);

        $this->assertSame(['seen' => 2, 'new' => 2], $ergebnis);
        $this->assertSame(2, ExternalGroup::query()->ofProvider('vf')->count());

        $werkstatt = ExternalGroup::query()->where('value', 'Werkstattleiter')->firstOrFail();
        $this->assertSame(2, $werkstatt->member_count);
        $this->assertNotNull($werkstatt->first_seen_at);
    }

    #[Test]
    public function a_second_run_updates_instead_of_duplicating(): void
    {
        $this->remember([new DiscoveredGroup('Vorstand', memberCount: 3)]);
        $ergebnis = $this->remember([new DiscoveredGroup('Vorstand', memberCount: 4)]);

        $this->assertSame(['seen' => 1, 'new' => 0], $ergebnis);
        $this->assertSame(1, ExternalGroup::query()->count());
        $this->assertSame(4, ExternalGroup::query()->firstOrFail()->member_count);
    }

    /**
     * Der Kern der Sache: Eine verschwundene Funktion behält ihre Zeile.
     *
     * Sonst verschwindet mit ihr die Erklärung, warum jemand einmal eine Rolle
     * hatte -- und die Zuordnung stünde da und zeigte ins Leere, ohne dass
     * irgendwo steht, warum.
     */
    #[Test]
    public function a_group_that_vanishes_is_kept_and_marked(): void
    {
        $this->remember([
            new DiscoveredGroup('Vorstand', memberCount: 3),
            new DiscoveredGroup('Kassenwart', memberCount: 1),
        ]);

        // Zweiter Abruf, ohne Kassenwart -- und eine Sekunde später, damit der
        // Vergleich der Zeitstempel überhaupt etwas zu vergleichen hat.
        $this->travel(1)->seconds();
        $this->remember([new DiscoveredGroup('Vorstand', memberCount: 3)]);

        $this->assertSame(2, ExternalGroup::query()->count());

        $vorstand = ExternalGroup::query()->where('value', 'Vorstand')->firstOrFail();
        $kassenwart = ExternalGroup::query()->where('value', 'Kassenwart')->firstOrFail();

        $this->assertSame(ExternalGroup::STATUS_CURRENT, $vorstand->status());
        $this->assertSame(ExternalGroup::STATUS_GONE, $kassenwart->status());
    }

    /**
     * Von Hand eingetragen ist nicht dasselbe wie verschwunden.
     *
     * Bei Vereinsflieger entsteht die Funktionsliste aus den Mitgliedern -- eine
     * frisch angelegte Funktion, die noch niemand trägt, ist über die API nicht
     * sichtbar. Sie deshalb wie eine gelöschte zu markieren wäre eine Warnung
     * über einen Zustand, der völlig in Ordnung ist.
     */
    #[Test]
    public function a_hand_written_group_is_unconfirmed_not_gone(): void
    {
        $this->remember([new DiscoveredGroup('Vorstand')]);

        $vonHand = ExternalGroup::create(['provider' => 'vf', 'value' => 'Jugendwart']);

        $this->assertSame(ExternalGroup::STATUS_UNCONFIRMED, $vonHand->status());
    }

    #[Test]
    public function providers_do_not_see_each_others_groups(): void
    {
        $this->remember([new DiscoveredGroup('Vorstand')]);
        app(RememberExternalGroups::class)->handle('ldap', [new DiscoveredGroup('Vorstand')]);

        $this->assertSame(1, ExternalGroup::query()->ofProvider('vf')->count());
        $this->assertSame(2, ExternalGroup::query()->count());
    }

    #[Test]
    public function an_empty_value_is_not_a_group(): void
    {
        // Ein ";" zu viel in der VF-Spalte ergäbe sonst eine namenlose Funktion
        // in der Auswahl -- und die wäre anklickbar.
        $ergebnis = $this->remember([
            new DiscoveredGroup('  '),
            new DiscoveredGroup('Vorstand'),
        ]);

        $this->assertSame(1, $ergebnis['seen']);
        $this->assertSame(1, ExternalGroup::query()->count());
    }

    /**
     * @param  list<DiscoveredGroup>  $gruppen
     * @return array{seen: int, new: int}
     */
    private function remember(array $gruppen): array
    {
        return app(RememberExternalGroups::class)->handle('vf', $gruppen);
    }
}
