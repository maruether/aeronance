<?php

declare(strict_types=1);

namespace Tests\Feature\Fleet;

use App\Core\Http\HttpFetcher;
use App\Models\User;
use App\Modules\Directives\Enums\Bindingness;
use App\Modules\Directives\Enums\DirectiveKind;
use App\Modules\Directives\Enums\SubjectKind;
use App\Modules\Directives\Models\Directive;
use App\Modules\Fleet\Actions\AdoptTypeCertificate;
use App\Modules\Fleet\Filament\Resources\AircraftTypes\Pages\ListAircraftTypes;
use App\Modules\Fleet\Models\Aircraft;
use App\Modules\Fleet\Models\AircraftType;
use App\Modules\Fleet\Permissions;
use App\Modules\Fleet\TypeCertificates\CertificateSubject;
use App\Modules\Fleet\TypeCertificates\EasaSource;
use App\Modules\Fleet\TypeCertificates\TypeCertificateCandidate;
use App\Modules\Fleet\TypeCertificates\TypeCertificateRegistry;
use Filament\Forms\Components\Field;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Aircraft types and their data sheets.
 *
 * Vorgabe: "wir sollten da das kennblatt mitführen" plus "eine durchsuchbare liste
 * mit der möglichkeit zum freitext" and an automatic download where possible.
 *
 * The EASA pages are fixtures fetched on 2026-07-30 -- the same discipline as the
 * Schleicher parser, and for the same reason: the real markup is what breaks.
 */
