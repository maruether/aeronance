<?php

declare(strict_types=1);

namespace App\Modules\Vereinsflieger;

use App\Core\Identity\DiscoveredGroup;
use App\Core\Identity\DiscoversGroups;
use App\Core\Identity\ExternalSubject;
use App\Core\Identity\IdentityProvider;
use App\Modules\Vereinsflieger\Actions\RememberMemberStatuses;
use App\Modules\Vereinsflieger\Enums\MemberStatusHandling;
use App\Modules\Vereinsflieger\Models\Connection;
use App\Modules\Vereinsflieger\Models\MemberStatus;
use RuntimeException;
use SensitiveParameter;

/**
 * Vereinsflieger als Identitätsquelle.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * WAS ER LIEFERT: wer jemand ist -- UID, Name, E-Mail, Vereinsfunktionen.
 * WAS ER NICHT LIEFERT: was jemand darf.
 *
 * Aus der Analyse (§6.4) und ausdrücklich bestätigt: „die
 * freigabeerlaubnis wird explizit nur in aeronance geführt, nicht gesynct."
 * Vereinsfunktion und Werkstattqualifikation sind zwei verschiedene Dinge --
 * die eine ist eine Organisationsaussage, die andere eine mit Lizenznachweis,
 * Recency und Haftungsfolge. Der Kern setzt das durch
 * (CoreRoles::neverFromProvider); dieser Connector muss davon nichts wissen und
 * kann es auch nicht umgehen.
 * ─────────────────────────────────────────────────────────────────────────────
 */
final class VereinsfliegerProvider implements DiscoversGroups, IdentityProvider
{
    /**
     * Die drei Ebenen tragen ihre Herkunft im Wert.
     *
     * Ohne das fielen "Fluglehrer" als Vereinsamt und "Fluglehrer" als
     * VF-Berechtigung zusammen -- VIER Namen kommen tatsaechlich in beiden
     * Listen vor, das ist gemessen.
     */
    public const FUNCTION_PREFIX = 'funktion:';

    public const ROLE_PREFIX = 'rolle:';

    /** Wert ist die msid, NICHT das Wort -- siehe groups(). */
    public const STATUS_PREFIX = 'status:';

    /**
     * Die Mitgliederliste dieses Laufs -- roh, wie sie kam.
     *
     * ─────────────────────────────────────────────────────────────────────────
     * DER GRUND IST DIE MENGENBEGRENZUNG. members() und groups() beantworten
     * zwei Fragen aus DERSELBEN Antwort: Vereinsflieger kennt keinen Endpunkt
     * fuer Vereinsfunktionen -- nachgesehen im offiziellen Client, dort gibt es
     * user/list und auth/getuser und sonst nichts zu Personen. Die Funktionen
     * entstehen also aus den Mitgliedern.
     *
     * Wuerde jede der beiden Methoden selbst abrufen, kostete ein Abgleich mit
     * anschliessender Funktionsliste die doppelte Anzahl Anfragen -- fuer
     * dieselben Daten.
     *
     * Nur fuer die Lebensdauer des Objekts, und das ist ein Lauf.
     * ─────────────────────────────────────────────────────────────────────────
     *
     * @var list<array<int|string, mixed>>|null
     */
    private ?array $mitglieder = null;

    /**
     * Die Anbindung, aus der dieser Provider liest.
     *
     * ─────────────────────────────────────────────────────────────────────────
     * Frueher stand der Zugang in den Einstellungen -- genau einer. Seit eine
     * CAO Luftfahrzeuge MEHRERER Vereine betreut, ist er ein Datensatz.
     *
     * Als Identitaetsquelle taugt trotzdem nur EINE: Ein Mensch hat ein Konto,
     * und kaeme er aus zwei Vereinsfliegern, gaebe es zwei Wahrheiten darueber,
     * wer er ist. Ohne Angabe nimmt der Provider deshalb die dafuer
     * gekennzeichnete Anbindung.
     * ─────────────────────────────────────────────────────────────────────────
     */
    public function __construct(private ?Connection $connection = null) {}

    public function name(): string
    {
        return 'vereinsflieger';
    }

    public function label(): string
    {
        return 'Vereinsflieger';
    }

