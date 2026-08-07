# Externe Identitäten — die Naht im Kern

> Vorgabe vom 2026-07-28 (E4): „VF ist ein Modul zur Anbindung, die eigentlichen
> Rechte werden im System gebaut und dann werden die User (bei VF ggf. die
> Funktionen) auf die systeminternen Rollen gematcht. Da gibt es ja auch Samba
> oder sonst was."

Ein Connector liefert nur die **Aussenseite**: Subjekte und Gruppen. Was daraus
an Rechten wird, entscheidet der Kern — für alle Provider gleich, weil die
Regeln nichts mit dem Protokoll zu tun haben.

```
Connector-Modul               Kern
───────────────               ────
Vereinsflieger  ──┐
LDAP / Samba AD ──┼──> ExternalSubject ──[role_mappings]──> Rollen ──> Rechte
OIDC            ──┘    (id, groups)                        (spatie/laravel-permission)
```

## Drei Tabellen — und die dritte ist die, die man vergisst

| Tabelle | hält fest |
|---|---|
| `external_identities` | wer bei welchem Provider wer ist |
| `role_mappings` | welches Subjekt / welche Gruppe welche Rolle bekommt |
| `external_role_grants` | **welche Rollen ein Provider tatsächlich vergab** |

Ohne die dritte lässt sich nicht unterscheiden, ob jemand „Mechaniker" aus einer
AD-Gruppe hat oder weil der Werkstattleiter sie gestern von Hand gab. Ein
Abgleich, der stumpf neu setzt, nimmt die zweite wieder weg — lautlos,
irgendwann nachts, und auffallen würde es dem Betroffenen am Samstag vor dem
Hangar.

**Die Regel ist damit scharf:** Der Provider darf genau das zurücknehmen, was er
selbst vergeben hat.

## Vier Regeln, jede mit einem Schaden dahinter

**1. Gefunden wird über die Kennung des Providers, nicht über die E-Mail.**
Adressen ändern sich; ein Abgleich, der daran hängt, legt beim nächsten Lauf ein
zweites Konto für denselben Menschen an — ohne dessen Rollen und Vergangenheit.
Die E-Mail darf beim **ersten** Mal verbinden (ein Verein, der lokal gepflegt
hat und dann einen Provider anschaltet, soll keine Doppelkonten bekommen), ab
dann trägt die Kennung.

**2. Zurückgenommen wird nur, was der Provider selbst vergab.** Siehe oben.

**3. Ausgetreten heisst deaktiviert, nicht gelöscht.** Ein gelöschtes Konto
reisst Löcher in die Nachweiskette, und hartes Löschen ist ohnehin verboten. Der
Zugang ist weg, die Vergangenheit bleibt lesbar.

**4. Freigabeberechtigung kommt nie von aussen.**

## Warum `certifying_staff` nicht zuordenbar ist

Aus der Analyse (§6.4): **Vereinsfunktion und Werkstattqualifikation sind zwei
verschiedene Dinge.** Wer im Verein „Werkstattleiter" heisst, ist eine
Organisationsaussage. Ob jemand freigabeberechtigt ist, ist eine
**Qualifikationsaussage** — mit Lizenznachweis, Recency (66.A.20) und
Haftungsfolge. Vereinsflieger kennt diese Kategorie nicht, ein Active Directory
ebenso wenig.

Eine automatische Ableitung würde also genau dort versagen, wo Korrektheit am
meisten zählt — und ein Audit fragt bei genau dieser Rolle nach dem Nachweis.

Deshalb **zwei Riegel**:

1. Beim **Anlegen** der Zuordnung — damit niemand einen Eintrag anlegt, der
   stillschweigend nichts tut. Ein Eintrag, der dasteht und wirkungslos ist, ist
   eine Zusage, die keiner hält.
2. Beim **Anwenden** — greift auch, wenn eine Rolle später umbenannt wurde oder
   eine Zuordnung aus einer Sicherung zurückkommt, die älter ist als diese Regel.

