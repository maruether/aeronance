<?php

declare(strict_types=1);

namespace App\Modules\Warehouse\Support;

use chillerlan\QRCode\Common\EccLevel;
use chillerlan\QRCode\Output\QROutputInterface;
use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;

/**
 * Ein QR-Code als eingebettetes SVG.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * KEINE NEUE ABHÄNGIGKEIT: `chillerlan/php-qrcode` liegt bereits im Projekt --
 * Filament bringt es für die Zwei-Faktor-Anmeldung mit. Ein QR-Code kostet
 * damit nichts weiter als diese Datei.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * DREI ENTSCHEIDUNGEN, ALLE WEGEN DES DRUCKS:
 *
 *  1. SVG UND NICHT PNG. Ein Etikettendrucker löst anders auf als der
 *     Bildschirm; eine Rastergrafik käme entweder unscharf oder unnötig groß
 *     heraus. Ein Vektor druckt in der Auflösung, die das Gerät kann.
 *
 *  2. EINGEBETTET UND NICHT ALS data:-URI. Base64 bläht dieselbe Grafik um ein
 *     Drittel auf, und ein Etikettenbogen trägt schnell zwei Dutzend davon.
 *     Eingebettet lässt sich der Code außerdem über CSS in der Größe steuern.
 *
 *  3. FEHLERKORREKTUR M UND NICHT L. Ein Etikett im Lager bekommt Fingerabdrücke,
 *     Öl und Kratzer ab. Stufe M verträgt rund 15 % Verlust und kostet dafür
 *     ein paar Module mehr -- bei dem kurzen Inhalt hier fällt das kaum ins
 *     Gewicht, und ein Code, der beim zweiten Versuch noch liest, spart einen
 *     Weg zum Regal.
 *
 * Helle Module werden nicht gezeichnet: Das Papier ist bereits weiß, und die
 * Hälfte der Pfade wegzulassen halbiert die Dateigröße.
 * ─────────────────────────────────────────────────────────────────────────────
 */
final class QrSvg
{
    /**
     * Der Code als SVG-Markup, fertig zum Einbetten.
     *
     * Das Ergebnis stammt aus der Bibliothek und enthält kein Nutzereingabe --
     * der Inhalt landet als Modulmuster darin, nicht als Text. Es darf deshalb
     * unescaped in die Seite.
     */
    public static function render(string $payload): string
    {
        $options = new QROptions([
            'outputType' => QROutputInterface::MARKUP_SVG,
            'eccLevel' => EccLevel::M,
            'outputBase64' => false,
            'svgAddXmlHeader' => false,
            'drawLightModules' => false,

            /*
             * Die Ruhezone gehoert zum Code. Ohne sie findet ein Scanner die
             * Ecken nicht zuverlaessig -- und auf einem Etikett grenzt der Code
             * unmittelbar an Text, was genau der Fall ist, in dem sie fehlt.
             */
            'addQuietzone' => true,
            'quietzoneSize' => 2,
        ]);

        return (new QRCode($options))->render($payload);
    }
}
