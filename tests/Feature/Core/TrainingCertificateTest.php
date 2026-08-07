<?php

declare(strict_types=1);

namespace Tests\Feature\Core;

use App\Core\Access\AccessSetup;
use App\Core\Access\Authority;
use App\Core\Models\Qualification;
use App\Core\Modules\ModuleManager;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Schulungsnachweise am Menschen.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * DER TEST, UM DEN ES GEHT: `a_training_certificate_grants_nothing`.
 *
 * Ein Zertifikat sagt „diese Person wurde geschult", nicht „diese Person darf
 * freigeben". Authority entscheidet über eine Positivliste je Berechtigung, in
 * der nur die Lizenz und die Pilot-Owner-Berechtigung vorkommen — ein neuer Typ
 * ist damit von sich aus wirkungslos.
 *
 * Dieser Test hält das fest, weil genau hier die stille Rechteausweitung wäre:
 * Ergänzt jemand die Liste später um eine Zeile, um „auch Geschulte" zuzulassen,
 * schlägt er an. Ohne ihn fiele es niemandem auf.
 * ─────────────────────────────────────────────────────────────────────────────
 */
final class TrainingCertificateTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        /*
         * Die Module MIT, denn geprueft wird gegen ihre Rechte -- und genau
         * die sind es, die eine Qualifikation verlangen. Ohne sie gaebe es die
         * Berechtigungen gar nicht, und der Test waere gruen, ohne etwas
         * gezeigt zu haben.
         */
        foreach (['warehouse', 'fleet', 'taskcards', 'directives'] as $modul) {
            app(ModuleManager::class)->enable($modul);
        }
        app(ModuleManager::class)->forgetCache();

        app(AccessSetup::class)->run();
    }

    /**
     * DER TEST, UM DEN ES GEHT.
     */
    #[Test]
    public function a_training_certificate_grants_nothing(): void
    {
        $mensch = User::factory()->create(['is_active' => true]);

        /*
         * Alle Rechte, die eine Qualifikation verlangen -- aber als
         * Qualifikation NUR ein Schulungsnachweis.
         *
         * `releases.issue` steht zwar in Authoritys Liste, ist aber von keinem
         * Modul als Berechtigung registriert: ein Vorgriff auf das
         * Freigabemodul. Es hier zu vergeben schluege fehl, und es zu pruefen
         * waere gruen aus dem falschen Grund.
         */
        foreach ([
            'stock.quarantine.certify',
            'workorders.cards.certify',
            'directives.assess',
        ] as $recht) {
            $mensch->givePermissionTo($recht);
        }

        Qualification::create([
            'user_id' => $mensch->id,
            'type' => Qualification::TYPE_TRAINING,
            'subject' => 'Rotax 912 Service Training',
            'valid_from' => now()->subMonth()->toDateString(),
        ]);

        $authority = app(Authority::class);
        $mensch = $mensch->fresh();

        foreach ([
            'stock.quarantine.certify',
            'workorders.cards.certify',
            'directives.assess',
        ] as $recht) {
            $this->assertNull(
                $authority->qualificationFor($mensch, $recht),
                sprintf('Ein Schulungsnachweis darf "%s" nicht abdecken.', $recht),
            );
        }
    }

    /**
     * Und die Lizenz tut es weiterhin — sonst hätte der Test oben auch grün
     * ausgesehen, wenn die Prüfung insgesamt kaputt wäre.
     */
    #[Test]
    public function a_part66_licence_still_covers_what_it_covered(): void
    {
        $mensch = User::factory()->create(['is_active' => true]);
        $mensch->givePermissionTo('workorders.cards.certify');

        Qualification::create([
            'user_id' => $mensch->id,
            'type' => Qualification::TYPE_PART66,
            'reference' => 'DE.66.00000',
            'category' => 'B1',
            'valid_from' => now()->subYear()->toDateString(),
        ]);

        $this->assertNotNull(
            app(Authority::class)->qualificationFor($mensch->fresh(), 'workorders.cards.certify'),
        );
    }

    /**
     * Ein Zertifikat läuft ab wie alles andere auch.
     *
     * Bei Human Factors ist genau das der Punkt: Die Auffrischung ist alle zwei
     * Jahre fällig, und ohne Ablaufdatum wäre die Liste eine Sammlung alter
     * Urkunden.
     */
    #[Test]
    public function a_certificate_expires_like_everything_else(): void
    {
        $mensch = User::factory()->create(['is_active' => true]);

        $abgelaufen = Qualification::create([
            'user_id' => $mensch->id,
            'type' => Qualification::TYPE_TRAINING,
            'subject' => 'Human Factors Auffrischung',
            'valid_from' => now()->subYears(3)->toDateString(),
            'valid_until' => now()->subYear()->toDateString(),
        ]);

        $this->assertFalse($abgelaufen->isValidOn());
        $this->assertSame(
            0,
            Qualification::query()->ofType(Qualification::TYPE_TRAINING)->valid()->count(),
        );
    }

    /**
     * Gegenstand, Nummer und Aussteller sind DREI Dinge.
     *
     * Die erste Fassung schrieb den Gegenstand in `reference` — ein Feld, das
     * laut eigenem Hilfetext die Lizenznummer ist. Bei einer Lizenz fällt das
     * nicht auf, weil Nummer und Bezeichnung dort zusammenfallen; bei einem
     * Zertifikat sind es getrennte Angaben, und der Aussteller fehlte ganz.
     */
    #[Test]
    public function a_certificate_separates_subject_number_and_issuer(): void
    {
        $mensch = User::factory()->create(['is_active' => true]);

        $nachweis = Qualification::create([
            'user_id' => $mensch->id,
            'type' => Qualification::TYPE_TRAINING,
            'subject' => 'Klebeverfahren Faserverbund',
            'reference' => 'ZERT-2024-0815',
            'issuer' => 'Schulungsstelle Musterstadt',
            'valid_from' => now()->toDateString(),
        ]);

        $this->assertSame('Klebeverfahren Faserverbund', $nachweis->subject);
        $this->assertSame('ZERT-2024-0815', $nachweis->reference);
        $this->assertSame('Schulungsstelle Musterstadt', $nachweis->issuer);
    }

    /**
     * Und es passt alles hinein, nicht nur Musterschulungen.
     *
     * Vorgabe: „mir fallen da auch noch andere ein, das war ein beispiel." Es
     * gibt deshalb weder Auswahlliste noch Unterarten — jede Liste wäre nach
     * dem dritten Verein unvollständig.
     */
    #[Test]
    public function anything_that_can_be_evidenced_fits(): void
    {
        $mensch = User::factory()->create(['is_active' => true]);

        foreach ([
            'Rotax 912 Service Training',
            'Klebeverfahren Faserverbund',
            'Zerstörungsfreie Prüfung — Farbeindringverfahren',
            'Rettungsgeräte packen',
            'Sauerstoffanlagen',
            'Human Factors Auffrischung',
        ] as $gegenstand) {
            Qualification::create([
                'user_id' => $mensch->id,
                'type' => Qualification::TYPE_TRAINING,
                'subject' => $gegenstand,
                'valid_from' => now()->toDateString(),
            ]);
        }

        $this->assertSame(6, $mensch->fresh()->qualifications()->count());
    }

    /**
     * Der Nachweis hängt am Menschen, nicht am Gerät.
     *
     * Wer auf Rotax geschult ist, bleibt es, auch wenn der Verein den Motor
     * verkauft.
     */
    #[Test]
    public function the_certificate_belongs_to_the_person(): void
    {
        $mensch = User::factory()->create(['is_active' => true]);

        Qualification::create([
            'user_id' => $mensch->id,
            'type' => Qualification::TYPE_TRAINING,
            'subject' => 'Rotax 912 Service Training',
            'scope' => 'Rotax 912',
            'valid_from' => now()->toDateString(),
        ]);

        $this->assertSame(1, $mensch->fresh()->qualifications()->count());
    }
}