`CoreRoles::neverFromProvider()` zu leeren wäre die Entscheidung,
Freigabeberechtigung aus einer Gruppenmitgliedschaft abzuleiten.

## Was der Provider besitzt — und was nicht

Vorgabe: „die über einen provider kommen dürfen nur angezeigt, aber nicht
verändert werden."

| Gehört dem Provider | Gehört diesem Betrieb |
|---|---|
| Name | Rollen |
| E-Mail-Adresse | Qualifikationen (Part-66) |
| „Aktiv" | Passwort |

Links steht, was `LinkExternalIdentity` bei **jedem** Lauf neu setzt. Diese
Felder sind in der Benutzerverwaltung gesperrt — nicht als Warnung, sondern
als Sperre auf dem Server: Ein gesperrtes Filament-Feld wird nicht mit
abgeschickt, also kommt auch ein manipuliertes Formular nicht daran vorbei.
Ein Eingabefeld, dessen Wert um 2 Uhr morgens still zurückgesetzt wird, wäre
schlechter als gar keins.

Rechts steht, was kein Mitgliederverwaltungssystem wissen kann. Das ist keine
Ausnahme von der Regel, sondern ihre Bedingung: `certifying_staff` kommt nie
von außen. Wäre die Rollenauswahl bei Provider-Konten gesperrt, könnte in einem
Verein, dessen Mitglieder alle aus Vereinsflieger kommen, niemand je eine
Freigabeberechtigung erteilen — die Freigabe wäre unerreichbar.

**Der Provider besitzt die Identität. Was jemand tun darf, besitzt der
Betrieb.**

## Der Not-Aus

Aus der Regel oben folgte zunächst, dass sich ein Mitglied hier gar nicht mehr
von Hand abschalten ließ — `is_active` gehört ja dem Provider. Für den
geordneten Austritt ist das richtig. Für den ungeordneten nicht.

Deshalb gibt es eine **zweite, getrennte Aussage**:

| Spalte | Aussage | Wer schreibt sie |
|---|---|---|
| `is_active` | „Der Provider führt diesen Menschen als Mitglied." | der Abgleich, jede Nacht |
| `locked_at` | „Dieser Betrieb hat den Zugang gesperrt." | ein Administrator, von Hand |

Zugang gibt es nur, wenn beide ja sagen — `User::hasAccess()`, und die Frage
sitzt an genau einer Stelle, weil sie an dreien gestellt wird (Panel,
Rechteprüfung, `Gate::before`).

`LinkExternalIdentity` fasst `locked_at` nie an. Wer gesperrt ist, bleibt
gesperrt, auch wenn der Provider die Person munter als aktiv meldet, und auch
über ein Aus und Wieder-Ein dort hinweg. Eine Sperre, die nachts von selbst
aufgeht, wäre keine.

Das Sperren beendet außerdem die laufende Sitzung: Der Sitzungs-Cookie ersetzt
das Anmelden, ohne diesen Schritt bliebe ein Angemeldeter drin, bis er selbst
geht. Und niemand kann sich selbst sperren — sonst sperrt sich der einzige
Administrator aus und kann die Sperre danach nicht mehr aufheben.

## Zwei Provider nebeneinander

Ein Betrieb kann LDAP und Vereinsflieger gleichzeitig fahren. Weil jeder Grant
seinen Provider trägt, nimmt keiner dem anderen etwas weg — sonst hingen die
Rechte von der Reihenfolge der nächtlichen Läufe ab und wären damit zufällig.

## Was ein Connector können muss

```php
interface IdentityProvider
{
    public function name(): string;
    public function label(): string;
    public function supportsPassword(): bool;
    public function authenticate(string $username, string $password): ?ExternalSubject;
    public function members(): iterable;
}
```

