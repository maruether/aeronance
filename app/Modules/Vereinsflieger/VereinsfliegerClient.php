<?php

declare(strict_types=1);

namespace App\Modules\Vereinsflieger;

use Illuminate\Support\Facades\Http;
use RuntimeException;
use SensitiveParameter;

/**
 * Der REST-Zugang zu Vereinsflieger.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * NACHGEBAUT AUS VEREINSFLIEGERS EIGENEM CLIENT, der dort heruntergeladen
 * hat (`VereinsfliegerRestInterface.php`, 599 Zeilen). Das ist kein Eigenbau
 * eines Vorgaengers, sondern deren Referenz -- die Ablaeufe hier stimmen mit ihr
 * ueberein, nur ohne die 30 Endpunkte, die dieses Projekt nicht braucht.
 *
 * DIE ANMELDUNG UND IHRE FALLE:
 *
 *     GET    auth/accesstoken        -> accesstoken
 *     POST   auth/signin             accesstoken, username, password, cid,
 *                                    appkey, auth_secret
 *     DELETE auth/signout/{token}
 *
 * Der offizielle Client nimmt das Passwort im KLARTEXT und bildet selbst
 * ISO-8859-1 -> MD5. Wer dort einen fertigen Hash hineingibt, hasht doppelt --
 * und genau das war im Altsystem eingestellt (offene Frage F19 der Analyse).
 *
 * Deshalb wird hier NICHT geraten, ob ein Wert schon ein Hash ist. Ein Muster
 * auf "32 Hexzeichen" waere eine Heuristik, und eine falsch geratene Heuristik
 * sieht hier aus wie ein falsches Passwort. Die Instanz sagt es ausdruecklich.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * SPARSAM, WEIL DER DIENST BEGRENZT IST. Vorgabe: "mache moeglichst wenig
 * abfragen, das ding ist rate-limited."
 *
 * Ein Objekt = eine Sitzung. Der Token wird gehalten und wiederverwendet;
 * angemeldet wird genau einmal, abgemeldet am Ende. Kein Wiederholen in
 * Schleifen: Wer bei einem begrenzten Dienst automatisch nachfasst, verwandelt
 * eine Stoerung in eine Sperre.
 * ─────────────────────────────────────────────────────────────────────────────
 */
final class VereinsfliegerClient
{
    private const BASE = 'https://www.vereinsflieger.de/interface/rest/';

    private ?string $token = null;

    /** @var array<int|string, mixed> */
    private array $letzteAntwort = [];

    public function __construct(
        private readonly string $username,
        #[SensitiveParameter] private readonly string $password,
        #[SensitiveParameter] private readonly string $appKey,

        /**
         * Ob $password bereits der MD5-Hash ist.
         *
         * Ausgeschrieben und nicht erkannt -- siehe Klassenkopf.
         */
        private readonly bool $passwordIsHash = false,

        /**
         * Die Vereins-ID. VORGABE "0", nicht leer.
         *
         * Der offizielle Client hat $Cid=0 als Vorgabe, und http_build_query
         * macht daraus cid=0. Ein leerer Wert ergibt cid= -- gemessen: Damit
         * weist Vereinsflieger die Anmeldung ab.
         */
        private readonly string $cid = '0',
        #[SensitiveParameter] private readonly string $authSecret = '',
    ) {}

    /**
     * Anmelden. Wahr, wenn die Zugangsdaten stimmen.
     *
     * @throws RuntimeException wenn der Dienst nicht erreichbar ist
     */
    public function signIn(): bool
    {
        /*
         * ALS POST, obwohl der offizielle Client die Methode "GET" nennt: Sein
         * SendRequest behandelt GET und POST im selben Zweig und setzt in beiden
         * Faellen CURLOPT_POST. Ein echtes GET ist also NICHT das, was
         * Vereinsflieger sieht -- nachgelesen in der Referenz, nicht vermutet.
         */
        $antwort = $this->request('POST', 'auth/accesstoken');

        $token = (string) ($antwort['accesstoken'] ?? '');

        if ($token === '') {
            throw new RuntimeException(
                'Vereinsflieger liefert keinen Accesstoken. Das ist eine Stoerung des '
                .'Dienstes und kein falsches Passwort.'
            );
        }

        $this->token = $token;

        $ergebnis = $this->request('POST', 'auth/signin', [
            'accesstoken' => $token,
            'username' => $this->username,
            'password' => $this->hashedPassword(),
            'cid' => $this->cid,
            'appkey' => $this->appKey,
            'auth_secret' => $this->authSecret,
        ], erlaubeFehlschlag: true);

        // Kein httpcode 200 -> falsche Zugangsdaten. Eine Stoerung waere oben
        // schon als Ausnahme herausgekommen.
        return ($ergebnis['__ok'] ?? false) === true;
    }

    public function signOut(): void
    {
        if ($this->token === null) {
            return;
        }

        try {
            $this->request('DELETE', 'auth/signout/'.$this->token, ['accesstoken' => $this->token], erlaubeFehlschlag: true);
        } catch (RuntimeException) {
            // Beim Abmelden nicht mehr scheitern: Die Sitzung laeuft ohnehin ab,
            // und ein Fehler hier wuerde einen erfolgreichen Lauf ueberdecken.
        }

        $this->token = null;
    }