    /**
     * NEIN -- und das ist die Korrektur, nicht eine technische Grenze.
     *
     * ─────────────────────────────────────────────────────────────────────────
     * Vorgabe: „eine anmeldung über den VF geht nicht. das bietet der nicht an
     * soweit ich weiß."
     *
     * NACHGEPRUEFT statt geglaubt, und der Beleg steht in Vereinsfliegers
     * eigenem Client: auth/signin verlangt neben Benutzername und Passwort
     * einen APPKEY -- den Schluessel der ANWENDUNG. Das ist ein API-Zugang,
     * kein Anmeldeverfahren fuer Dritte.
     *
     * Drei Gruende, warum das kein Wortklauben ist:
     *
     *  1. KEIN WEITERLEITUNGSVERFAHREN. Kein OAuth, kein Token fuer Dritte.
     *     Das Passwort muesste durch Aeronance fliessen -- die Anwendung
     *     saesse also im Besitz fremder Zugangsdaten zu einem fremden System.
     *
     *  2. DAS KONTINGENT. Vereinsflieger erlaubt rund 1000 Anfragen am Tag.
     *     Bei 394 Mitgliedern waere eine Anmeldung je Person und Tag ein
     *     Drittel davon -- fuer nichts als das Pruefen eines Passworts.
     *
     *  3. ES IST NICHT DAFUER GEDACHT. Vorgabe: „VF ist nur ein information-
     *     und kein identity provider." Genau so verhaelt es sich: Es sagt, WER
     *     jemand ist und was er im Verein tut -- nicht, dass er es IST.
     *
     * FOLGE, und sie ist der Grund, warum der Mailweg jetzt gebaut wird: Jedes
     * Konto braucht ein EIGENES Passwort in Aeronance. Vereinsflieger liefert,
     * wer jemand ist und was er im Verein tut -- nicht, dass er es ist.
     *
     * GEPRUEFT AM 14.08.2026: github.com/diginize/wp-vereinsflieger, ein
     * WordPress-Plugin, das genau diese Anmeldung anbietet. Es hat KEINEN
     * anderen Weg gefunden -- es nimmt denselben (App-Key, CID, auth/signin
     * mit dem Passwort des Benutzers) und traegt damit dieselben zwei
     * Nachteile: fremde Zugangsdaten fliessen durch die Anwendung, und jede
     * Anmeldung kostet drei Anfragen aus dem Tageskontingent. Marvin dazu:
     * "der beschriebene macht für uns keinen sinn." Nicht erneut recherchieren;
     * ein Weiterleitungsverfahren gibt es bei Vereinsflieger schlicht nicht.
     * ─────────────────────────────────────────────────────────────────────────
     */
    public function supportsPassword(): bool
    {
        return false;
    }