Bewusst schmal. `null` aus `authenticate()` heisst **falsche Zugangsdaten**,
nicht „Fehler" — ist der Provider nicht erreichbar, gehört eine Ausnahme
geworfen. Ein Ausfall, der wie ein falsches Passwort aussieht, schickt einen
ganzen Verein auf die Suche nach seinem Tippfehler.

`supportsPassword()` gibt es wegen **OIDC**: Dort fliesst kein Passwort durch
diese Anwendung, der Connector leitet weiter und meldet danach über dieselbe
`ExternalSubject`-Struktur, wer zurückkam. Die Anmeldemaske soll dann gar kein
Formular anbieten, das niemand beantworten kann.

## Gruppen kommen aus dem Verein, nicht aus dem Code

> Vorgabe: „es geht bei der zuordnung um die funktionen die der verein angelegt
> hat. die ui muss entsprechend dynamisch sein."

Vereinsfunktionen sind Vereinssache. „Werkstattleiter" heisst anderswo
„Technischer Leiter" oder „Wart". Eine eingebaute Auswahl wäre für jede
Installation ausser einer falsch — und ein freies Textfeld wäre schlimmer: Wer
sich vertippt, legt eine Zuordnung an, die nie greift, und sieht keinen Fehler.

Deshalb die Erweiterung `DiscoversGroups`, **getrennt** von `IdentityProvider`:
Ein OIDC-Provider erfährt Gruppen erst aus dem Token des Anmeldenden und kann
sie nicht vorher aufzählen. Wer die Fähigkeit nicht hat, bekommt ein freies Feld
**mit Begründung** — keine leere Liste, die aussieht wie „dieser Verein hat
keine Funktionen".

### Der Befund zu Vereinsflieger

**Es gibt dort keinen Endpunkt für Vereinsfunktionen.** Nachgesehen im
offiziellen Client: zu Personen existieren `user/list` und `auth/getuser`, sonst
nichts. Die Funktionsliste entsteht also **aus den Mitgliedern** — mit zwei
Folgen, die beide in der Oberfläche stehen:

1. **Eine Funktion, die niemand trägt, ist unsichtbar.** Wer die Rechte für eine
   frisch angelegte Funktion vergeben will, könnte das sonst erst, nachdem der
   erste Mensch sie hat — also genau dann nicht, wenn er es braucht. Der Weg von
   Hand bleibt deshalb offen; solche Einträge gelten als **unbestätigt**, bis
   der Anbieter sie zum ersten Mal meldet.
2. **`members()` und `groups()` teilen sich eine Anfrage.** Der Dienst ist
   mengenbegrenzt; zwei Abrufe für dieselben Daten wären der teure Weg zum
   selben Ergebnis. Ein Test hält das fest.

Gespeichert wird in `external_groups`, und **gelöscht wird dort nie**:
Verschwindet eine Funktion beim Anbieter, behält sie ihre Zeile und wird als
„beim letzten Abruf nicht mehr vorhanden" geführt. Sonst verschwände mit der
Zeile die Erklärung, warum jemand einmal eine Rolle hatte. Wirkung hat so ein
Eintrag ohnehin keine mehr — in einer Gruppe, die es nicht gibt, ist niemand.

Abgerufen wird **auf Knopfdruck**, nie beim Seitenaufbau. Eine Auswahlliste, die
sich bei jedem Öffnen neu holt, würde bei einem begrenzten Dienst die Sperre
selbst herbeiführen.

## Rollen, Funktionen, Status — drei verschiedene Dinge

Gemessen an der Referenzinstallation (394 Sätze):

| | `functions` | `roles` |
|---|---|---|
| Was es ist | **Vereinsämter** | **Vereinsfliegers eigene Rechte** |
| Verschiedene Werte | 19 | 35 |
| Menschen ohne | 361 von 394 | 224 von 394 |
| Höchstzahl je Person | 5 | **10** |
| Beispiele | Fluglehrer, Schlepppilot, Zellenwart | „Standard (Administrator)", „LFZ bearbeiten", „Mitglied (nur eigene Daten)", „Website API" |