final class AircraftTypeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Permission::findOrCreate(Permissions::FLEET_MANAGE, 'web');

        app()->bind(HttpFetcher::class, fn (): HttpFetcher => new EasaStubFetcher);
        app()->singleton(TypeCertificateRegistry::class, function (): TypeCertificateRegistry {
            $registry = new TypeCertificateRegistry;
            $registry->register(new EasaSource(app(HttpFetcher::class)));

            return $registry;
        });
    }

    // ── The type itself ─────────────────────────────────────────────────────

    #[Test]
    public function a_type_holds_the_certificate_for_all_its_aircraft(): void
    {
        // The reason this is a table: three ASK 21s share one Kennblatt, and a
        // field per aircraft could hold three different ones.
        $type = AircraftType::create([
            'designation' => 'ASK 21',
            'type_certificate' => 'EASA.A.221',
            'certificate_authority' => AircraftType::AUTHORITY_EASA,
        ]);

        foreach (['D-KABC', 'D-KDEF', 'D-KGHI'] as $reg) {
            Aircraft::create(['registration' => $reg, 'model' => 'ASK 21', 'aircraft_type_id' => $type->id]);
        }

        $this->assertSame(3, $type->aircraft()->count());
        $this->assertSame('EASA.A.221', $type->aircraft()->first()->aircraftType->type_certificate);
    }

    #[Test]
    public function free_text_designations_remain_possible(): void
    {
        // the requirement was for it explicitly: a club may fly something nobody
        // catalogued, and typing a name has to keep working.
        $type = AircraftType::create(['designation' => 'Eigenbau Möwe 3']);

        $this->assertFalse($type->isDocumented());
        $this->assertSame('Eigenbau Möwe 3', $type->label());
    }

    #[Test]
    public function an_aircraft_without_a_type_still_works(): void
    {
        $bare = Aircraft::create(['registration' => 'D-KABC', 'model' => 'Ka 8']);

        $this->assertNull($bare->aircraft_type_id);
        $this->assertNull($bare->aircraftType);
    }

    #[Test]
    public function the_type_form_offers_both_halves_of_the_type_support_question(): void
    {
        /*
         * Not a test of Filament, but of a seam: the flag is only worth anything
         * if somebody can set it, and the one place types are maintained is this
         * form. Losing either field would leave a column nobody can reach and a
         * warning that never appears -- silently, since every other test here
         * works on the model.
         */
        $names = array_map(
            fn (Field $field): ?string => $field->getName(),
            ListAircraftTypes::formSchema(),
        );

        $this->assertContains('type_support', $names);
        $this->assertContains('without_type_support', $names);
    }

    // ── Exact directive matching, the payoff ────────────────────────────────

    #[Test]
    public function a_linked_type_makes_directive_matching_exact(): void
    {
        // The fuzzy comparison would have matched both of these; the type link
        // separates the variants.
        $ask21 = AircraftType::create(['designation' => 'ASK 21']);
        $ask21b = AircraftType::create(['designation' => 'ASK 21 B']);

        $plain = Aircraft::create(['registration' => 'D-KABC', 'model' => 'ASK 21', 'aircraft_type_id' => $ask21->id]);
        $variant = Aircraft::create(['registration' => 'D-KDEF', 'model' => 'ASK 21 B', 'aircraft_type_id' => $ask21b->id]);

        $directive = $this->directive(['aircraft_type_id' => $ask21b->id, 'subject_model' => 'ASK 21 B']);

        $this->assertTrue($directive->mayApplyTo($variant));
        $this->assertFalse($directive->mayApplyTo($plain), 'The plain ASK 21 is a different type.');
    }

    #[Test]
    public function an_uncatalogued_aircraft_falls_back_to_the_name_and_is_not_exempted(): void
    {
        // The deliberate asymmetry: a directive WITH a type and an aircraft
        // WITHOUT one must not answer "no". An uncatalogued aircraft escaping a
        // directive is the failure this module exists to prevent.
        $type = AircraftType::create(['designation' => 'ASK 21']);
        $directive = $this->directive(['aircraft_type_id' => $type->id, 'subject_model' => 'ASK 21']);

        $uncatalogued = Aircraft::create(['registration' => 'D-KABC', 'model' => 'ASK 21']);

        $this->assertTrue($directive->mayApplyTo($uncatalogued));
    }

    #[Test]
    public function without_types_the_loose_comparison_still_applies(): void
    {
        // It has to stay: a manufacturer's list names a type that may not be
        // catalogued yet, and a row must be importable before anybody curates it.
        $directive = $this->directive(['subject_model' => 'ASK 21']);

        $this->assertTrue($directive->mayApplyTo(
            Aircraft::create(['registration' => 'D-KABC', 'model' => 'ASK 21 B']),
        ));
    }

    // ── The EASA lookup ─────────────────────────────────────────────────────

    #[Test]
    public function a_search_finds_the_certificate_and_reads_its_number_properly(): void
    {
        // The slug flattens the authority's dots -- "easaa221" is not a number
        // anybody reads off a document.
        $candidates = app(AdoptTypeCertificate::class)->search('ASK 21');

        $numbers = array_map(fn ($c): string => $c->certificate, $candidates);

        $this->assertContains('EASA.A.221', $numbers);
        $this->assertNotContains('easaa221', $numbers);
    }

    #[Test]
    public function adopting_records_the_number_the_authority_and_the_link(): void
    {
        $type = AircraftType::create(['designation' => 'ASK 21']);

        $candidate = $this->candidateFor('EASA.A.221');

        $result = app(AdoptTypeCertificate::class)->adopt(
            $type, $candidate, $this->manager(), storeDocument: false,
        );

        $fresh = $result['type'];
        $this->assertSame('EASA.A.221', $fresh->type_certificate);
        $this->assertSame(AircraftType::AUTHORITY_EASA, $fresh->certificate_authority);
        $this->assertNotNull($fresh->data_sheet_url);
        $this->assertNotNull($fresh->data_sheet_checked_at);
    }

    #[Test]
    public function the_document_link_is_the_downloads_path_not_a_pdf_extension(): void
    {
        // EASA serves documents from /en/downloads/<id>/en. Looking for a .pdf
        // extension finds nothing -- the first version of this parser did exactly
        // that and came back empty.
        $resolved = (new EasaSource(new EasaStubFetcher))->resolve($this->candidateFor('EASA.A.221'));

        $this->assertNotNull($resolved->dataSheetUrl);
        $this->assertStringContainsString('/downloads/', $resolved->dataSheetUrl);
    }

    #[Test]
    public function the_designation_comes_from_the_page_title(): void
    {
        $resolved = (new EasaSource(new EasaStubFetcher))->resolve($this->candidateFor('EASA.A.221'));

        $this->assertSame('Schleicher ASK 21', $resolved->designation);
    }

    #[Test]
    public function adopting_needs_the_manage_permission(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/permission/');

        app(AdoptTypeCertificate::class)->adopt(
            AircraftType::create(['designation' => 'ASK 21']),
            $this->candidateFor('EASA.A.221'),
            User::factory()->create(['is_active' => true]),
        );
    }

    #[Test]
    public function one_authority_failing_does_not_hide_the_others(): void
    {
        // A search is not a place to fail: a missing hit is visible as a missing
        // hit, while an exception would hide every other authority's answer.
        $registry = new TypeCertificateRegistry;
        $registry->register(new EasaSource(new ThrowingFetcher));

        $this->assertSame([], $registry->searchAll('ASK 21'));
    }

    /** @param array<string, mixed> $attributes */
    private function directive(array $attributes): Directive
    {
        return Directive::create(array_merge([
            'source' => 'manual',
            'number' => 'LTA-1',
            'title' => 'Prüfung',
            'kind' => DirectiveKind::Lta,
            'bindingness' => Bindingness::Mandatory,
            'subject_kind' => SubjectKind::AircraftModel,
        ], $attributes));
    }

    /**
     * Motoren und Propeller antworten aus der EASA-Bibliothek -- Flugzeuge
     * nicht als Komponenten und umgekehrt. Gemessen: engine-cs-e und
     * propeller-cs-p sind die Kategorien im Pfad. Der Fall dahinter: Fuer
     * einen Rotax 912F3 kam bisher NUR der LBA-Treffer (mit der
     * Blaues-Buch-PDF als Kennblatt) -- der EASA.E.121-Kandidat fehlte.
     */
    #[Test]
    public function components_answer_from_the_engine_and_propeller_shelves(): void
    {
        $quelle = new EasaSource(new MixedCategoryFetcher);

        $komponenten = $quelle->search('egal', CertificateSubject::Component);
        $this->assertSame(
            ['EASA.E.121', 'EASA.P.001'],
            array_map(fn ($k) => $k->certificate, $komponenten),
        );

        $flugzeuge = $quelle->search('egal', CertificateSubject::Aircraft);
        $this->assertSame(
            ['EASA.A.221'],
            array_map(fn ($k) => $k->certificate, $flugzeuge),
        );
    }

    /**
     * "DR300" fragt auch nach "DR 300" -- gemessen an der echten Bibliothek:
     * Die EASA-Volltextsuche normalisiert Leerzeichen nicht, ?search=DR300
     * liefert null Treffer, ?search=DR%20300 das CEAPR-Kennblatt EASA.A.367.
     * Feldtest: "ASK21 wird gefunden, DR300 nicht."
     */
    #[Test]
    public function the_search_also_tries_the_spaced_spelling(): void
    {
        $fetcher = new RecordingFetcher;

        (new EasaSource($fetcher))->search('DR300', CertificateSubject::Aircraft);

        $this->assertCount(2, $fetcher->urls);
        $this->assertStringContainsString('DR300', $fetcher->urls[0]);
        $this->assertStringContainsString('DR%20300', $fetcher->urls[1]);
    }

    private function candidateFor(string $certificate): TypeCertificateCandidate
    {
        foreach (app(AdoptTypeCertificate::class)->search('ASK 21') as $candidate) {
            if ($candidate->certificate === $certificate) {
                return $candidate;
            }
        }

        $this->fail(sprintf('No candidate %s in the fixture.', $certificate));
    }

    private function manager(): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->givePermissionTo(Permissions::FLEET_MANAGE);

        return $user->fresh();
    }
}

final class MixedCategoryFetcher implements HttpFetcher
{
    public function get(string $url, array $headers = []): string
    {
        return '<a href="/en/document-library/type-certificates/engine-cs-e/easae121-rotax-912-engine-series">x</a>'
            .'<a href="/en/document-library/type-certificates/propeller-cs-p/easap001-mt-propeller-mtv-16">x</a>'
            .'<a href="/en/document-library/type-certificates/cs-22-sailplanes/easaa221-schleicher-ask">x</a>';
    }
}

final class RecordingFetcher implements HttpFetcher
{
    /** @var list<string> */
    public array $urls = [];

    public function get(string $url, array $headers = []): string
    {
        $this->urls[] = $url;

        return '<html></html>';
    }
}

final class EasaStubFetcher implements HttpFetcher
{
    public function get(string $url, array $headers = []): string
    {
        $file = str_contains($url, 'easaa221') ? 'easa-ask21.html' : 'easa-search.html';

        return file_get_contents(base_path('tests/Fixtures/Easa/'.$file));
    }
}

final class ThrowingFetcher implements HttpFetcher
{
    public function get(string $url, array $headers = []): string
    {
        throw new RuntimeException('The authority is unreachable.');
    }
}