    /**
     * Die Kategorien fuer Arbeitsstunden.
     *
     * Keine Personendaten -- eine Liste von Taetigkeitsarten. Gebraucht fuer den
     * Rueckweg, damit eine gebuchte Stunde in der richtigen Kategorie landet und
     * nicht unter einer geratenen Nummer.
     *
     * @return array<int|string, mixed>
     */
    public function workHourCategories(): array
    {
        return $this->authorised('POST', 'workhourcategories/list');
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<int|string, mixed>
     */
    public function authorised(string $method, string $path, array $data = []): array
    {
        if ($this->token === null) {
            throw new RuntimeException('Nicht angemeldet.');
        }

        return $this->request($method, $path, $data + ['accesstoken' => $this->token]);
    }

    /**
     * Was Vereinsflieger zuletzt geantwortet hat.
     *
     * Fuer die Fehlersuche: Der Dienst begruendet eine Ablehnung im Rumpf, und
     * diese Begruendung wegzuwerfen hat hier zwei Versuche gekostet. Enthaelt
     * keine Zugangsdaten -- nur Status und Meldung.
     *
     * @return array<int|string, mixed>
     */
    public function lastResponse(): array
    {
        return $this->letzteAntwort;
    }

    private function hashedPassword(): string
    {
        if ($this->passwordIsHash) {
            return $this->password;
        }

        // Genau wie im offiziellen Client: erst nach ISO-8859-1, dann MD5.
        // Die Umkodierung ist kein Zierat -- ein Umlaut im Passwort ergibt
        // sonst einen anderen Hash als der, den Vereinsflieger erwartet.
        return md5(mb_convert_encoding($this->password, 'ISO-8859-1', 'UTF-8'));
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<int|string, mixed>
     */
    private function request(string $method, string $path, array $data = [], bool $erlaubeFehlschlag = false): array
    {
        /*
         * ZWEI VERSCHIEDENE GEDULDSFAEDEN, und der Unterschied ist wichtig.
         *
         * connectTimeout bleibt kurz: Wer nach zehn Sekunden nicht antwortet,
         * ist nicht erreichbar, und darauf zu warten hilft niemandem.
         *
         * timeout dagegen musste hoch. GEMESSEN am echten Dienst: user/list
         * eines Vereins mit 394 Mitgliedern hatte nach 30 Sekunden erst 81 KB
         * geliefert und wurde abgeschnitten -- cURL-Fehler 28, der wie eine
         * Stoerung aussieht und keine ist. Ein groesserer Verein braucht noch
         * laenger.
         *
         * Genau die Sorte Grenze, die im Testbetrieb nie auffaellt und beim
         * ersten echten Abgleich zuschlaegt.
         */
        $anfrage = Http::asForm()
            ->timeout(180)
            ->connectTimeout(10)
            ->withHeaders([
                'User-Agent' => 'Aeronance/0.1 (+https://github.com/maruether/aeronance)',
                'Accept' => 'application/json',
            ]);

        $antwort = match ($method) {
            'GET' => $anfrage->get(self::BASE.$path, $data),
            'POST' => $anfrage->post(self::BASE.$path, $data),
            'DELETE' => $anfrage->delete(self::BASE.$path, $data),
            default => throw new RuntimeException('Unbekannte Methode '.$method),
        };

        /*
         * ─────────────────────────────────────────────────────────────────────
         * ERST FESTHALTEN, DANN WERFEN -- und das ist eine Korrektur.
         *
         * Vorher stand das Festhalten UNTER den Fehlerpruefungen. Wer eine
         * abgelehnte Anfrage untersuchte, bekam von lastResponse() also die
         * Antwort des VORIGEN Aufrufs -- also genau das Wegwerfen der
         * Begruendung, gegen das diese Klasse angeblich gebaut war.
         *
         * Aufgefallen bei einem 400 auf workhours/add: lastResponse() zeigte
         * seelenruhig die Stundenliste von davor, und die sah aus, als waere
         * alles in Ordnung.
         * ─────────────────────────────────────────────────────────────────────
         */
        $inhalt = $antwort->json();

        $this->letzteAntwort = (is_array($inhalt) ? $inhalt : ['__raw' => mb_substr((string) $antwort->body(), 0, 400)])
            + ['__status' => $antwort->status()];

        /*
         * 429 wird eigens benannt. Vorgabe: "das ding ist rate-limited." Eine
         * Sperre als gewoehnlichen Fehler zu melden, laedt dazu ein, es gleich
         * noch einmal zu versuchen -- und genau das verlaengert sie.
         */
        if ($antwort->status() === 429) {
            throw new RuntimeException(
                'Vereinsflieger hat die Anfrage wegen zu vieler Zugriffe abgewiesen (429). '
                .'Bitte NICHT sofort wiederholen -- der Abgleich laeuft ohnehin geplant.'
            );
        }

        if (! $erlaubeFehlschlag && $antwort->failed()) {
            /*
             * Die Begruendung gehoert IN die Meldung, nicht nur in
             * lastResponse(). Wer im Log einen Fehlschlag sieht, soll nicht
             * erst ein Werkzeug bemuehen muessen, um zu erfahren, warum.
             */
            $grund = trim((string) ($this->letzteAntwort['error'] ?? ''));

            throw new RuntimeException(sprintf(
                'Vereinsflieger antwortete auf %s mit HTTP %d%s',
                $path,
                $antwort->status(),
                $grund !== '' ? ': '.$grund : '.',
            ));
        }

        return (is_array($inhalt) ? $inhalt : []) + ['__ok' => $antwort->successful()];
    }
}