**die Erinnerung — Rollen tragen Rechte, Funktionen sind Etiketten — ist
belegt**, und zwar an drei Stellen in den Daten:

1. **Gestapelte Namen.** 148 Menschen haben „Mitglied", 143 davon zusätzlich
   „Mitglied (nur eigene Daten)". Ämter stapelt man nicht, Rechtebündel schon —
   und bis zu zehn davon.
2. **Stufen mit `+`.** Es gibt „Bordbuchkontrolle" *und* „Bordbuchkontrolle +",
   „Ausbildungsleiter" *und* „Ausbildungsleiter +". Ein Amt kennt keine
   Steigerungsform, eine Berechtigung schon.
3. **Namen, die reine Tätigkeiten sind.** „LFZ bearbeiten", „Dokumente
   bearbeiten", „Rundmail", „Mitgliederverwaltung", „Website API". Das sind
   Rechte, keine Posten.

**Trotzdem sind beide vereinsseitig frei benannt**, und beide sagen nichts über
eine Qualifikation. „Fluglehrer" als Rolle heißt „darf in Vereinsflieger
Fluglehrer-Dinge tun" — nicht „ist Fluglehrer". Für die Freigabeberechtigung
ändert das nichts: Sie kommt nach wie vor nie von außen.

## Der Mitgliedsstatus entscheidet, ob es die Person überhaupt gibt

> Vorgabe: „bei memberstatus interessieren mich initial nur 1 und 2. alle anderen
> soll das modul initial abrufen und den admin entscheiden lassen was damit
> passiert."

Vorbelegt sind deshalb **ausschließlich** `msid` 1 (aktiv) und 2 (passiv) — die
beiden im systemseitigen Nummernbereich. Jeder andere Status kommt als **offene
Entscheidung** an, mit drei Möglichkeiten:

| Behandlung | Konto | Anmeldung | Sammelgruppe |
|---|---|---|---|
| aktiv | ja | ja | `mitglied:aktiv` |
| passiv | ja | **ja** | `mitglied:passiv` |
| ignorieren | **nein** | — | — |

> Vorgabe: „passiv darf sich anmelden, die rechte werden nach memberstatus und
> funktion gemappt."

**Die Einordnung ist kein Zugangsschalter, sondern eine Zuordnungsebene.**
Meine erste Auslegung — „passiv = Konto ohne Zugang" — war falsch und ist
korrigiert. Passive Mitglieder melden sich an wie alle anderen; was sie
*dürfen*, entscheidet ausschließlich die Zuordnung. Hart wirkt nur
„ignorieren": Dann entsteht gar kein Konto.

**Und darin liegt der Nutzen:** Neben der genauen Statusnummer (`status:101`)
bekommt jedes Subjekt die Sammelgruppe seiner Einordnung. Wer „Ehrenmitglied"
als aktives Mitglied führt, schreibt seine Regel **einmal** für
`mitglied:aktiv` statt für jede Statusnummer neu — und wer doch unterscheiden
will, nimmt weiter die Nummer.

**Ein Konto allein kann nichts.** Rechte entstehen erst über die Zuordnungen.
Vorgabe: „Wenn irgendwann eine funktion dazu kommt hat sie einfach keine rechte
und kann nachgemappt werden." Genau dieser Zustand — angemeldet, aber ohne
Rechte — ist der Normalfall und tut niemandem weh.

## Abgerufen wird einmal täglich um 02:00

> Vorgabe: „Der VF abruf findet bitte genau einmal am tag um 2 uhr morgens statt."

`aeronance:vereinsflieger-sync`, geplant um **02:00** — vor allen anderen Läufen
(Lager 04:00, Aufbewahrung 04:30, Sicherung 05:00), damit wer nachts dazukommt
oder wegfällt schon in der Sicherung desselben Morgens steht.

