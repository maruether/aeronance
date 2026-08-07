<?php

declare(strict_types=1);

namespace Tests\Feature\Fleet;

use App\Models\User;
use App\Modules\Fleet\Actions\AdoptTypeCertificate;
use App\Modules\Fleet\Models\AircraftType;
use App\Modules\Fleet\Permissions;
use App\Modules\Fleet\TypeCertificates\TypeCertificateCandidate;
use App\Modules\Fleet\Types\TypeLookup;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * One type, several Kennblatt numbers -- and which of them leads.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * THE FACT THAT DECIDES THIS, from Vorgabe: an aircraft first certified under
 * national law and later modified holds an LBA Kennblatt AND, from the
 * modification onwards, an EASA TCDS.
 *
 *   "Wenn beides oder nur ein TCDS angegeben sind, zählt immer das TCDS. Nur
 *    wenn keines vorhanden ist zählt das alte LBA Kennblatt."
 *
 * So the two numbers in a Blaues-Buch row are not equals, and the leading one
 * must not depend on which catalogue somebody happened to search.
 *
 * The old Kennblatt is still kept -- not on display, but on file -- because a
 * publication that quotes it has to find the type. Vorgabe: "falls zur zuordnung
 * nötig kann das alte kennblatt irgendwo mitgeführt werden."
 * ─────────────────────────────────────────────────────────────────────────────
 */
final class TypeCertificateNumbersTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Permission::findOrCreate(Permissions::FLEET_MANAGE, 'web');
    }

    #[Test]
    public function the_tcds_leads_even_when_the_kennblatt_was_searched_for(): void
    {
        $type = AircraftType::create(['designation' => 'ASK 21']);

        // A Blaues-Buch row: found under the German number, carrying the
        // European one beside it.
        app(AdoptTypeCertificate::class)->adopt(
            $type,
            new TypeCertificateCandidate(
                designation: 'ASK 21',
                certificate: '339/SP',
                authority: AircraftType::AUTHORITY_LBA,
                alsoFiledAs: [['number' => 'EASA.A.221', 'authority' => AircraftType::AUTHORITY_EASA]],
            ),
            $this->manager(),
            storeDocument: false,
        );

        $fresh = $type->fresh();

        $this->assertSame('EASA.A.221', $fresh->type_certificate, 'Das TCDS führt.');
        $this->assertSame(AircraftType::AUTHORITY_EASA, $fresh->certificate_authority);

        // And the old number is still on file.
        $this->assertEqualsCanonicalizing(
            ['EASA.A.221', '339/SP'],
            $fresh->certificates()->pluck('number')->all(),
        );
    }

    #[Test]
    public function without_a_tcds_the_old_kennblatt_is_the_answer(): void
    {
        /*
         * The other half of the rule, and the reason it cannot simply be "always
         * prefer EASA": an Annex-I type -- a homebuilt, an oldtimer, a glider
         * that never went through a European modification -- has no TCDS at all,
         * and its national Kennblatt is what every authority quotes.
         */
        $type = AircraftType::create(['designation' => 'Pützer Elster B']);

        app(AdoptTypeCertificate::class)->adopt(
            $type,
            new TypeCertificateCandidate(
                designation: 'Elster B',
                certificate: '751',
                authority: AircraftType::AUTHORITY_LBA,
            ),
            $this->manager(),
            storeDocument: false,
        );

        $fresh = $type->fresh();

        $this->assertSame('751', $fresh->type_certificate);
        $this->assertSame(AircraftType::AUTHORITY_LBA, $fresh->certificate_authority);
        $this->assertSame(['751'], $fresh->certificates()->pluck('number')->all());
    }

    #[Test]
    public function a_directive_finds_the_type_under_either_number(): void
    {
        /*
         * ─────────────────────────────────────────────────────────────────────
         * WHAT THE WHOLE CHANGE IS FOR. The gazette quotes the EASA reference for
         * a European type and the national Kennblatt for an Annex-I one, and
         * older issues quote the Kennblatt for types that have a TCDS today.
         *
         * With a single column, a club that had adopted one number saw no
         * directives filed under the other -- and nothing said so. The list was
         * simply shorter.
         * ─────────────────────────────────────────────────────────────────────
         */
        $type = AircraftType::create(['designation' => 'ASK 21']);
        $type->recordCertificate('EASA.A.221', AircraftType::AUTHORITY_EASA, primary: true);
        $type->recordCertificate('339/SP', AircraftType::AUTHORITY_LBA);

        $lookup = app(TypeLookup::class);

        $this->assertSame($type->id, $lookup->byCertificate('EASA.A.221'));
        $this->assertSame($type->id, $lookup->byCertificate('339/SP'));

        // The gazette writes several in one cell; each is tried in turn.
        $this->assertSame($type->id, $lookup->byCertificate('UK.TC.A.00147, 339/SP'));

        // And a number nobody flies is still a normal, quiet "no".
        $this->assertNull($lookup->byCertificate('EASA.A.999'));
    }

    #[Test]
    public function the_number_on_the_type_and_the_one_in_the_list_cannot_drift(): void
    {
        /*
         * type_certificate stays on the type because every screen and the
         * activity log refer to it -- which makes it a copy, and copies drift.
         * Somebody correcting the field in the type form would otherwise leave
         * the lookup matching a number the club no longer claims.
         */
        $type = AircraftType::create([
            'designation' => 'DG-300',
            'type_certificate' => 'EASA.A.106',
            'certificate_authority' => AircraftType::AUTHORITY_EASA,
        ]);

        $this->assertSame(['EASA.A.106'], $type->certificates()->pluck('number')->all());

        $type->update(['type_certificate' => 'EASA.A.107']);

        $this->assertSame(
            $type->id,
            app(TypeLookup::class)->byCertificate('EASA.A.107'),
            'Die korrigierte Nummer trifft.',
        );

        $this->assertTrue(
            $type->certificates()->where('number', 'EASA.A.107')->value('is_primary'),
            'Und sie ist die führende.',
        );
    }

    #[Test]
    public function the_same_number_recorded_twice_stays_one_entry(): void
    {
        /*
         * A club looks a type up at the EASA and again in the Blaues Buch, and
         * both name EASA.A.221. Two rows would make every directive quoting it
         * arrive twice -- and a duplicate on an airworthiness list is not a
         * cosmetic problem, it is two people ticking off one job.
         */
        $type = AircraftType::create(['designation' => 'ASK 21']);

        $type->recordCertificate('EASA.A.221', AircraftType::AUTHORITY_EASA, primary: true);
        $type->recordCertificate('EASA.A.221', AircraftType::AUTHORITY_EASA);

        $this->assertSame(1, $type->certificates()->count());
    }

    #[Test]
    public function only_one_number_is_ever_the_leading_one(): void
    {
        $type = AircraftType::create(['designation' => 'ASK 21']);

        $type->recordCertificate('339/SP', AircraftType::AUTHORITY_LBA, primary: true);
        $type->recordCertificate('EASA.A.221', AircraftType::AUTHORITY_EASA, primary: true);

        $this->assertSame(1, $type->certificates()->where('is_primary', true)->count());
        $this->assertSame(
            'EASA.A.221',
            $type->certificates()->where('is_primary', true)->value('number'),
        );
    }

    private function manager(): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->givePermissionTo(Permissions::FLEET_MANAGE);

        return $user->fresh();
    }
}
