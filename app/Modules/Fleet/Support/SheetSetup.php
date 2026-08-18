<?php

declare(strict_types=1);

namespace App\Modules\Fleet\Support;

use App\Modules\Fleet\Enums\Propulsion;
use App\Modules\Fleet\Enums\SheetVariant;
use App\Modules\Fleet\Enums\Undercarriage;
use App\Modules\Fleet\Models\Aircraft;
use App\Modules\Fleet\Models\Weighing;

/**
 * Welches Wägeblatt zu diesem Flugzeug gehört — und woher die Antwort kommt.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * Feldtest: „wenn ich für die D-EICC eine wägung anlege bekomme ich als
 * eingabemaske die massenübersicht segelflugzeug."
 *
 * Die Auskunft gab es vorher an drei Stellen verstreut -- im Anlegeformular als
 * fester Vorgabewert „Segelflugzeug", in PrepareWeighing als Rückfall auf die
 * letzte Wägung, und in SeedWeighingSheet nochmal als eigener Rückfall. Drei
 * Meinungen zu einer Frage, und die lauteste war die falsche: Wer über „Neue
 * Wägung (Werte übernehmen)" anlegte, wurde nie gefragt und bekam ohne
 * Vorgängerwägung stumm ein Segelflugblatt -- auch für ein Flugzeug.
 *
 * Hier steht die Frage EINMAL, mit ihrer Rangfolge:
 *
 *   1. DAS MUSTER. Die Aussage, die jemand bewusst getroffen hat, und die für
 *      jedes Exemplar gilt: Eine Aquila wird auf dem Flugzeugblatt gewogen,
 *      heute wie in vier Jahren.
 *   2. DIE LETZTE ABGEZEICHNETE WÄGUNG dieses Flugzeugs -- was beim letzten Mal
 *      unterschrieben wurde, stimmt vermutlich noch. Entwürfe zählen nicht:
 *      ein halb ausgefülltes Blatt kann genau der Fehler sein, der hier
 *      gemeldet wurde.
 *   3. DER ANTRIEB, als blosser Vorschlag: unmotorisiert ⇒ Segelflugblatt.
 *
 * Das Ergebnis ist NIE eine stille Festlegung. Es füllt ein sichtbares Feld
 * vor, das der Ausfüllende ändern kann -- und was er wählt, kann er beim Muster
 * hinterlegen, damit Stufe 1 beim nächsten Mal greift.
 * ─────────────────────────────────────────────────────────────────────────────
 */
final readonly class SheetSetup
{
    public const FROM_TYPE = 'type';

    public const FROM_PREVIOUS = 'previous';

    public const FROM_PROPULSION = 'propulsion';

    public function __construct(
        public SheetVariant $variant,
        public Undercarriage $undercarriage,
        /** Woher die Blattart stammt -- eine der FROM_*-Konstanten. */
        public string $origin,
        /** Ob das Muster beides schon weiss (dann gibt es nichts zu hinterlegen). */
        public bool $storedOnType,
        /** Ob es überhaupt ein Muster gibt, an dem etwas hinterlegt werden könnte. */
        public bool $hasType,
    ) {}

    public static function for(Aircraft $aircraft): self
    {
        $muster = $aircraft->aircraftType;
        $vorherige = self::lastSignedOff($aircraft);

        $ausMuster = $muster?->sheetVariant();
        $ausVorheriger = $vorherige?->sheet_variant;

        $variante = $ausMuster ?? $ausVorheriger ?? self::fromPropulsion($aircraft);

        $herkunft = match (true) {
            $ausMuster !== null => self::FROM_TYPE,
            $ausVorheriger !== null => self::FROM_PREVIOUS,
            default => self::FROM_PROPULSION,
        };

        return new self(
            variant: $variante,
            undercarriage: self::undercarriageFor($variante, $muster?->undercarriage(), $vorherige),
            origin: $herkunft,
            storedOnType: $muster?->sheetVariant() !== null && $muster?->undercarriage() !== null,
            hasType: $muster !== null,
        );
    }

    /**
     * Das Fahrwerk zur ermittelten Blattart.
     *
     * Die Angabe der letzten Wägung zählt nur, wenn sie zu DERSELBEN Blattart
     * gehörte. Sonst entstünde die schlechteste aller Antworten: ein
     * Flugzeugblatt mit den zwei Wägepunkten eines Segelflugzeugs, zusammen-
     * gesetzt aus zwei Quellen, die einzeln jeweils richtig waren.
     */
    private static function undercarriageFor(
        SheetVariant $variante,
        ?Undercarriage $ausMuster,
        ?Weighing $vorherige,
    ): Undercarriage {
        if ($ausMuster !== null) {
            return $ausMuster;
        }

        if ($vorherige?->undercarriage !== null && $vorherige->sheet_variant === $variante) {
            return $vorherige->undercarriage;
        }

        return Undercarriage::defaultFor($variante);
    }

    /**
     * Der Vorschlag aus dem Antrieb -- ausdrücklich nur ein Vorschlag.
     *
     * Er trifft den Normalfall und kann daneben liegen: Ein Segelflugzeug mit
     * Hilfstriebwerk ist motorisiert und wird trotzdem nicht wie ein Flugzeug
     * gewogen. Deshalb steht das Ergebnis in einem Feld, das jemand ansieht,
     * und nicht in einer Zeile, die niemand je zu Gesicht bekommt.
     */
    private static function fromPropulsion(Aircraft $aircraft): SheetVariant
    {
        return $aircraft->propulsion === null || $aircraft->propulsion === Propulsion::Unpowered
            ? SheetVariant::Glider
            : SheetVariant::Aeroplane;
    }

    /**
     * Das letzte Blatt, dem man glauben darf.
     *
     * Dieselbe Auswahl wie in PrepareWeighing, und aus demselben Grund: Ein
     * Entwurf trägt Werte, die nie jemand geprüft hat.
     */
    private static function lastSignedOff(Aircraft $aircraft): ?Weighing
    {
        return Weighing::query()
            ->where('aircraft_id', $aircraft->id)
            ->whereNotNull('signed_off_at')
            ->orderByDesc('weighed_at')
            ->orderByDesc('id')
            ->first();
    }
}
