<?php

declare(strict_types=1);

namespace App\Modules\Fleet\Enums;

/**
 * Welches Wägeblatt ausgefüllt wird.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * DREI BLÄTTER, ZWEI RECHENWEGE -- und das ist kein Widerspruch, sondern genau
 * der Grund, warum es diese Aufzählung neben WeighingKind gibt.
 *
 * Gerechnet wird auf zwei Arten: über den Hebel (Segelflugzeug) oder über die
 * Momente (alles mit Motor). Überschrieben sind die Blätter aber mit drei
 * verschiedenen Namen, und danach sucht derjenige, der abschreibt.
 *
 * Frühere Fassungen hielten das für einen Titel und ließen ihn weg. Feldtest:
 * „drei, wie auf dem papier" -- wer das Blatt zur Nachprüfung vorlegt, legt es
 * unter der Bezeichnung vor, die der Verband kennt. Ein Motorsegler-Blatt mit
 * der Überschrift „Flugzeug" ist beim Prüfer eine Rückfrage.
 * ─────────────────────────────────────────────────────────────────────────────
 */
enum SheetVariant: string
{
    case Glider = 'glider';

    case Motorglider = 'motorglider';

    case Aeroplane = 'aeroplane';

    public function label(): string
    {
        return __('fleet.weighing.variant.'.$this->value);
    }

    /**
     * Der Rechenweg dahinter.
     *
     * Der Motorsegler rechnet wie ein Flugzeug -- über Momente und mit Abzügen
     * für ausfliegbaren Kraftstoff. Er hat einen Motor und einen Tank; dass er
     * auch segeln kann, ändert an der Waage nichts.
     */
    public function kind(): WeighingKind
    {
        return $this === self::Glider ? WeighingKind::Glider : WeighingKind::Powered;
    }

    /** @return array<string, string> Wert => Beschriftung */
    public static function options(): array
    {
        $optionen = [];

        foreach (self::cases() as $fall) {
            $optionen[$fall->value] = $fall->label();
        }

        return $optionen;
    }
}