**Ein Lauf, eine Antwort, mehrere Ergebnisse.** Vereinsflieger kennt weder einen
Endpunkt für Funktionen noch einen für Mitgliedsstatus — beides steht nur an den
Mitgliedern. Der Befehl holt `user/list` deshalb **genau einmal** und zieht
daraus Statusliste und Gruppen. Ein Test hält die Eins fest.

Die Knöpfe auf den beiden Seiten bleiben, sind aber als **Ausnahme für die
Ersteinrichtung** beschriftet: Sie lösen einen zusätzlichen Zugriff aus, und das
steht in der Bestätigung.

Ein abgeschaltetes Modul und ein fehlender Zugang sind **kein Fehler** — der
Befehl sagt es und geht. Sonst brächte der nächtliche Eintrag jeder Installation
ohne Vereinsflieger einen Fehlschlag, den niemand lesen will.

**Jeder Lauf frischt die Statusliste mit auf** — nicht nur der Knopf auf der
Seite. Legt der Verein später einen Status an, fiele derjenige,
der ihn trägt, sonst still aus dem Abgleich: kein Konto, kein Hinweis, und
niemand kann etwas zuordnen, das er nicht sieht. So bekommt jeder neue Status
beim nächsten Lauf seine Zeile, seine Kopfzahl und sein Abzeichen. Entscheiden
muss ihn weiterhin ein Mensch — geraten wird nichts.

**Nicht entschieden ≠ ignorieren.** Beide führen zu keinem Konto, aber nur das
eine ist eine Entscheidung. Die Seite zeigt offene Status samt Kopfzahl an und
trägt ein Abzeichen in der Navigation — sonst bekämen in der
Referenzinstallation 243 Menschen stillschweigend keinen Zugang, und niemand
wüsste, ob das so gewollt war.

Dass ein unentschiedener Status **kein** Konto ergibt, ist die sichere Richtung:
229 Menschen stehen auf „sonstige". Sie vorsorglich anzulegen hieße, mit einem
Abgleich 229 Konten zu erzeugen, über die niemand entschieden hat. Der
umgekehrte Fehler — jemand fehlt — fällt auf und ist reparabel.

Eine einmal getroffene Entscheidung wird **nie** überschrieben, auch nicht bei
1 und 2: Wer „aktiv" bewusst auf „ignorieren" gestellt hat, will das behalten.

## Mehrere Vereine an einer Installation

> Vorgabe: „ich möchte optional mehrere vereine koppeln können. Hintergrund ist
> da das cao umfeld: Verein ist mit seinen flugzeugen in der cao und diese
> bekommt so automatisch die stunden quasi live statt immer nachfragen zu
> müssen."

Die Zugangsdaten sind deshalb **Datensätze** statt Einstellungen — eine
Einstellung kann genau einen Zugang halten. Eine CAO betreut Luftfahrzeuge
mehrerer Vereine, und jeder Verein hat seinen eigenen Vereinsflieger.

### Der gefährlichste Haken

> Vorgabe: „mit mehreren anbindungen geben wir ggf leuten zugriff auf ein cao
> system."

Genau so ist es: Setzt jemand **„Mitglieder als Benutzer importieren"** bei
einem betreuten Verein, bekommen dessen Mitglieder Konten im System der CAO.
Deshalb ist der Haken ab Werk **aus**, rot beschriftet, und die Warnung steht
am Feld statt in einer Doku. Ohne ihn holt eine Anbindung **nur**
Betriebszeiten — niemand bekommt ein Konto.

**Genau eine** Anbindung darf ihn haben: Ein Mensch hat ein Konto, und zwei
Vereinsflieger vergeben dieselben Kennungen doppelt. Wird er woanders gesetzt,
verlieren ihn die übrigen — umgeschaltet statt abgewiesen, denn wer ihn setzt,
meint genau das.

### Was der nächtliche Lauf tut

