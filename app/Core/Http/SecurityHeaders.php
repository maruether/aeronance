<?php

declare(strict_types=1);

namespace App\Core\Http;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Die HTTP-Härtung, an einer Stelle statt an vielen.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * CLAUDE.md verlangt CSP, HSTS und X-Frame-Options. Gesetzt waren sie bisher in
 * genau einem Controller -- dem Dokumentenausgang des Lagers. Damit galten sie
 * für einen von Dutzenden Endpunkten, und der nächste Controller hätte sie
 * wieder vergessen. Eine Härtung, an die jeder selbst denken muss, ist keine.
 *
 * WAS HIER BEWUSST FEHLT: `unsafe-inline` für Skripte. Sobald es jemand
 * einträgt, ist die CSP als Schutz gegen XSS wertlos, denn genau das ist der
 * Weg, den ein eingeschleustes Skript nimmt. Für Styles steht es drin --
 * Filament setzt Stilattribute inline, und ein Style führt keinen Code aus.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * HIER STAND EINMAL: „Filament und Livewire kommen ohne aus." DAS WAR FALSCH,
 * und es war eine Annahme, keine Messung.
 *
 * GEMESSEN am 2026-08-05: Filament liefert VIER Inline-Skripte -- Dunkelmodus,
 * eingeklappte Menügruppen, `window.filamentData`, den Aufruf des
 * Dunkelmodus. Unter `script-src 'self'` führt der Browser keines davon aus.
 * Aufgefallen ist das nie, weil ein blockiertes Skript KEINE 500 erzeugt: Die
 * Seite baut sich, jeder Test ist grün, und der Schaden steht in der Konsole
 * des Browsers.
 *
 * Deshalb stehen ihre Hashes in `config/csp.php` -- erzeugt und eingecheckt,
 * nicht zur Laufzeit gerechnet. Zur Laufzeit zu hashen wäre die naheliegende
 * Automatisierung und wäre fatal: Die Middleware sähe die fertige Seite und
 * würde ein EINGESCHLEUSTES Skript genauso brav erlauben.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * `unsafe-eval` IST DRIN, UND DAS IST EIN ZUGESTÄNDNIS, KEIN VERSEHEN.
 *
 * Alpine -- gebündelt in Livewire -- baut seinen Ausdrucks-Auswerter mit
 * `new Function` und `new AsyncFunction`; nachgelesen im ausgelieferten
 * Bundle. Damit braucht JEDES `x-data`, `x-show`, `x-on:click` diese
 * Direktive: Dropdowns, Modale, Reiter -- die gesamte Bedienbarkeit von
 * Filament. Alpines CSP-Build käme ohne aus, verlangt aber Komponenten ohne
 * Ausdruck-Strings, und die schreibt Filament nicht.
 *
 * Was die Richtlinie trotzdem noch hält: keine fremden Skriptquellen, keine
 * beliebigen Inline-Skripte (nur die festgeschriebenen), kein `object`, kein
 * Framing, kein `base-uri`. Was sie verliert: `eval`. Wer HTML einschleusen
 * kann, käme über Alpine-Attribute an Codeausführung -- wogegen Blades
 * Escaping die eigentliche Verteidigung ist.
 *
 * HSTS NUR ÜBER HTTPS. Über eine unverschlüsselte Verbindung gesendet ist der
 * Header wirkungslos; im lokalen Betrieb ohne Zertifikat würde er einen
 * Entwickler dagegen für Monate auf HTTPS festnageln, inklusive localhost.
 * Deshalb hängt er an der Verbindung und nicht an der Umgebung.
 * ─────────────────────────────────────────────────────────────────────────────
 */
final class SecurityHeaders
{
    /**
     * Die Skript-Regel: eigene Herkunft, `eval` und die festgeschriebenen Hashes.
     *
     * Die Hashes stehen in `config/csp.php` und werden von
     * `CspScriptHashesTest` erzeugt und überwacht -- schlägt er an, hat
     * Filament seine Inline-Skripte geändert.
     */
    private static function scriptSrc(): string
    {
        /** @var list<string> $hashes */
        $hashes = config('csp.script_hashes', []);

        $teile = ["script-src 'self'", "'unsafe-eval'"];

        foreach ($hashes as $hash) {
            $teile[] = "'".$hash."'";
        }

        return implode(' ', $teile);
    }

    public function handle(Request $request, Closure $next): Response
    {
        /** @var Response $response */
        $response = $next($request);

        /*
         * Downloads bleiben unangetastet. Ein PDF mit einer CSP auszuliefern
         * bringt nichts, und der Dokumentenausgang setzt seine eigenen, engeren
         * Header -- die hier zu überschreiben wäre eine Lockerung.
         */
        if ($response->headers->has('Content-Disposition')) {
            return $response;
        }

        $headers = [
            // Kein fremder Ursprung, nirgends. Assets liefert die Instanz selbst
            // aus -- das Projekt bindet keine CDNs ein, und diese Zeile hält
            // das so.
            'Content-Security-Policy' => implode('; ', [
                "default-src 'self'",
                self::scriptSrc(),
                "style-src 'self' 'unsafe-inline'",
                "img-src 'self' data:",
                "font-src 'self' data:",
                "connect-src 'self'",
                "form-action 'self'",
                "frame-ancestors 'none'",
                "base-uri 'self'",
                "object-src 'none'",
            ]),

            // frame-ancestors deckt das für moderne Browser schon ab; dieser
            // Header ist der, den ältere Proxys und Scanner lesen.
            'X-Frame-Options' => 'DENY',

            'X-Content-Type-Options' => 'nosniff',
            'Referrer-Policy' => 'same-origin',

            /*
             * Was nicht gebraucht wird, bleibt aus -- mit einer Ausnahme.
             *
             * DIE KAMERA IST FREIGESCHALTET, UND ZWAR NUR FUER DIESE HERKUNFT.
             * Das Lager scannt QR-Codes von Etiketten und Regalschildern; ohne
             * diese Zeile weist der Browser `getUserMedia` ab, AUCH WENN DER
             * NUTZER DIE ERLAUBNIS ERTEILT HAT. Das ist der unangenehme Fall:
             * Der Mensch klickt "erlauben", und es passiert trotzdem nichts.
             *
             * `self` und nicht `*`: Ein eingebetteter Fremdinhalt bekommt die
             * Kamera damit nicht mit -- eingebettet werden darf ohnehin nichts
             * (frame-ancestors 'none'), aber die Erlaubnis so eng zu fassen wie
             * moeglich kostet hier gar nichts.
             *
             * Mikrofon, Standort und Bezahlung bleiben aus. Nichts davon hat
             * eine Werkstattverwaltung zu suchen.
             */
            'Permissions-Policy' => 'camera=(self), microphone=(), geolocation=(), payment=()',
        ];

        foreach ($headers as $name => $value) {
            if (! $response->headers->has($name)) {
                $response->headers->set($name, $value);
            }
        }

        if ($request->isSecure()) {
            $response->headers->set(
                'Strict-Transport-Security',
                'max-age=31536000; includeSubDomains',
            );
        }

        return $response;
    }
}
