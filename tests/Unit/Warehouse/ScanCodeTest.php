<?php

declare(strict_types=1);

namespace Tests\Unit\Warehouse;

use App\Modules\Warehouse\Support\ScanCode;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Was in einem QR-Code auf einem Etikett steht.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * Vorgabe: „warum eine adresse? ich dachte eher daran das aeronance selbst einen
 * scanner aufmacht und somit darin nur Infos sind die das tool braucht."
 *
 * Der wichtigste Test hier ist deshalb `a_foreign_code_is_recognised_as_foreign`:
 * Ein Scanner, der einen fremden QR-Code nicht als fremd erkennt, muss raten —
 * und Raten heißt im Lager: falsches Los.
 * ─────────────────────────────────────────────────────────────────────────────
 */
final class ScanCodeTest extends TestCase
{
    #[Test]
    public function a_lot_code_carries_the_lot_number(): void
    {
        $this->assertSame('AER1:L:EASA-12345', ScanCode::forLot('EASA-12345'));
    }

    #[Test]
    public function a_location_code_carries_the_id(): void
    {
        $this->assertSame('AER1:S:17', ScanCode::forLocation(17));
    }

    #[Test]
    public function what_was_written_can_be_read_back(): void
    {
        $this->assertSame(
            ['kind' => ScanCode::KIND_LOT, 'reference' => 'EASA-12345'],
            ScanCode::parse(ScanCode::forLot('EASA-12345')),
        );

        $this->assertSame(
            ['kind' => ScanCode::KIND_LOCATION, 'reference' => '17'],
            ScanCode::parse(ScanCode::forLocation(17)),
        );
    }

    /**
     * KEINE ADRESSE IM CODE.
     *
     * Ein Regalschild hängt sichtbar in der Halle. Eine URL darauf verriete
     * jedem, der es fotografiert, die Adresse dieser Instanz — und ein
     * Aufkleber überlebt jeden Domainwechsel, eine URL nicht.
     */
    #[Test]
    public function the_code_contains_no_address(): void
    {
        foreach ([ScanCode::forLot('EASA-12345'), ScanCode::forLocation(17)] as $inhalt) {
            $this->assertStringNotContainsString('http', $inhalt);
            $this->assertStringNotContainsString('://', $inhalt);
            $this->assertStringNotContainsString('.', $inhalt, 'Kein Punkt — also auch keine Domain.');
        }
    }

    /**
     * DER TEST, UM DEN ES GEHT: Fremdes wird als fremd erkannt.
     *
     * Ein Paketaufkleber, ein WLAN-Code, das Etikett eines anderen Systems.
     * Alles davon landet früher oder später vor der Kamera.
     */
    #[Test]
    public function a_foreign_code_is_recognised_as_foreign(): void
    {
        $fremd = [
            'https://example.org/lot/12',
            'WIFI:S:Werkstatt;T:WPA;P:geheim;;',
            'AER0:L:EASA-12345',      // andere Formatversion
            'AER1:X:EASA-12345',      // unbekannte Art
            'AER1:L:',                // ohne Verweis
            'AER1:L',                 // unvollstaendig
            'EASA-12345',             // nur die Nummer
            '',
            '   ',
        ];

        foreach ($fremd as $eingabe) {
            $this->assertNull(
                ScanCode::parse($eingabe),
                sprintf('"%s" haette abgelehnt werden muessen.', $eingabe),
            );
        }
    }

    /**
     * Eine Losnummer darf einen Doppelpunkt enthalten.
     *
     * Sie stammt aus einem Form 1, und was dort steht, bestimmt nicht dieses
     * System. Deshalb wird nur an den ersten beiden Doppelpunkten getrennt.
     */
    #[Test]
    public function a_colon_in_the_lot_number_survives(): void
    {
        $nummer = 'DE.145.0123:2026:7';

        $this->assertSame(
            ['kind' => ScanCode::KIND_LOT, 'reference' => $nummer],
            ScanCode::parse(ScanCode::forLot($nummer)),
        );
    }

    #[Test]
    public function surrounding_whitespace_does_not_matter(): void
    {
        $this->assertSame(
            ['kind' => ScanCode::KIND_LOT, 'reference' => 'EASA-12345'],
            ScanCode::parse("  AER1:L:EASA-12345\n"),
        );
    }
}