| Schritt | Umfang |
|---|---|
| 1. Mitglieder, Gruppen, Status | nur die Identitäts-Anbindung |
| 2. Betriebszeiten | **jede** aktive Anbindung |
| 3. Arbeitsstunden hinüber | nur die Identitäts-Anbindung |

Die Reihenfolge ist die Vorgabe („nach den mitgliedern") und hat einen
Grund: Wer nachts neu dazukommt, soll sein Konto haben, bevor Arbeitsstunden
für ihn gebucht werden — sonst fällt er durch, weil es zu seiner Kennung noch
keine Zuordnung gibt.

**Eine scheiternde Anbindung beendet den Lauf nicht.** Ändert ein Verein sein
Passwort, bekommen die anderen trotzdem ihre Zeiten; der Fehler steht an der
Anbindung und ist dort zu sehen.

### Betriebszeiten

`maintenance/airplane/{Kennzeichen}` liefert `motortime`, `flighttime`,
`landingcount` und `towcount`. Die ersten drei werden Zählerstände;
**`towcount` bleibt liegen**, weil Aeronance Schleppstarts nicht als eigene
Zählerart kennt und ein Wert in der falschen Schublade schlechter ist als
keiner.

Geschrieben wird **nur bei Änderung** — ein Zählerstand ist unveränderlich, ein
nächtlicher Lauf erzeugte sonst 365 identische Zeilen im Jahr je Zähler und
Maschine. Und **ohne Person**: Diesen Stand hat niemand abgelesen.

Die Kopplung gehört dem VF-Modul, nicht der Flotte — mit dem Kennzeichen eigens
daneben, vorbelegt aus dem Luftfahrzeug und trotzdem änderbar, falls
Vereinsflieger anders schreibt.

## Stand

Die Naht steht und ist **ohne jeden Connector** geprüft. Der Kern bleibt ohne
Identity-Modul voll benutzbar: lokales Login plus lokale Rollenvergabe. Die
Zuordnungen haben jetzt eine Oberfläche (`RoleMappingResource`, Recht
`core.roles.manage`), die sich ausblendet, solange kein Connector eingetragen
ist.

**F19 ist beantwortet:** Der offizielle Client hasht den Klartext selbst
(ISO-8859-1 → MD5); wer einen fertigen Hash hineingibt, hasht doppelt. `appkey`
und `auth_secret` sind zwei verschiedene Dinge, und `auth_secret` kommt **nicht**
aus der Anmeldung — `auth/accesstoken` liefert nur den Token.

**F12 ist beantwortet, und die Antwort ist ein Nein.** Gemessen an 394 Sätzen:
`sonstige` 229, `aktiv` 91, `passiv` 60, `Externer Pilot` 11, `Ehrenmitglied` 3.
Wer „aktiv heißt aktiv" ableitet, sperrt **303 von 394** Konten aus. Das Feld
ist eine frei gepflegte Vereinskategorie, kein Zustandskennzeichen.

**Und `memberend` taugt auch nicht:** leer bei allen 394, während `memberbegin`
bei allen gefüllt ist. `user/list` enthält offenbar nur aktuelle Mitglieder —
wer austritt, verschwindet, statt ein Datum zu bekommen.

**Damit ist das Austrittsmerkmal die Anwesenheit in der Liste selbst**, und der
Abgleich braucht einen Vergleich mit dem letzten Lauf statt eines Feldes. Das
ist heikel: Beim Bau lieferte `user/list` einmal nur 81 KB und brach ab —
hieße „fehlt" automatisch „ausgetreten", hätte dieser eine Abruf den halben
Verein deaktiviert. Deshalb erst die Regeln (wie viele Läufe, welche
Plausibilitätsgrenze), dann der Abgleich. Siehe F38.

**Noch offen:** der Mitglieder-Abgleich selbst — `members()` ist gebaut, aber
kein Befehl ruft es auf. Bis dahin wird niemand automatisch deaktiviert.
