<?php

declare(strict_types=1);

namespace App\Modules\Tooling;

use App\Core\Access\PermissionDefinition;
use App\Core\Modules\Contracts\AeronanceModule;
use App\Core\Modules\Manifest;
use Filament\Panel;

/**
 * Werkzeug- und Kalibrierverwaltung — der zweite Part-145-Baustein.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * ES STEHT ALLEIN. Kein `requires`: Ein Verein, der nur wissen will, wann der
 * Drehmomentschlüssel wieder zum Labor muss, braucht dafür weder Lager noch
 * Flotte. Das ist der kleinste sinnvolle Einstieg ins Thema überhaupt.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * INTERVALLE LAUFEN ÜBER DIE ZEIT, und das ist recherchiert, nicht geraten.
 *
 * Die Vorschrift nennt selbst gar kein Intervall. 145.A.40(b) und CAO.A.030
 * verlangen nur, dass Werkzeuge „controlled and calibrated according to an
 * officially recognised standard" sind und die Nachweise aufbewahrt werden. Die
 * AMC zu 145.A.40(b) ergänzt, es sei den Herstelleranweisungen zu folgen,
 * „except where the organisation can show by results that a different TIME
 * PERIOD is appropriate" — die Regel denkt also in Zeiträumen.
 *
 * ABER NICHT AUSSCHLIESSLICH: Der offiziell anerkannte Standard für
 * Drehmomentwerkzeuge, EN ISO 6789:2017, sagt „12 Monate ODER 5.000
 * Betätigungen, was zuerst eintritt". Ausgerechnet beim Drehmomentschlüssel
 * gibt es die zweite, nutzungsabhängige Grenze.
 *
 * TROTZDEM KEIN BETÄTIGUNGSZÄHLER. Er müsste bei jedem Handgriff gepflegt
 * werden, und einer, den niemand hochzählt, zeigt „1.200 von 5.000" an — eine
 * Lüge mit Nachkommastelle, die schlimmer ist als die fehlende Angabe.
 * Stattdessen trägt das Werkzeug ein Textfeld `calibration_basis`, in dem
 * steht, worauf sein Intervall beruht. Dann ist die zweite Grenze bekannt, und
 * wer sie erreichen könnte, setzt das Zeitintervall kürzer — was die AMC
 * ausdrücklich erlaubt, wenn er es begründen kann. In einem Verein greift bei
 * ein paar hundert Betätigungen im Jahr ohnehin immer die Zeit zuerst.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * WAS NOCH OFFEN IST: die Verbindung zu den Arbeitskarten.
 *
 * Dieses Modul weiß nicht, WER WOMIT gearbeitet hat — dafür bräuchte es die
 * Arbeitskarten und eine Erfassung bei jedem Handgriff. Was es stattdessen tut,
 * trägt auch ohne diese Erfassung: Es hält den ZEITRAUM fest, dessen Arbeit in
 * Frage steht, und verlangt eine dokumentierte Bewertung dazu. Damit ist die
 * Frage „welche Arbeit ist zu prüfen" beantwortbar, sobald jemand in die
 * Arbeitskarten dieses Zeitraums sieht — und sie verschwindet nicht mit dem
 * nächsten Kalibrierschein.
 *
 * Welcher Zeitraum das ist, entscheidet der BEFUND und nicht die Verspätung;
 * die Begründung steht in RecordCalibration::reviewPeriod().
 */
final class ToolingModule implements AeronanceModule
{
    public function getId(): string
    {
        return 'tooling';
    }

    public function manifest(): Manifest
    {
        return new Manifest(
            name: 'tooling',
            version: '0.1.0',
            title: __('tooling.module.title'),
            description: __('tooling.module.description'),
        );
    }

    /** @return list<PermissionDefinition> */
    public function permissions(): array
    {
        return PermissionDefinition::fromGroups([
            'tooling' => [
                Permissions::TOOLS_VIEW,
                Permissions::TOOLS_ISSUE,
                Permissions::TOOLS_MANAGE,
                Permissions::TOOLS_ASSESS,
            ],
        ]);
    }

    public function register(Panel $panel): void
    {
        $panel->discoverResources(
            in: __DIR__.'/Filament/Resources',
            for: 'App\\Modules\\Tooling\\Filament\\Resources',
        );
    }

    public function boot(Panel $panel): void
    {
        //
    }
}