    /**
     * Anmeldung MIT DEN DATEN DES BENUTZERS, nicht mit denen der Instanz.
     *
     * ─────────────────────────────────────────────────────────────────────────
     * Das ist der Unterschied zum Abgleich weiter unten: Hier prüft
     * Vereinsflieger selbst, ob Benutzername und Passwort stimmen -- diese
     * Anwendung erfährt das Passwort nur für die Dauer des Aufrufs und
     * speichert es nie.
     *
     * Der Benutzer gibt seinen KLARTEXT ein, also wird gehasht wie in der
     * Referenz. Der hinterlegte Instanzzugang ist ein anderer Fall und trägt
     * seinen eigenen Schalter.
     * ─────────────────────────────────────────────────────────────────────────
     */
    public function authenticate(string $username, #[SensitiveParameter] string $password): ?ExternalSubject
    {
        /*
         * Wird nicht benutzt -- supportsPassword() ist false, die Anmeldemaske
         * bietet diesen Weg gar nicht erst an. Der Code bleibt stehen, weil er
         * gemessen funktioniert: Sollte Vereinsflieger je ein Verfahren fuer
         * Dritte anbieten (OAuth, Token), haengt die Umsetzung hier und muss
         * nicht neu erarbeitet werden.
         */
        if (! $this->supportsPassword()) {
            return null;
        }

        $anbindung = $this->connection();

        $client = new VereinsfliegerClient(
            username: $username,
            // Der Benutzer gibt seinen KLARTEXT ein -- der Hash-Schalter der
            // Anbindung gilt fuer deren eigenen Zugang, nicht fuer ihn.
            password: $password,
            appKey: (string) $anbindung->app_key,
            passwordIsHash: false,
            cid: (string) ($anbindung->cid ?: '0'),
            authSecret: (string) ($anbindung->auth_secret ?? ''),
        );

        try {
            if (! $client->signIn()) {
                // Falsche Zugangsdaten -- KEIN Fehler. Eine Störung käme als
                // Ausnahme aus dem Client heraus.
                return null;
            }

            return $this->subjectFrom($client->authorised('POST', 'auth/getuser'));
        } finally {
            $client->signOut();
        }
    }

    /**
     * Alle Mitglieder -- für den Abgleich.
     *
     * ─────────────────────────────────────────────────────────────────────────
     * EINE ANFRAGE FÜR ALLE, nicht eine je Person. Vorgabe: „mache möglichst
     * wenig abfragen, das ding ist rate-limited." user/list liefert die ganze
     * Liste; sie danach einzeln nachzuschlagen wäre der teure Weg zum selben
     * Ergebnis.
     *
     * @return iterable<ExternalSubject>
     */
    public function members(): iterable
    {
        foreach ($this->rawMembers() as $eintrag) {
            $subjekt = $this->subjectFrom($eintrag);

            if ($subjekt !== null) {
                yield $subjekt;
            }
        }
    }

    /**
     * Alles, woran sich eine Rolle festmachen laesst -- in DREI Ebenen.
     *
     * ─────────────────────────────────────────────────────────────────────────
     * Vorgabe: „es gibt rollen und funktionen, die brauchen wir auch."
     *
     * GEMESSEN, warum das drei getrennte Ebenen sein muessen und nicht eine:
     *
     *   FUNKTIONEN sind Vereinsaemter -- Fluglehrer, Schlepppilot, Zellenwart.
     *   19 verschiedene in der Referenzinstallation.
     *
     *   ROLLEN sind Vereinsfliegers EIGENE Rechte -- "Standard
     *   (Administrator)", "LFZ bearbeiten", "Mitglied (nur eigene Daten)",
     *   "Website API". 35 verschiedene; 224 von 394 Menschen haben gar keine.
     *
     *   STATUS ist die Mitgliedsart -- aktiv, passiv, Ehrenmitglied.
     *
     * DIE EBENEN DUERFEN NICHT ZUSAMMENFALLEN, und das ist gemessen und nicht
     * vermutet: VIER Namen kommen in BEIDEN Listen vor -- Fluglehrer,
     * Werkstattleiter, Schriftfuehrer, Jugendleiter. Wuerde man sie in einen
     * Topf werfen, bekaeme "Fluglehrer als Vereinsamt" dieselben Rechte wie
     * "Fluglehrer als VF-Berechtigung", und niemand koennte die beiden noch
     * auseinanderhalten. Deshalb traegt jeder Wert seine Ebene vorn.
     *
     * DER STATUS HAENGT AN DER KENNUNG, NICHT AM WORT. `msid` ist die
     * Statusnummer, und sie ist fest: aktiv=1, passiv=2, sonstige=6,
     * Ehrenmitglied=101, Externer Pilot=102. Ein Verein, der "Externer Pilot"
     * in "Gastpilot" umbenennt, behaelt die 102 -- eine Zuordnung auf das Wort
     * waere am naechsten Tag still wirkungslos.
     * ─────────────────────────────────────────────────────────────────────────
     *
     * @return iterable<DiscoveredGroup>
     */
    public function groups(): iterable
    {
        $zaehler = [];
        $namen = [];

        foreach ($this->rawMembers() as $eintrag) {
            foreach ($this->groupsOf($eintrag) as $wert => $anzeige) {
                $zaehler[$wert] = ($zaehler[$wert] ?? 0) + 1;
                $namen[$wert] = $anzeige;
            }
        }

        ksort($zaehler, SORT_NATURAL | SORT_FLAG_CASE);

        foreach ($zaehler as $wert => $anzahl) {
            yield new DiscoveredGroup(
                value: (string) $wert,
                label: $namen[$wert] ?? null,
                memberCount: $anzahl,
            );
        }
    }

    /**
     * Die Mitgliederliste -- einmal geholt, zweimal benutzt.
     *
     * @return list<array<int|string, mixed>>
     */
    private function rawMembers(): array
    {
        if ($this->mitglieder !== null) {
            return $this->mitglieder;
        }

        $client = $this->instanceClient();

        try {
            if (! $client->signIn()) {
                throw new RuntimeException(
                    'Der hinterlegte Vereinsflieger-Zugang wird abgelehnt: '
                    .((string) ($client->lastResponse()['error'] ?? 'keine Begründung genannt'))
                    .'. Bitte in den Einstellungen prüfen -- der Abgleich läuft sonst bei '
                    .'jedem Lauf ins Leere.'
                );
            }

            $saetze = [];

            foreach ($client->authorised('POST', 'user/list') as $key => $eintrag) {
                // Die Antwort trägt neben den Sätzen auch httpstatuscode und
                // Ähnliches -- nur die numerisch geschlüsselten sind Personen.
                if (is_array($eintrag) && is_numeric($key)) {
                    $saetze[] = $eintrag;
                }
            }

            $this->mitglieder = $saetze;

            /*
             * ─────────────────────────────────────────────────────────────────
             * JEDER BLICK AUF DIE MITGLIEDER FRISCHT DIE STATUSLISTE AUF.
             *
             * Vorgabe: „denke daran das die anderen IDs auf aktiv, passiv oder
             * ignorieren gemappt sein müssen. das macht der admin manuell."
             *
             * Genau deshalb steht das hier und nicht nur hinter einem Knopf.
             * Legt der Verein spaeter einen neuen Status an, faellt derjenige,
             * der ihn traegt, sonst STILL aus dem Abgleich -- kein Konto, kein
             * Hinweis, und niemand kann etwas zuordnen, das er nicht sieht.
             *
             * So bekommt jeder neue Status beim naechsten Lauf seine Zeile,
             * seine Kopfzahl und sein Abzeichen in der Navigation. Entscheiden
             * muss ihn weiterhin ein Mensch -- geraten wird nichts.
             * ─────────────────────────────────────────────────────────────────
             */
            app(RememberMemberStatuses::class)->handle($this->memberStatuses());

            return $this->mitglieder;
        } finally {
            $client->signOut();
        }
    }

    /**
     * Die Funktionen eines Datensatzes.
     *
     * ─────────────────────────────────────────────────────────────────────────
     * DIE API LIEFERT EIN ARRAY, KEINEN TEXT -- gemessen, nicht vermutet.
     *
     * Hier stand zuerst ein preg_split auf ";" und ",". Das war aus dem
     * Altsystem uebernommen, wo die Funktionen als getrennte Liste in EINER
     * Spalte lagen (sy_vf_members.functions). Die REST-Schnittstelle liefert
     * stattdessen ein Feld -- bei einem Mitglied ohne Funktion das
     * aufschlussreiche [""], also ein Array mit einem leeren Eintrag.
     *
     * Aufgefallen ist es erst am echten Dienst, mit "Array to string
     * conversion". Eine Annahme aus dem Vorgaengersystem ist eben eine
     * Vermutung und keine Kenntnis.
     *
     * Beide Formen werden trotzdem gelesen: Es kostet nichts, und ein Feld,
     * das mal so und mal so kommt, waere sonst ein Fehler, der nur bei manchen
     * Vereinen auftritt.
     * ─────────────────────────────────────────────────────────────────────────
     *
     * @param  array<int|string, mixed>  $daten
     * @return list<string>
     */
    private function listOf(array $daten, string $feld): array
    {
        $gefunden = [];

        $sammle = static function (mixed $wert) use (&$gefunden, &$sammle): void {
            if (is_array($wert)) {
                foreach ($wert as $teil) {
                    $sammle($teil);
                }

                return;
            }

            if (! is_scalar($wert)) {
                return;
            }

            foreach (preg_split('/[;,]/', (string) $wert) ?: [] as $stueck) {
                $stueck = trim(self::decode($stueck));

                if ($stueck !== '') {
                    $gefunden[] = $stueck;
                }
            }
        };

        $sammle($daten[$feld] ?? null);

        return array_values(array_unique($gefunden));
    }

    /**
     * Die drei Ebenen eines Datensatzes -- Wert => Anzeigename.
     *
     * Der WERT traegt seine Ebene vorn und ist das, was in der Zuordnung steht.
     * Der ANZEIGENAME ist, was ein Mensch liest. Beim Status fallen die beiden
     * auseinander, und genau darum geht es: Der Wert haengt an der Nummer, der
     * Name am Wort, und nur die Nummer ueberlebt eine Umbenennung.
     *
     * @param  array<int|string, mixed>  $daten
     * @return array<string, string>
     */
    private function groupsOf(array $daten): array
    {
        $ebenen = [];

        foreach ($this->listOf($daten, 'functions') as $funktion) {
            $ebenen[self::FUNCTION_PREFIX.$funktion] = __('vereinsflieger.group.function', ['name' => $funktion]);
        }

        foreach ($this->listOf($daten, 'roles') as $rolle) {
            $ebenen[self::ROLE_PREFIX.$rolle] = __('vereinsflieger.group.role', ['name' => $rolle]);
        }

        $msid = trim((string) ($daten['msid'] ?? ''));
        $status = trim(self::decode((string) ($daten['memberstatus'] ?? '')));

        // Die Sammelgruppe aus der Einordnung -- damit eine Regel fuer "aktiv"
        // auch fuer alles gilt, was als aktiv gefuehrt wird.
        $sammelgruppe = MemberStatus::handlingFor($msid)?->membershipGroup();

        if ($sammelgruppe !== null) {
            $ebenen[$sammelgruppe] = __('vereinsflieger.group.membership', [
                'name' => __('vereinsflieger.status_handling.'.($sammelgruppe === 'mitglied:aktiv' ? 'active' : 'passive')),
            ]);
        }

        if ($msid !== '') {
            $ebenen[self::STATUS_PREFIX.$msid] = __('vereinsflieger.group.status', [
                // Der Name kann sich aendern, die Nummer nicht -- deshalb steht
                // sie mit dabei. Wer die Zuordnung spaeter liest, findet den
                // Status auch dann wieder, wenn er inzwischen anders heisst.
                'name' => $status !== '' ? $status : $msid,
                'id' => $msid,
            ]);
        }

        return $ebenen;
    }

    /**
     * Die Funktionen eines Datensatzes -- fuer subjectFrom().
     *
     * @param  array<int|string, mixed>  $daten
     * @return list<string>
     */
    private function functionsOf(array $daten): array
    {
        return array_keys($this->groupsOf($daten));
    }

    /**
     * Die Statusliste des Vereins -- mit Namen und Anzahl.
     *
     * Getrennt von groups(), weil daraus keine Rollen werden, sondern eine
     * ENTSCHEIDUNG: Bekommt dieser Status ueberhaupt Konten? Die Statusliste
     * gehoert deshalb dem Modul und nicht der Rollenzuordnung im Kern.
     *
     * @return list<array{msid: string, label: string, count: int}>
     */
    public function memberStatuses(): array
    {
        $gefunden = [];

        foreach ($this->rawMembers() as $eintrag) {
            $msid = trim((string) ($eintrag['msid'] ?? ''));

            if ($msid === '') {
                continue;
            }

            $gefunden[$msid] ??= [
                'msid' => $msid,
                'label' => trim(self::decode((string) ($eintrag['memberstatus'] ?? ''))),
                'count' => 0,
            ];

            $gefunden[$msid]['count']++;
        }

        ksort($gefunden, SORT_NATURAL);

        return array_values($gefunden);
    }

    /**
     * Wie mit diesem Datensatz zu verfahren ist.
     *
     * NULL heisst „nicht entschieden" und fuehrt zu keinem Konto -- wie
     * „ignorieren", aber aus einem anderen Grund. Die Oberflaeche kann die
     * beiden auseinanderhalten und zeigen, was offen ist.
     *
     * @param  array<int|string, mixed>  $daten
     */
    private function handlingFor(array $daten): ?MemberStatusHandling
    {
        $msid = trim((string) ($daten['msid'] ?? ''));

        if ($msid === '') {
            /*
             * Kein Status am Datensatz. Das kommt bei auth/getuser vor, wo die
             * Antwort schmaler ist als in der Liste -- und wer sich gerade
             * erfolgreich angemeldet hat, soll nicht daran scheitern, dass ein
             * Feld fehlt. Anmeldung ist ohnehin die staerkere Aussage: Der
             * Anbieter selbst hat ihn eben bestaetigt.
             */
            return MemberStatusHandling::Active;
        }

        return MemberStatus::handlingFor($msid);
    }

    /**
     * Vereinsflieger kodiert Sonderzeichen als HTML-Entities.
     *
     * Gemessen an den Arbeitsstunden-Kategorien: "Wartung&#47;Werkstatt" steht
     * fuer "Wartung/Werkstatt". Unbehandelt staende der Rohtext in der
     * Zuordnungsliste -- und wer ihn dort abtippt, traefe den echten Wert nie.
     */
    public static function decode(string $wert): string
    {
        return html_entity_decode($wert, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    /**
     * Die Anbindung dieses Providers.
     *
     * Ohne Angabe die, die als Identitaetsquelle gekennzeichnet ist -- es darf
     * hoechstens eine geben, siehe Connection.
     *
     * @throws RuntimeException wenn keine gekennzeichnet ist
     */
    public function connection(): Connection
    {
        $this->connection ??= Connection::identitySource();

        if ($this->connection === null) {
            throw new RuntimeException(
                'Keine Vereinsflieger-Anbindung ist als Identitaetsquelle gekennzeichnet. '
                .'Unter "VF-Anbindungen" bei genau einer den Haken setzen.'
            );
        }

        return $this->connection;
    }

    /**
     * Der Zugang der INSTANZ -- für den Abgleich, nicht für eine Anmeldung.
     */
    private function instanceClient(): VereinsfliegerClient
    {
        return $this->connection()->client();
    }

    /**
     * Aus einem VF-Datensatz ein externes Subjekt.
     *
     * @param  array<int|string, mixed>  $daten
     */
    private function subjectFrom(array $daten): ?ExternalSubject
    {
        $uid = (string) ($daten['uid'] ?? '');

        if ($uid === '') {
            return null;
        }

        /*
         * ─────────────────────────────────────────────────────────────────────
         * ZUERST: GIBT ES DIESEN MENSCHEN UEBERHAUPT?
         *
         * Vorgabe: „bei memberstatus interessieren mich initial nur 1 und 2.
         * alle anderen soll das modul initial abrufen und den admin entscheiden
         * lassen was damit passiert."
         *
         * Also wird hier nichts geraten. Vorbelegt sind ausschliesslich 1
         * (aktiv) und 2 (passiv); jeder andere Status wartet auf eine
         * Entscheidung, und bis dahin entsteht kein Konto.
         *
         * DASS EIN UNENTSCHIEDENER STATUS KEIN KONTO ERGIBT, IST ABSICHT und
         * nicht Bequemlichkeit: In der Referenzinstallation stehen 229 Menschen
         * auf "sonstige". Wuerde man die vorsorglich anlegen, entstuenden mit
         * einem Abgleich 229 Konten, ueber die niemand entschieden hat. Der
         * umgekehrte Fehler -- jemand fehlt -- faellt auf und ist reparabel.
         * ─────────────────────────────────────────────────────────────────────
         */
        $behandlung = $this->handlingFor($daten);

        if ($behandlung === null || ! $behandlung->createsAccount()) {
            return null;
        }

        /*
         * DIE DREI EBENEN SIND DIE GRUPPEN. Sie werden hier NUR gereicht; was
         * daraus an Rollen wird, entscheidet die Zuordnung im Kern.
         *
         * Dazu die SAMMELGRUPPE aus der Einordnung: Wer „Ehrenmitglied" als
         * aktives Mitglied fuehrt, soll seine Regel einmal fuer „aktiv"
         * schreiben und nicht fuer jede Statusnummer neu. Die genaue Nummer
         * bleibt trotzdem dabei, fuer den Fall, dass jemand doch unterscheiden
         * will.
         */
        $funktionen = $this->functionsOf($daten);

        $sammelgruppe = $behandlung->membershipGroup();

        if ($sammelgruppe !== null) {
            $funktionen[] = $sammelgruppe;
        }

        return new ExternalSubject(
            id: $uid,
            username: (string) ($daten['username'] ?? $uid),
            name: trim(
                self::decode((string) ($daten['firstname'] ?? ''))
                .' '
                .self::decode((string) ($daten['lastname'] ?? ''))
            ),
            email: ($daten['email'] ?? null) ?: null,
            groups: $funktionen,

            /*
             * ALLE, DIE EIN KONTO BEKOMMEN, DUERFEN SICH ANMELDEN -- auch
             * passive. Vorgabe: „passiv darf sich anmelden, die rechte werden
             * nach memberstatus und funktion gemappt."
             *
             * Was jemand DARF, entscheidet also die Zuordnung und nicht dieser
             * Schalter. Wer keine Zuordnung trifft, hat ein Konto ohne Rollen
             * -- und das ist genau der Zustand, in dem man nichts kaputtmachen
             * kann.
             */
            active: true,
        );
    }
}
