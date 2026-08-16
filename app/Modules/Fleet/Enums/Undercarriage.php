<?php

declare(strict_types=1);

namespace App\Modules\Fleet\Enums;

/**
 * Worauf das Luftfahrzeug beim Wiegen steht.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * Feldtest: „außerdem die auswahl spornrad mit 1/2 Hauptrad oder 3-Bein".
 *
 * Es bestimmt zwei Dinge, die vorher geraten wurden:
 *
 *  1. WIE VIELE WÄGEPUNKTE es gibt. Bisher hing das an der Blattart -- zwei
 *     beim Segelflugzeug, drei beim Motorflugzeug. Das stimmt für den
 *     Regelfall und ist beim einrädrigen Segelflugzeug mit Spornrad falsch
 *     herum gedacht: Dort sind es zwei, weil es zwei Räder gibt, nicht weil
 *     es ein Segelflugzeug ist. Ein Motorsegler mit Bugrad hat drei.
 *
 *  2. WELCHE ZEICHNUNG danebensteht. Das Papier druckt beide Konfigurationen
 *     nebeneinander und überlässt dem Ausfüllenden die Wahl; hier steht die,
 *     die zum eingetragenen Fahrwerk gehört.
 *
 * NICHT bestimmt es das Vorzeichen der Formel. Das steckt im Hebelarm `a`
 * selbst, der vorzeichenbehaftet gespeichert wird -- deshalb rechnet der
 * Rechenweg immer „+ a", während das Papier zwei Formeln nebeneinander
 * druckt. Wer das hier nochmal entscheiden ließe, hätte zwei Wahrheiten.
 * ─────────────────────────────────────────────────────────────────────────────
 */
enum Undercarriage: string
{
    /** Ein Hauptrad und ein Sporn -- das übliche Segelflugzeug. */
    case TailwheelOneMain = 'tailwheel_one_main';

    /** Zwei Haupträder und ein Spornrad -- der klassische Spornradflieger. */
    case TailwheelTwoMains = 'tailwheel_two_mains';

    /** Zwei Haupträder und ein Bugrad. */
    case Tricycle = 'tricycle';

    public function label(): string
    {
        return __('fleet.weighing.undercarriage.'.$this->value);
    }

    /** Ob der dritte Wägepunkt vorne liegt -- fürs Bild, nicht fürs Rechnen. */
    public function isNoseWheel(): bool
    {
        return $this === self::Tricycle;
    }

    /**
     * Die vorgedruckten Auflagenzeilen.
     *
     * @return list<string>
     */
    public function supports(): array
    {
        return match ($this) {
            self::TailwheelOneMain => ['Hauptrad G1', 'Sporn G2'],
            self::TailwheelTwoMains => ['Hauptrad links G1l', 'Hauptrad rechts G1r', 'Spornrad G2'],
            self::Tricycle => ['Hauptrad links G1l', 'Hauptrad rechts G1r', 'Bugrad G2'],
        };
    }

    /** Wie viele Wägepunkte -- zwei oder drei. */
    public function supportCount(): int
    {
        return count($this->supports());
    }

    /** Was ab Werk zu einer Blattart passt, solange niemand etwas anderes wählt. */
    public static function defaultFor(SheetVariant $variant): self
    {
        return $variant === SheetVariant::Glider ? self::TailwheelOneMain : self::Tricycle;
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
