# LTA / TM — Lufttüchtigkeitsanweisungen und Technische Mitteilungen

die Vorgabe:

> Da geht es vor allem um die Übersicht, die ich dann bestätigen kann (Zeile für
> Zeile). Die Übersichtsliste ändert sich herstellerseitig nicht oder wird länger.
> Daher sind abgehakte Punkte so lange abgehakt, bis ihre Laufzeit kickt.

---

## 1. Zwei Tabellen, und die Trennung ist der ganze Entwurf

**`directives`** ist die Zeile des Herstellers oder der Behörde. Sie existiert
**einmal**, egal wie viele Flugzeuge sie betrifft.

**`directive_applications`** ist, was *dieser Betrieb* zu dieser Zeile für *ein*
Flugzeug sagt. Das ist der Datensatz, den ein Prüfer liest — und er ist pro
Flugzeug, weil dieselbe LTA an der D-KABC durchgeführt und für die D-KXYZ nicht
zutreffend sein kann.

Die beiden zu verschmelzen wäre der naheliegende Fehler gewesen: Eine Anweisung
trüge dann die Antwort *eines* Flugzeugs, und ein Herstellerimport würde
Beurteilungen überschreiben, die jemand schon getroffen hat. Ein Test prüft
genau das.

---

## 2. Verbindlichkeit ist unabhängig von der Art

> Beachte bitte auch den Status der TM: optional, mandatory, SB … nur optional
> darf den Status nicht durchgeführt erhalten.

Die erste Fassung leitete die Verbindlichkeit aus der **Art** des Dokuments ab —
LTA verbindlich, TM nicht. Das war falsch: Eine TM wird verbindlich, sobald eine
Behörde sie übernimmt, **ohne ihre Nummer oder ihre Art zu ändern**. Abgeleitet
war dieser Fall nicht darstellbar.

Jetzt sind es zwei Felder: `kind` sagt, *was* das Dokument ist (LTA, AD, TM, SB),
`bindingness` sagt, *ob es sein muss*. Der Default ist **verbindlich** — die
sichere Seite: Etwas Bindendes als optional zu führen erlaubt seine Umgehung,
umgekehrt steht es nur so lange auf der Liste, bis jemand die Zeile korrigiert.

## 3. Vier Antworten, nicht zwei

> Es gibt aber nicht nur ja/nein, sondern auch nicht zutreffend (mit Begründung)
> und nicht durchgeführt.

| Zustand | heißt |
|---|---|
| **nicht beurteilt** | Niemand hat diese Zeile gelesen |
| **durchgeführt** | Gemacht — bei wiederkehrenden bis die Laufzeit greift |
| **nicht zutreffend** | Beurteilt, betrifft uns nicht — **mit Begründung** |
| **nicht durchgeführt** | Betrifft uns, ist bekannt, ist nicht gemacht — **mit Begründung** |

**Das entscheidende Paar ist „nicht beurteilt" und „nicht durchgeführt".** Beide
heißen, dass die Arbeit nicht passiert ist. Nur eines heißt, dass jemand das
*entschieden* hat. In einer Liste sehen sie gleich aus und sagen Entgegengesetztes
über die beteiligten Menschen — deshalb sind es zwei Zustände und nicht einer.

### Nur Optionales darf abgelehnt werden

**Für eine verbindliche Anweisung gibt es „nicht durchgeführt" nicht.** Es
existiert keine Erklärung, mit der man das Überspringen einer LTA erklärt: Sie ist
durchgeführt, sie trifft nicht zu, oder sie steht im Weg. Ein Test prüfte vorher
das Gegenteil und ist mit korrigiert.

### Und dafür braucht es Part-66 oder den Halter — nicht P/O

> Nicht durchgeführt braucht auch Part-66 oder Halter (nicht P/O).

Das ist eine **andere Art** von Entscheidung als die übrigen. Durchführen ist
technisch. „Trifft nicht zu" ist technisch. Dass eine Empfehlung *nicht befolgt*
wird, ist entweder eine technische Beurteilung (Part-66) oder die Entscheidung des
Halters über sein eigenes Luftfahrzeug — Kosten, Nutzen, Zeitpunkt.

Eine **Pilot-Owner-Berechtigung ist keins von beidem**: Sie ist die enge Befugnis,
eigene begrenzte Instandhaltung abzuzeichnen. Über das Aufheben einer
Herstellerempfehlung sagt sie nichts, und sie hier genügen zu lassen wäre derselbe
Fehler wie den Pilot-Owner fremde Arbeit freigeben zu lassen.

Vermerkt wird die **Eigenschaft**, in der jemand gehandelt hat — Lizenz oder
Halter. Zwei Jahre später ist das die interessante Frage: technische Beurteilung
oder Entscheidung des Betreibers?

### Nicht beurteilt ist die rote Flagge

> Nicht beurteilt ist ne red flag und verhindert die Freigabe.

Eine ungelesene Zeile blockiert **unabhängig von ihrer Verbindlichkeit** — denn
niemand hat sie gelesen, also kann niemand sagen, ob es die harmlose ist. Die
Unsicherheit ist das Problem, nicht die Anweisung.

Und sie verhindert die **Freigabe**, was eine Kopplung über Modulgrenzen bedeutet.
Gelöst über die bestehende Naht: `OpenItem` hat ein zweites Flag `blocksRelease`,
die Lufttüchtigkeitsprüfung kann danach filtern, und `IssueRelease` fragt die
Prüfung der *Flotte* — die Arbeitskarten kennen die LTA nicht und müssen es nicht.
Ein Verein ohne LTA-Modul bekommt eine leere Liste und merkt nichts.

**Was dabei ausdrücklich nicht blockiert:** die eigenen Meldungen der Flotte. Eine
abgelaufene ARC macht das Flugzeug nicht lufttüchtig, aber die daran ausgeführte
Instandhaltung bleibt freigebbar — eine CRS bescheinigt *Arbeit*, nicht
Flugtauglichkeit. Genau dafür ist das zweite Flag da und nicht `blocking`
wiederverwendet.

„Nicht durchgeführt" macht das Flugzeug **nicht** lufttüchtig: Eine verbindliche
Anweisung blockiert weiter, und die Lufttüchtigkeitsprüfung sagt es. Was der
Zustand leistet, ist einen Namen und einen Grund neben die Tatsache zu stellen —
der Unterschied zwischen einer Entscheidung und einem Versehen.

**Verbindlich vs. nicht:** Eine nicht durchgeführte LTA/AD blockiert. Eine TM/SB
erscheint auf der Liste, blockiert aber nicht — das ist eine Entscheidung, für die
der Betrieb einsteht.

**Alle drei Beurteilungen brauchen eine Qualifikation.** „Nicht zutreffend" ist
dabei *nicht* die vorsichtige Variante, sondern die gefährlichste: falsch gesetzt
verschwindet eine verbindliche Anweisung still aus der Liste. Die Zuordnung steht
in `Authority::REQUIRES_QUALIFICATION`, wo die anderen Feststellungen auch stehen.

---

## 4. Wer betroffen ist — und warum das Werkzeug zum Ja neigt

Alle drei Bezüge, die genannt wurden: **Muster**, **Bauteil (S/N-Bereich)**,
**Motor/Propeller** getrennt. Die letzten drei sind technisch derselbe Fall — ein
Teil mit Seriennummer — bleiben aber auseinander, weil Hersteller sie als eigene
Listen mit eigenen Nummernkreisen veröffentlichen.

Die Zuordnung antwortet bei Unklarheit mit **Ja**:

- Ein Flugzeug **ohne erfasste Komponenten** trifft eine Bauteil-Anweisung, statt
  ihr zu entgehen. Nicht erfasst heißt nicht „nicht verbaut".
- Eine **unbekannte Seriennummer** fällt in jeden Bereich.
- Ein **leeres Muster** trifft jede Musterangabe.

Das ist sicher, weil die Antwort nur ein **Vorschlag für die Liste** ist —
jemand beurteilt jede Zeile ohnehin und kann sie mit Begründung wegräumen. Die
andere Richtung wäre nicht sicher: Da würde eine verbindliche Anweisung
verschwinden, weil Daten fehlen.

Mustervergleich als Teilstring in **beide** Richtungen: Hersteller schreiben
„ASK 21", die Flotte „ASK 21 B", und beides kann das längere sein. Exakter
Vergleich hätte halbe Vereinsflotten stillschweigend ausgenommen.

**Seriennummern werden natürlich verglichen** (`strnatcasecmp`), und das ist kein
Detail: Hersteller schreiben „0123", „A-45" und „1000 and up". Reiner
Textvergleich geht bei Nullauffüllung in die *gefährliche* Richtung schief —
`"99" > "0100"` lexikografisch, ein Teil mit Nummer 99 fiele also in einen Bereich
ab 0100 und würde als betroffen gemeldet. `strnatcasecmp` vergleicht Zahlen als
Zahlen (`99 < 100`) und liest „A-45" < „A-99" so, wie ein Mensch es täte.
Groß-/Kleinschreibung spielt keine Rolle.

---

## 5. Die Liste wird länger, nie kürzer

Der Importer **fügt hinzu und aktualisiert, er entfernt nie**. Eine Zeile, die
aus der Herstellerdatei verschwunden ist, wird hier nicht gelöscht: Ein gekürzter
Export, eine geänderte URL und ein kaputter Parser sehen identisch aus — und die
Beurteilungen gingen mit.

**Aktualisiert wird nur innerhalb derselben Quelle.** Ein Herstellerabruf darf
keine handgetippte Zeile anfassen, auch nicht eine mit derselben Nummer. Genau
dafür existiert `ManualSource` als *Quelle* und nicht als Abwesenheit einer
Quelle.

Eine neuere Anweisung **ersetzt** eine ältere, statt sie zu löschen. „Durchgeführt
nach LTA 2019-05, ersetzt durch LTA 2024-11" ist die Geschichte, nach der ein
Prüfer fragt.

---

## 6. Die Quellen-Naht

> Wo möglich per Hersteller-Untermodul im Modul ein Download. Wo das nicht geht,
> manuell und CSV.

Zwei Quellen gibt es immer — **Manuell** und **CSV** — plus eine Registry, in die
ein Herstelleradapter sich selbst einträgt. Er erscheint dann in der Importmaske,
**ohne dass sich hier etwas ändert**: Das Select wird aus der Registry gefüllt.

Bewusst **noch nicht gebaut**: ein echter Herstelleradapter. Jeder Publisher macht
es anders, das ist ein Parser pro Quelle — und den ersten zu schreiben, bevor der
manuelle Weg trägt, hieße das ganze Modul daran aufzuhängen. Die Naht ist da,
damit der erste Adapter eine Ergänzung ist und kein Umbau.

Der CSV-Leser erkennt Semikolon und Komma (deutsches Excel gegen alles andere —
falsch geraten wird die ganze Datei eine Spalte), liest mit oder ohne Kopfzeile,
überspringt Zeilen ohne Nummer oder Titel und **rät keine Daten**: Was nicht als
deutsches oder ISO-Datum lesbar ist, bleibt leer. Eine falsche Frist ist schlimmer
als eine fehlende, weil ein leeres Feld sichtbar ist.

---

## 7. Wiederkehrend

> Abgehakt bleibt abgehakt, bis die Laufzeit greift.

Gerechnet wird vom **Tag der Durchführung**, nicht von der Fälligkeit — wer früh
prüft, ist früh wieder dran. Kalender und Zähler zählen unabhängig, jeder für sich
genügt: dieselbe Regel, die die Flotte schon auf Bauteil-Laufzeiten anwendet.

Ein Zähler-Intervall zählt ab dem **Stand bei Durchführung**: Ein Flugzeug mit 300
Stunden bekommt seinen nächsten 100-Stunden-Punkt bei 400.

Eine negative Antwort trägt **keine** Wiederkehr — es gibt nichts, das
wiederkommen könnte.

---

## 8. Was zur Lufttüchtigkeit gemeldet wird

Über die Erweiterungsstelle der Flotte, nie durch Hineingreifen — der **zweite**
Nutzer dieser Schnittstelle nach den Befunden, und damit der erste Beleg, dass es
die richtige Naht war.

Vier verschiedene Dinge erscheinen:

1. eine Zeile, die niemand gelesen hat
2. eine Zeile, die jemand als nicht durchgeführt vermerkt hat
3. eine wiederkehrende, deren Laufzeit gegriffen hat
4. **eine Anweisung, die dieses Flugzeug betrifft und überhaupt keine
   Beurteilungszeile hat**

Der vierte Fall wäre sonst unsichtbar: Ein Import legt keine Beurteilungszeilen
an, also landet eine neue LTA in der Datenbank und nichts auf der Flugzeugseite
erwähnt sie. Hier zur Laufzeit ermittelt statt beim Import materialisiert —
Betroffenheit ist eine Beurteilung, die sich ändert, wenn ein Bauteil eingebaut
wird, und eine beim Import geschriebene Zeile fröre die Antwort von gestern ein.

---

## 9. Arbeitskarte aus einer Zeile

Optional wie die Teileentnahme: Dieses Modul fordert die Flotte, **nicht** die
Arbeitskarten — ein Verein mit LTA-Liste und ohne Vorgänge ist ein echter Fall, und
dort funktioniert die Liste als einfaches Häkchen.

Die Karte trägt Frist und Fundstelle in die Anweisung, damit der Mensch am Flugzeug
nicht suchen muss. Als Tätigkeitsart nimmt sie `AdCompliance` — die gab es im
Arbeitskarten-Modul **schon, bevor dieses hier existierte**, und genau das ist der
Grund, warum die beiden zusammenpassen, ohne dass eines das andere kennt.

**Die Karte erledigt die Anweisung nicht von selbst** — anders als beim Befund.
Ein Befund ist ein Mangel, den jemand gemeldet hat; die Durchführung einer LTA ist
eine Aussage gegenüber einer Behörde, und die trifft jemand Qualifiziertes
ausdrücklich, mit der Kartennummer als Nachweis. Die Karte organisiert die Arbeit,
sie unterschreibt sie nicht.

---

## 10. Zwei Ansichten derselben zwei Tabellen

Die **Anweisungsliste** zeigt Zeilen und welche Flugzeuge sie betreffen. Die
**Übersicht je Luftfahrzeug** ist die Transponierte — und die braucht eine
Jahresnachprüfung: Flugzeug wählen, jede betreffende Zeile durchgehen, jede
beantworten.

Keine der beiden ist überflüssig: Ein Import will die zeilenzentrierte Sicht
(„welche Flugzeuge trifft diese neue LTA?"), eine Nachprüfung diese („was ist an
der D-KABC noch offen?").

Die Übersicht baut sich aus der **Vereinigung** von „könnte zutreffen" und
„Beurteilung existiert". Beide Hälften können etwas enthalten, das die andere nicht
hat: Eine frisch importierte LTA hat noch keine Beurteilung, und eine Beurteilung
überlebt die Betroffenheit, wenn ein Bauteil ausgebaut wird. Eine der beiden
weglassen hieße Zeilen verstecken, die jemand sehen muss.

Als **Papier** (A4 quer) mit der Zählung oben — inklusive der Warnung, wenn Zeilen
ungelesen sind — und Unterschriftsfeldern. Eine Übersicht ohne Datum und Namen ist
ein Ausdruck; mit ihnen ist sie die Aussage, dass jemand die Liste durchgearbeitet
hat.

---

## 11. Hersteller kommen per Konfigurationsdatei

> Bau das Modul so, dass ich den Abrufmechanismus pro Hersteller per Config File
> einspielen kann, das macht die Verbreitung und Updates einfacher.

Der Mechanismus liegt jetzt **einmal** im Release, jeder Hersteller ist eine
YAML-Datei. Ein Verein, der etwas fliegt, das wir nie vorgesehen haben, legt eine
Datei ab und hat einen Import — ohne auf ein Release zu warten.

### Daten, kein Code — und das ist eine harte Grenze

CLAUDE.md: *„Kein Code-Nachladen zur Laufzeit — in keiner Ausbaustufe."*

Eine Spezifikation, die einen Callback tragen könnte, machte **jede
Herstellerdatei zu einem Weg, beliebiges PHP auf dem Vereinsserver auszuführen** —
genau die Tür, die diese Leitplanke schließt. Eine Spec besteht deshalb aus
Mustern und Feldzuordnungen, interpretiert von **einem** Treiber, der mit dem
Release kommt. Für Ausdrücke oder Rückrufe gibt es im Format keinen Platz.

**Der Preis, klar benannt:** Das liest Hersteller, die eine **Tabelle**
veröffentlichen. Wer ein PDF oder eine per JavaScript aufgebaute Liste
veröffentlicht, braucht weiter eine Klasse. Die Klassen-Naht bleibt dafür
bestehen.

### Zwei Verzeichnisse, und das zweite ist der Grund

| | |
|---|---|
| `resources/directive-sources/` | ausgeliefert, wird beim Update ersetzt |
| `storage/app/directive-sources/` | die eigenen, vom Update unberührt |

Updates laufen über `git checkout <tag>` und würden alles im Repo überschreiben —
die eigene Herstellerdatei eines Vereins muss also dort liegen, wo CLAUDE.md
ohnehin zusichert, dass Updates nicht hinreichen: `storage/`.

**Eine lokale Datei gewinnt** gegen eine ausgelieferte gleichen Namens. Absicht:
Baut ein Hersteller seine Seite mitten im Release-Zyklus um, repariert der Verein
das noch am selben Nachmittag — und die Reparatur überlebt das Update, das
dieselbe Reparatur ausliefert.

### Fehler werden gemeldet, nicht verschluckt

Eine kaputte Datei nimmt die anderen **nicht** mit. Sie wird übersprungen, und
`SpecRepository::problems()` sagt welche und warum — denn eine still fehlende
Quelle sieht genauso aus wie ein Hersteller, der nichts Neues veröffentlicht hat.

Aus demselben Grund werden **alle Muster beim Laden übersetzt**, nicht erst beim
ersten Gebrauch: Ein kaputter regulärer Ausdruck in einer Herstellerdatei
erschiene sonst als „dieser Import hat nichts gefunden".

### Der Nachweis: dieselben Erwartungen, andere Mechanik

Die Tests des Config-Treibers stellen **exakt dieselben Anforderungen** wie zuvor
der handgeschriebene Schleicher-Adapter, gegen **dieselben gespeicherten Seiten**.
Hätte eine Spec plus ein generischer Treiber sie nicht erfüllt, wäre der Ansatz
gescheitert und hätte nicht ausgeliefert werden dürfen.

**608 Zeilen PHP wurden eine YAML-Datei.** Was daran wirklich
herstellerspezifisch war, waren Muster und Spaltenindizes — alles andere war die
Arbeit, die jede tabellenförmige Liste braucht. Genau deshalb ließ es sich
überhaupt zu Konfiguration machen, und genau deshalb kann ein Hersteller mit PDF
es nicht: dort unterscheidet sich die Arbeit selbst.

### Was in der Spec steht

Am Beispiel Schleicher: Indexseite und Link-Muster (mit **benannten Gruppen**,
damit die Spec sagt, welcher Treffer welcher ist, statt sich auf die Reihenfolge
zu verlassen), Tabellen-/Zeilen-/Zellenmuster, die Spaltenzuordnung, die Liste
optionaler Wendungen und die Überschreibung für „optional, aber mit hartem
Termin".

Alle fachlichen Regeln aus Abschnitt 4 gelten unverändert: Behördennummer
gewinnt, unbekannte Wendung ist verbindlich, Fristen werden nicht aus Prosa
gezogen, Wiederkehr nie abgeleitet. Sie stehen jetzt nur an einer Stelle statt in
jedem Adapter.

### Zweiter Modus: JSON

Der Treiber liest zweierlei, und die Spec sagt welches:

| Modus | wofür |
|---|---|
| `type: table` | eine HTML-Tabelle — Schleicher, Schempp-Hirth |
| `type: json` | ein Endpunkt, Feldpfade statt Spaltenindizes — noch kein Hersteller |
| `type: list` | eine Liste von Links, Felder per Muster — Lindner/Grob |
| `type: overview` | das Übersichts-PDF eines Herstellers — Schleicher, LBA |
| `type: aura` | ein Portal-RPC, wenn es keine Übersicht gibt — Diamond (§14) |

DG war der Anlass: Deren Seite baut die Liste **per JavaScript** aus einem
WordPress-Dateimanager auf — im HTML stehen null Tabellen und null PDF-Links,
jeder Serienlink ist ein `href="#"` mit `data-idcat`. Als Tabelle war das nie
lesbar.

Geändert hat sich nur, **wo ein Feld steht**: ein gepunkteter Pfad statt eines
Zellenindex. Alle fachlichen Regeln gelten unverändert und stehen weiter an
einer Stelle.

Der Pfadausdruck ist **bewusst winzig** (`files`, `data.items`). Eine richtige
Ausdruckssprache wäre ein Weg, Specs schlau zu machen — und schlaue Specs sind,
wie ein Konfigurationsformat wieder zu Code wird.

### Zugangsdaten stehen nie in der Spec

Eine Spec nennt höchstens ein **Profil** (`auth: schempp`), die Werte stehen in
`.env` als `DIRECTIVES_SCHEMPP_USER` / `_PASSWORD`. Das hält eine Herstellerdatei
teilbar: Ein Verein gibt seine Datei weiter, ohne sein Passwort mitzugeben, und
eine Spec im Repo kann keines verlieren. Ein Test prüft das über **alle**
ausgelieferten Specs, weil die Regel dem Format gilt, nicht einer Datei.

Übertragen werden sie als **Header**, nie in der URL — eine URL landet in
Zugriffs- und Proxy-Logs. Fehlen sie, meldet sich die Quelle als **unbenutzbar**,
statt eine leere Liste zu liefern: Leer ist von „Hersteller hat nichts Neues"
nicht zu unterscheiden.

### Dritter Weg: Formular-Login mit Sitzung

Schempp-Hirth führt seine TM/LTA hinter TYPO3s `felogin`. Dessen Formular trägt
**pro Aufruf eigene versteckte Felder** (`__referrer[...]`, `__trustedProperties`),
ein fester POST-Rumpf funktioniert also nicht — das Formular muss erst **gelesen**
und dann beantwortet werden.

**Die angenehme Überraschung:** Dahinter liegt eine *ganz normale Tabelle*, auf
**einer** Seite, für alle Muster zugleich (458 Zeilen, Spalten `TM Nummer |
Betreff | Datum | LTA/AD Nummer`). Modus drei ist damit kein dritter Parser,
sondern der bestehende Tabellenmodus plus Sitzung — plus zwei Kleinigkeiten:

- **Einzelseite ohne Index** (`page.url`): Wo alles auf einer Seite steht, wären
  Indexadresse und Linkmuster zwei sinnlose Einträge in der Spec
- **Eigene Datumsspalte**: Schleicher packt Nummer und Datum in eine Zelle,
  Schempp-Hirth gibt dem Datum eine eigene. Eine Ergänzung, keine Änderung — die
  Schleicher-Spec bleibt unberührt, was deren Tests weiter belegen

**Ein `success_pattern` ist Pflicht bei Login-Quellen.** Ohne es liefert ein
falsches Passwort die Login-Seite zurück: HTTP 200, keine Zeilen — ein Import, der
aussieht wie „der Hersteller hat nichts veröffentlicht".

### Unvollständige Zertifikatskette

Schempp-Hirths Server sendet **nur sein eigenes Zertifikat** und lässt das
Zwischenzertifikat (RapidSSL TLS RSA CA G1) weg. Browser überspielen das, strenge
Clients nicht.

Eine Spec darf deshalb ein **CA-Bündel benennen**, das die Kette *vervollständigt*.
Das ist das Gegenteil davon, die Prüfung abzuschalten: Das Zwischenzertifikat muss
weiterhin auf eine vertraute Wurzel führen. **Eine Option, die Verifikation
auszuschalten, gibt es in diesem Format nicht** — ein Test prüft über alle Specs,
dass keine `verify`- oder `insecure`-Angabe existiert. In einer Konfigurationsdatei
sehen „Kette vervollständigen" und „Prüfung abschalten" ähnlich aus und sind
Gegenteile.

Kein sicherheitskritischer Fehler bei Schempp-Hirth, aber ein echter: Das
Zertifikat ist gültig, die Verschlüsselung unberührt — die Prüfung schlägt nur
dort fehl, wo sie streng erfolgt.

### Vierter Weg: eine Liste von Links

LTB Lindner, Musterbetreuer der Grob-Segelflugzeuge, veröffentlicht über ein
WordPress-PDF-Plugin: **ein `<li>` je Dokument**, keine Tabelle, kein Login.
Nummer und Betreff stecken in **einem** String („TM-G05 Seilrollen Durchmesser
42mm"), also wird jedes Feld über ein **eigenes Muster** gefunden statt über eine
Spaltenposition. Das ist der einzige Unterschied zum Tabellenmodus.

**Die Falle war eine andere als erwartet.** Lindner führt **zwei Nummernschemata
auf einer Seite**: die eigenen Mitteilungen (`TM-G11`, `A-I-G01`) neben Grobs
ursprünglichen, nach Muster nummerierten (`315-76`, `315-GROB-003` — 315 ist die
Kennblattnummer der G 103). Mein erstes Muster traf nur die ersten und verschluckte
**neunzehn echte Anweisungen** — genau das Versagen, gegen das dieses Modul
gebaut ist.

Die Lösung: das **erste Wort des Titels**, sofern es eine Ziffer *und* einen
Bindestrich enthält. Das lässt jede Kennung beider Schemata durch und hält
Lindners eigene Übersichts-PDF draußen, deren Titel mit einem nackten „G"
beginnt. Ein Test hält fest, dass **jeder** Eintrag außer genau diesem einen zu
einer Zeile wird — falls ein späteres Muster wieder still zu verwerfen beginnt,
fällt es dort auf.

Ein zweiter Fehler derselben Art: Das Nummernmuster lief zunächst über den
gesamten Eintrag und traf den **Dateinamen** in der URL — heraus kam
„LTA-TM-Uebersicht-30.06.2026.pdf" als Nummer und Kleinschreibung, wo der Titel
Großschreibung sagt. Verankert am Titelattribut stimmt es.

**Kein Datum wird übernommen, und das ist Absicht.** Die Einträge tragen ein
`data-date` — aber **83 der 92** auf der G-103-Seite tragen dasselbe
(2021-04-05): der Tag des Sammelimports, nicht der Ausgabetag. Es zu nehmen hieße,
83 falsche Daten in Flugzeugbücher zu schreiben. Ein leeres Feld ist sichtbar, ein
falsches nicht.

### Fünf Seiten, fünf Dateien — und eine, die anders ist

Lindner führt je Muster eine Seite: G 102, G 103, G 103 SL, G 104, Phoebus. Vier
Dateien unterscheiden sich nur in Adresse und Namen; die fünfte zeigt, warum eine
Spec **pro Seite** gilt und nicht pro Hersteller.

**Phoebus nummeriert anders.** Jeder Titel trägt einen zweistelligen Listenzähler
*vor* der eigentlichen Kennung:

```
16 252-13 Erhöhung der maximalen Flugmasse
05 2 Einbau einer Totalenergiedüse
```

Die Grob-Regel (erstes Wort, muss Ziffer *und* Bindestrich enthalten) trifft davon
**keinen einzigen**: Das erste Wort ist der Zähler, und eine Kennung ist ein
nacktes „2". Wiederverwendet hätte sie einen leeren Import erzeugt — der aussieht
wie ein Hersteller ohne Neuigkeiten. Phoebus überspringt daher den Zähler und
nimmt, was folgt.

Was für alle fünf gilt und ein Test über alle fünf festhält: **genau ein Eintrag
je Seite fällt weg**, und das ist die Übersichts-PDF.

| Seite | Einträge | Zeilen |
|---|---|---|
| G 102 | 49 | 48 |
| G 103 | 30 | 29 |
| G 103 SL | 40 | 39 |
| G 104 | 12 | 11 |
| Phoebus | 17 | 16 |

### DG Aviation: keine Spec, und warum das die richtige Antwort ist

DG hat den JSON-Modus veranlasst — und dann keine Spec gerechtfertigt.

Der Weg dahin, weil er für den nächsten Versuch nützlich ist: Die Seite baut ihre
Liste über das Plugin *WP File Download*. Der Aufruf steht in deren eigenem
Skript und lautet

```
admin-ajax.php?juwpfisadmin=false&action=wpfd&task=files.display&view=files
                &id=<Kategorie>&rootcat=<Wurzel>
```

Die Antwort trägt laut Skript `content.files` und `content.downloadable_files`.
**Getestet ist das nicht:** Der Endpunkt antwortet anonym mit **404** — auch mit
den korrekten Parametern, den echten Kategorie-IDs aus der Seite (281, 282, …)
und der richtigen `rootcat`. Und die Seite selbst enthält für einen anonymen
Besucher **keinerlei Dokumentinhalt**: keine Tabelle, keine Titel, keine
Download-Links.

Zwischenzeitlich lag hier eine DG-Spec mit geratenen Feldpfaden und einem
Kommentar „unverified". Anmerkung dazu, zu Recht:

> Zu DG: wir raten nicht. Niemals.

Das war inkonsistent: Dasselbe Modul weigert sich, eine Frist aus einem Satz zu
ziehen, eine Wiederkehr abzuleiten oder ein Hochladedatum als Ausgabedatum zu
nehmen — und hätte dann Feldnamen ausgeliefert, die eine Vermutung sind. Ein
Kommentar heilt das nicht; eine ausgelieferte Datei ist eine Behauptung.

**Der Modus bleibt, die Spec ging.** Ein Hersteller mit API ist ein offensichtlicher
Fall, und der Treiber kann ihn. Geprüft wird er gegen eine **Beispielspec** unter
`tests/Fixtures/Specs/`, die niemanden beschreibt — ein Mechanismus lässt sich
beweisen, ohne eine Tatsache über einen Hersteller zu behaupten.

Ein früherer Fehlschluss dazu ist ebenfalls erledigt: Ich hatte aus dem 404
geschlossen, DG brauche einen Login. Ein 404 eines Plugin-Endpunkts heißt „nicht
auf diesem Weg", nicht „nicht ohne Passwort".

---

### Schleicher Triebwerke und Propeller: drei Dateien, kein zweiter Parser

Die Vermutung, diese Seiten hätten eine andere Struktur, war falsch. Sie tragen
dieselbe `tableFormated`-Tabelle mit denselben acht Spalten wie die
Flugzeugseiten. Es sind drei ganz gewöhnliche Einzelseiten-Specs:
`schleicher-solo`, `schleicher-rotax`, `schleicher-propeller`.

**Die eigentliche Falle liegt in der URL.** `/tm-lta-wa/tm-triebwerke/` und
`/tm-lta-wa/propeller/` sind Verteilerseiten: eine Liste von Links, keine
einzige Tabellenzeile. Zeigt eine Spec dorthin, liefert der Server HTTP 200,
der Treiber findet keine Tabelle und gibt null Zeilen zurück — ein
kerngesunder Lauf mit der Aussage, Schleicher habe zu Triebwerken nichts
veröffentlicht. Ein Zeichen Unterschied in der URL, und 25 Anweisungen sind weg.

Deshalb nennt jede Spec die Blattseite direkt, und ein Test hält fest, dass die
Verteilerseite leer ist — damit ein späteres „Zusammenfassen" der drei Dateien
dort auffliegt und nicht in der Übersicht eines Vereins.

Zwei der sechs dort verlinkten Hersteller (Austro Engine, Limbach) und
Hoffmann bei den Propellern veröffentlichen auf eigenen Seiten. Das sind eigene
Fragen, nichts, wohin man sich durchhangelt.

### Dieselbe Nummer zweimal — und warum das gemeldet wird

Rotax führt `TM SB-2ST-000` zweimal auf einer Seite: einmal für die Baureihe
275, einmal für die 505, mit unterschiedlichen Daten. Beide sind echt.

Eine Anweisung wird über Quelle und Nummer identifiziert. Die zweite Zeile
landete damit im Update-Zweig und überschriebe die erste. Der Lauf meldete
„1 neu, 1 aktualisiert" — beides wahr, und es verschweigt vollständig, dass eine
Anweisung verschwunden ist. Genau der Verlust, gegen den dieses Modul sonst
überall absichert, nur durch die Vordertür.

Der Import erkennt jetzt eine Nummer, die innerhalb **eines** Abrufs zweimal
vorkommt, behält die erste und **meldet die übrigen namentlich** — in der
Konsole und als bleibende Warnung in der Oberfläche. Es wird nichts
zusammengeführt und keine unterscheidende Nummer erfunden: aus der Seite lässt
sich keine ableiten. Wer die zweite Anweisung braucht, legt sie von Hand an.

## 12. Das Blaue Buch: Komponentenbände lesen — mit einem Systempaket

Die Feststellung, die Motoren- und Propellerbände des LBA seien nicht
maschinenlesbar, war **falsch**. Sie war ein Artefakt des Extraktors, nicht der
Dokumente.

Die Bände richten ihre Spalten durch Leerzeichen-Auffüllung aus. Ein PHP-Parser
liest die Textobjekte in Inhaltsreihenfolge und hängt sie aneinander — aus
`4505/EN  Walter Mikron III  Walter Motorlet` wird
`4505/ENW alter Mikron IIIW alter Motorlet`. Die Spalten sind nicht beschädigt,
sie sind weg.

Mit `pdftotext -layout` bleibt die Geometrie erhalten:

| Band | vorher | jetzt | im Dokument |
|---|---|---|---|
| Segelflugzeuge | 156 | **157** | 157 |
| Motoren | **0** | **157** | 157 |
| Propeller | **0** | **130** | 130 |
| Schleppkupplungen | 9 | **10** | 10 |

Alle vier stimmen exakt mit dem überein, was im Dokument steht — gegengezählt,
nicht geschätzt.

### Zwei Fehler, die dabei sichtbar wurden

**Umbrochene Zellen gingen verloren.** Lange Zellen brechen auf die nächste
Zeile um, eingerückt auf ihre eigene Spalte:

```
53/SP   Habicht E   Deutsche
                    Forschungsanstalt für
                    Segelflug e.V.
```

Nur die Datenzeile zu lesen ergibt „Deutsche". Die ganze Fortsetzungszeile
anzuhängen ist schlimmer: `103/SP` trägt dort eine **zweite Baureihe**, der
Hersteller sammelte also eine Modellnummer ein. Beides waren reale Zustände.
Die Lösung ist der Grund, warum `-layout` ein Systempaket wert ist: es ist die
einzige Extraktion, die noch weiß, *wo* ein Textstück stand. Fortsetzungen
werden über ihre Startspalte der richtigen Zelle zugeordnet — damit wird aus
`103/SP` korrekt „Luftsportgem. Wolfenb.-Salzg.", und kurze Zellen bleiben
unangetastet.

Nebenbei löst das auch die Kupplungen: `Sicherheitskupplung Europa G 72`, `G 73`
und `G 88` sind jetzt drei unterscheidbare Einträge. Genau daran war die
Handeingabe-Entscheidung damals festgemacht worden.

**Sechs Motoren fielen durch das Nummernmuster.** Es verbot einen Buchstaben im
Zahlteil, also verschwanden `4509A/EN`, `4519A/EN`, `4524A/EN`, `4561A/EN`,
`4563A/EN` und `7007A/EN` — 151 von 157 gelesen, ohne eine einzige Meldung. Eine
Zeile, die das Muster verfehlt, ist eben keine Zeile. Deshalb zählen die Tests
gegen eine Zahl aus dem Dokument und nie gegen „über hundert". Im Seglerband
kam dadurch übrigens `205A/SP` dazu, das ebenfalls stillschweigend fehlte.

### Was dadurch neu ist

- **Kennblatt-Suche für Komponentenmuster.** Gab es vorher nicht. Durchsucht
  werden nur die Komponentenbände — wer einen Motor sucht, will keine 157
  Segelflugzeuge in der Liste
- **EASA antwortet auf Komponentensuchen bewusst nichts.** Der Adapter liest die
  Flugzeug-Bibliothek; Flugzeuge, deren Name zufällig passt, wären schlechter
  als ehrliches Schweigen, während das Blaue Buch daneben die Antwort hat
- **`aeronance:requirements`** prüft, ob `pdftotext` da ist. Eine Voraussetzung,
  die man erst im Betrieb bemerkt, bemerkt man am falschen Tag

### Null Zeilen sind ein Fehler, kein Ergebnis

Ein Band, der zu null Zeilen zerfällt, wirft. Jeder Band des LBA hat Einträge —
das macht ihn zum Band. Null heißt deshalb immer „das Format hat sich geändert"
oder „die Textextraktion liefert die Spalten nicht mehr getrennt", nie „nichts
registriert".

Dieselbe Regel greift eine Ebene höher: die Registry hat Fehler einzelner
Behörden bisher **verschluckt**, mit der Begründung, eine Suche sei kein Ort zum
Scheitern. Die erste Hälfte stimmt weiterhin — eine ausgefallene Behörde darf
die anderen nicht verdecken. Die zweite war falsch und wurde in dem Moment
gefährlich, in dem das Blaue Buch ein Systempaket braucht: auf einem Rechner
ohne `poppler-utils` hätte **jede** Suche für immer „kein Treffer" gemeldet, und
genau das liest ein Mensch als „dieses Muster steht nicht drin". Fehler werden
jetzt gesammelt und angezeigt, sobald die Trefferliste leer ist.

### Was bewusst offenbleibt

`Rhein-Ruhr-Sicherheits- kupplung (RRK 79)` behält ihren Trennstrich mit
Leerzeichen. Ob ein Bindestrich am Zeilenende ein Trennstrich ist oder zum Wort
gehört, lässt sich an einem Beispiel nicht entscheiden — und eine Regel, die
funktionierende Bände beschädigt, ist die falsche Regel. Eine kosmetisch
schiefe Zeile ist besser als eine stille Verfälschung.

## 13. Fünfter Modus: eine Liste, die nicht stillhält (DG Aviation)

Alle bisherigen Hersteller geben ihre Liste am Stück heraus. DG liefert 1894
Dokumente über einen WordPress-Feed mit zehn Einträgen pro Seite —
`posts_per_page` wird ignoriert. Das allein wäre lästig. Das Problem ist, dass
sich die Seiten **zwischen den Abrufen umordnen**: über 15 Seiten gemessen kamen
12 von 165 Einträgen doppelt, und jede Dublette auf einer Seite ist ein Dokument,
das von einer anderen gerutscht ist, bevor es gelesen wurde.

Die Seiten einmal durchzugehen verliert also Dokumente — und sieht dabei aus wie
ein vollständiger Lauf.

**Zwei Vorkehrungen, weil eine nicht reicht:**

1. **Durchlaufen, bis sich nichts mehr ändert.** Die ganze Liste wird wiederholt
   abgelaufen, bis ein kompletter Durchgang nichts Neues mehr findet. Das
   konvergiert meist im zweiten Durchgang. Eine Liste, die nach vier Durchläufen
   immer noch Neues liefert, wird **nicht** mit einem fünften geglättet, sondern
   abgelehnt — an dem Punkt kann niemand mehr sagen, was „die Liste" ist
2. **Gegen DGs eigenes Verzeichnis zählen.** Die Sitemap listet alle 1894
   Dokumente. Sie trägt keine Titel, taugt also nicht zum Lesen — aber sie
   beantwortet die eine Frage, die ein geblätterter Abruf sich selbst nicht
   beantworten kann: *waren das alle?* Fehlende werden namentlich gemeldet.
   Beim Abruf für ein einzelnes Muster entfällt das: das Verzeichnis kann nicht
   sagen, welche Dokumente zu einem Typ gehören, und das zu schätzen wäre genau
   die Sorte Annahme, die hier nichts zu suchen hat

### Drei Fallen, die alle schon einmal zugeschnappt sind

**Ein 404 beendet die Liste — außer auf Seite 1.** WordPress liefert hinter der
letzten Seite keinen leeren Feed, sondern 404. Auf Seite 1 heißt derselbe Code
etwas völlig anderes: falsche URL oder unbekannte Kategorie. Das als „Liste zu
Ende" zu lesen, meldete einen leeren Katalog für einen Hersteller mit
hunderten Anweisungen. Dafür gibt es `HttpNotFound` als eigenen Typ.

**Ein falscher Kategorie-Slug scheitert nicht, er antwortet leer.** Und leer
liest sich als „DG hat dazu nichts veröffentlicht". Das Muster wird deshalb
vorher gegen DGs eigene Taxonomie geprüft; ein unbekanntes wird namentlich
abgelehnt, mit Vorschlägen.

**Zwei Schreibweisen, wieder.** DG schreibt 196 Slugs als `tm-301-01` und 421
als `tm301-19`. Ein Muster mit erzwungenem Trennzeichen hätte die größere Hälfte
stumm verloren — derselbe Fehler wie bei Lindner, wo neunzehn echte Anweisungen
verschwanden.

### Was DG nicht hergibt, und was daraus folgt

- **Kein Datum.** `pubDate`, `lastmod` und „Erstellt" tragen alle den Moment des
  Massenimports, sekundengenau fortlaufend (tm-301-01 bis -04: 09:51:21,
  09:51:54, 09:52:19, 09:52:54). Es wird kein Datum abgebildet
- **Keine Verbindlichkeit.** Der Feed hat keine Dringlichkeitsspalte. Wichtig:
  eine *fehlende Spalte* ist etwas anderes als eine *leere Spalte* — letztere
  liest der Treiber als „keine Ausnahme", was bei Schleicher richtig ist. Hier
  würde das Schweigen zur Behauptung, und alle 1894 Dokumente kämen als
  verbindlich herein. Stattdessen greift die Voreinstellung aus der Art: eine TM
  ist optional, bis eine Behörde sie übernimmt. Vorgabe: „es gibt teilweise
  optionale TM's die sollen auch rein" — sie kommen rein, und zwar unbeurteilt
  wie alles andere, was die Freigabe blockiert, bis jemand Qualifiziertes
  entschieden hat
- **Nur etwa ein Drittel sind Anweisungen.** Der Rest sind Handbücher,
  Flughandbücher, Arbeitsanweisungen. Ihre Titel enthalten TM-Nummern:
  `MM DG-1000T Rev25 TM1000-52rev2 affected pages` ist ein Handbuchnachtrag.
  Das Nummernmuster ist deshalb **am Titelanfang verankert**
- **Titel sind oft nur die Nummer.** DGs Feed führt `<title>TM 301-01</title>`
  und leere `description`. Mehr gibt die Quelle nicht her; ein Titel pro
  Dokument nachzuladen kostete 1894 zusätzliche Anfragen

**Offen und bewusst nicht entschieden:** DG veröffentlicht viele Anweisungen
zweimal — als deutsche `TM` und als englische `TN`. 146 von 158 TN-Kennungen
haben ein TM-Gegenstück. Sie zusammenzuführen hieße festzulegen, dass `TM 301-05`
und `TN 301-05` dasselbe Dokument sind. Das ist plausibel und steht nirgends:
DGs eigene Kategorien gruppieren sie nicht, der Feed nennt keine. Also kommen
beide herein, und ein Verein sieht beide Fassungen. Wenn das stört, ist das eine
Entscheidung — keine Ableitung.

### Nicht zuständig ist kein Fehler

Der Wochenlauf fragt jede Quelle nach jedem Muster der Flotte. Schleicher wird
also jede Woche nach einer DG-300 gefragt und DG nach einer ASK 21, und beide
antworten korrekt, dass sie so etwas nicht bauen. Das erscheint als
Informationszeile („nicht zuständig"), nicht als Warnung — ein Wochenlauf, der
ein Dutzend Nicht-Ereignisse meldet, ist einer, den niemand mehr liest. Die
Unterscheidung stützt sich darauf, dass der Hersteller **selbst** eine Musterliste
führt; wo es die nicht gibt, bleibt der Zweifel und damit die Warnung.

## 14. Diamond Aircraft: eine API statt einer Übersicht

Diamond veröffentlicht **kein** Übersichtsdokument. Vorgabe: „Die bieten leider
keine gescheite übersicht an." Das stimmt — als Dokument. Ihr Portal liefert
denselben Inhalt aber als **strukturierte Daten**, und damit besser als jedes
PDF es könnte.

### Der Zugang

Das Portal ist eine Salesforce-Community. Die HTML-Seite ist eine 114-KB-Hülle,
für jede Route identisch; alle Daten kommen über einen Aura-RPC nach:

```
POST /s/sfsites/aura?r=<n>&aura.ApexAction.execute=1
  classname: daiFilesController
  getPublicLibraries(member)          -> 23 Bibliotheken, 20 davon je ein Muster
  getContentByFolder(parentfolderId)  -> Unterordner
  getFilesByFolder(parentId)          -> Dokumente
```

**Kein Login.** Der `member`-Parameter sieht aus wie eine Benutzerkennung und
ist die **Gast-Kennung der Seite** — eine Konstante. Das war der einzige
fehlende Baustein, und geklärt hat ihn nicht die Technik, sondern die
Hinweis, dass er gar keinen Zugang besitzt: Was aussah wie seine Sitzung, war
das, was jeder Besucher bekommt.

Ohne diesen Parameter antwortet die API `SUCCESS` mit einer **leeren Liste** —
nicht mit einem Fehler. Eine Anbindung, die das nicht prüft, meldet fröhlich
„Diamond hat nichts veröffentlicht".

### Was die Daten hergeben

```
Doc_No__c:   "DA40-24-03"          Nummer
Date__c:     2019-05-14            Ausgabedatum
Revision__c: 2                     Revision
Description: "..."                 Gegenstand
ContentDocumentId: 069Tb...        das Dokument selbst
```

**Über alle 2508 Dokumente gemessen: 100 % tragen ein Datum.** Deutlich besser
als DG oder Lindner, wo sich gar kein Ausgabedatum abbilden lässt.

### Die Falle, die eine Stichprobe nicht gezeigt hätte

Die alten Dimona-Bulletins heißen `"SB No. 3/2 (M)"` — das `(M)` steht für
*mandatory*. Naheliegend, daraus die Verbindlichkeit zu lesen.

**Über alle Dokumente gerechnet tragen es 1 %.** Die gesamte DA-Reihe nummeriert
`DA20-10-01` und kennt das `(M)` nicht. Wer die Regel aus einer Stichprobe
ableitet, importiert 99 % der Dokumente mit einer erfundenen Verbindlichkeit.

Also: **keine Verbindlichkeit aus der Quelle.** Es bleibt bei der Voreinstellung
aus der Art, und ein Mensch beurteilt jede Zeile. Vorgabe: „Die klassifizierung
muss der Nutzer erledigen."

### Umfang

18 der 20 Musterbibliotheken haben gleichlautend die Ordner „Service Bulletins"
und „Service Informations"; die zwei Ausnahmen sind Sonderausstattungs-
Bibliotheken ohne Bulletins.

**Nur die Service Bulletins werden importiert.** Service Informations sind
dieselbe Dokumentenklasse wie Lindners *Technische Informationen*, und dort gilt
schon: eine TI ist keine TM, sie bleibt draußen.

### Der Nebeneffekt: die Echo-Klasse

Motorsegler und Motorflugzeuge liegen bei Diamond in **derselben** Struktur:

```
H36 Dimona · HK36 Super Dimona · HK36 TS/TC · HK36 TTC-ECO · HK36 TTS/TTC
DV20 Katana · DA20-A1 · DA20-C1 · DA20i · DA40 (5 Varianten) · DA42 (3) · DA50C · DA62 (2)
```

Wer die Dimona anbindet, hat die DA40 mitgebaut. Das ist der Grund, warum
Diamond trotz fehlender Übersicht dazugehört.

### Gemessen, nicht geschätzt

Gegen das echte Portal abgerufen:

| Muster | Zeilen | ohne Datum |
|---|---:|---:|
| HK36 Super Dimona (Motorsegler) | 54 | 0 |
| DA40-180 (Echo) | 81 | 0 |
| DA42 TDI (Echo, zweimotorig) | 119 | 0 |

Ein Abruf fragt **nur das verlangte Muster** ab — vier Anfragen statt der
Bibliotheks-mal-Ordner-mal-Dateien-Lawine über alle zwanzig Muster.

### Die Verbindlichkeit steht doch in der Quelle — nur woanders

Das `(M)` war die falsche Stelle. Der Hersteller schreibt die Klassifizierung in
die **Nummer**, in zwei Schreibweisen nebeneinander. Über alle 944 nummerierten
Service Bulletins gezählt:

| Marker | Anzahl | Anteil |
|---|---:|---:|
| `MSB…` — Mandatory Service Bulletin | 295 | 31 % |
| `OSB…` — Optional Service Bulletin | 333 | 35 % |
| `RSB…` — Recommended | 85 | 9 % |
| Wort angehängt (`SB20-9/3 Mandatory`) | 25 | 3 % |
| gar kein Marker (`DA20-10-01`, DA20-Reihe) | 206 | 22 % |

**78 % statt 1 %** — das ist der Unterschied zwischen einer Regel und einer
Stichprobe, und der Grund, warum der Marker gelesen wird und das `(M)` nicht.

Wichtig für die Risikorichtung: Ein leeres Verbindlichkeitsfeld bedeutet im
Modell **verbindlich**, nicht optional (`Directive::$attributes`). Der Vorschlag
kann also nur *entschärfen*, und genau das ist die Richtung, die wehtun kann —
eine bindende Anweisung als optional geführt ließe sich abwählen. Deshalb:

- gesucht wird **verankert** (`/^([MOR]SB)|\s+(Mandatory|…)$/`), nicht als
  Teilzeichenkette. `MSB40-OSB-7` bliebe sonst hängen.
- entschärft wird **nur** bei `OSB`/`Optional`.
- `RSB`/`Recommended` (85 Stück) bleibt **verbindlich**. Ob ein *empfohlenes*
  Bulletin abgewählt werden darf, ist eine fachliche Frage, keine Ableitung —
  offen.
- die 206 ohne Marker bleiben verbindlich und damit Handarbeit.

Die Zuordnung selbst ist **kein neuer Mechanismus**: es ist dieselbe
`bindingnessFor()`-Tabelle mit `optional_phrases`, die auch Schleicher und
Schempp-Hirth benutzen, nur mit dem extrahierten Marker als Eingabe.

Nebenwirkung, die dazugehört: `SB20-9/3 Mandatory` wird als Nummer auf
`SB20-9/3` gekürzt. Das Wort gehört nicht in die Nummer — und es macht sie
**instabil**: Stuft der Hersteller um, änderte sich die Zeichenkette, und der
Import legte eine zweite Anweisung an, statt die erste zu aktualisieren. Die
Beurteilungen fielen auseinander.

### Ein Dokument von 945, und warum es den Import anhält

Beim Messen über alle Musterbibliotheken: von **945 Service Bulletins hat genau
eines** keine Nummer. Der Rohsatz, vollständig:

```
Id                068Tb000000GweRIAS
Title             SB20-054-1-M          <- der Dateiname
Date__c           2023-11-23
ContentDocumentId 069Tb000000GuRLIA0
                                        <- Doc_No__c und Description fehlen ganz
```

Diamond hat ein Dokument unvollständig eingestellt. Der Treiber hat solche
Dateien zunächst still übersprungen — das wäre ein verlorenes Service Bulletin
gewesen, und niemand hätte je davon erfahren.

Naheliegend wäre, den Dateinamen als Nummer zu nehmen. Der ganze Katalog sagt,
warum das falsch wäre: Die Datei heißt `SB20-054-1-M`, die Nummern derselben
Reihe lauten `SB20-54/1 Mandatory`. Der Dateiname ist **planmäßig** eine andere
Zeichenkette, nicht zufällig — er würde eine Nummer importieren, die der
Hersteller nie vergeben hat. „wir raten nicht."

Die anderen 55 zu importieren und den Fall nebenbei zu erwähnen, ist schlechter
als es klingt: Der Verein hält dann eine Liste, die **vollständig aussieht**.
Deshalb hält der Abruf an und nennt das Dokument — dieselbe Antwort, die der
Übersichtsmodus auf eine unlesbare Zeile gibt.

**Die Reichweite ist ein Muster, nicht der Hersteller.** Der wöchentliche Lauf
fragt je Flottenmuster einzeln ab und fängt Fehler pro Abruf ab:

```
diamond / DA40-180      81 neu
diamond / DV20 Katana   übersprungen — das Portal führt 1 Dokument(e) ohne
                        Nummer im Feld "Doc_No__c": DV20 Katana: "SB20-054-1-M"
```

Ein Verein ohne DV20 Katana merkt nie etwas davon. Ein Verein mit einer bekommt
für dieses Muster **nichts** und die Nummer des fehlenden Dokuments im Klartext
— bis er es von Hand anlegt oder Diamond das Feld füllt.

## 15. Drei Hersteller dazu — und was sie am Leser gefunden haben

| Quelle | Muster | Zeilen | Stand |
|---|---|---|---|
| Streifeneder (Glasflügel) | 11 | 340 | vollständig, 0 verworfen |
| Scheibe | 29 | 15 Blätter geprüft | vollständig, s. u. |
| SZD / Allstar PZL | 6 + allgemein | 143 | vollständig, 0 verworfen |

Alle drei Zählungen sind **gegengezählt**, nicht geschätzt: Aus jedem Blatt wurde
alles aus der Nummernspalte gezogen und mit dem verglichen, was der Leser daraus
macht. Bei Streifeneder ist die Differenz auf jedem Blatt exakt der Seitenkopf.

### Drei Defekte im gemeinsamen Leser — behoben

Streifeneder hat drei Fehler sichtbar gemacht, zwei davon **still**:

1. **Nummer senkrecht mittig.** Die erste Zeile einer Anweisung steht über ihrer
   Nummer. Vorwärts gelesen landete `LTA 2012-105` bei `201-39` — eine
   Lufttüchtigkeitsanweisung an der falschen TM. Behoben über
   `numbers_centred_in_row`; mehrzeilige Zellen wandern dabei als Ganzes.
2. **„Seitenkopf" hieß „Text, der zweimal vorkommt".** Ein Seitenkopf steht an
   *derselben Stelle jeder Seite*. Drei von 35 Zeilen der Club Libelle tragen
   denselben Gegenstand — der Titel von 205-1 galt als Möbelstück und verschwand.
3. **Spaltenabgleich war eine Zeichenzahl.** Eine Überschrift sitzt, wo sie
   gesetzt wurde; auf vier Blättern 3–5 Zeichen rechts der Nummern darunter. Statt
   die Toleranz hochzudrehen (und damit die Prüfung zu entwerten) gilt jetzt die
   strukturelle Frage: liegt **eine andere gemessene Spalte dazwischen**?

Schleicher unverändert bei 53 und 17 Zeilen.

### Zwei Defekte derselben Klasse — behoben

Beide erzeugten **falsche Daten ohne Meldung** — der Fehlertyp, gegen den dieses
Modul gebaut ist: Ein verworfener Eintrag wird gemeldet, ein fehlgelesener sieht
plausibel aus.

**Scheibe zentriert den Zellinhalt waagerecht.** Auf dem Bergfalke III steht die
Nummer je nach Zeile auf Spalte 15 bis 25; der Strich `--` der Zeile *LTA 82-216*
auf 25 — näher an der Gegenstandsspalte (33) als an der eigenen (15). Die
Zuordnung gab ihn dem Gegenstand, wo der Gegenstand schon stand. Die Zeile begann
nie, ihre LTA klebte an der Zeile darüber als `--82-216`: 30 Zeilen aus einem
Blatt von 31, mit leerem Vollständigkeitsbericht.

> **Zwei Zellen können sich keine Spalte teilen.** Liegt zwischen ihnen eine
> *gemessene* Spaltengrenze, ist die linke verrutscht und gehört in ihre eigene
> Region. Die Grenze ist genau das, was sie zu zwei Zellen macht statt zu einem
> Eintrag mit weiter Lücke (`Alle   Werknummern` bleibt zusammen).

Bergfalke III: **31 von 31.**

**SZD lässt Text in die Nachbarzeile laufen.** Aus übergelaufenem
Betroffenheitstext entstehen falsche Serienbereiche — und ein falscher
Serienbereich lässt eine Anweisung stillschweigend entfallen. Über die sechs
Blätter:

| | vorher | nachher |
|---|---:|---:|
| Serienbereiche abgeschnitten | 5 | **2** |
| Titel beginnt mitten im Satz | 66 | **35** |
| verworfene Einträge | 0 | 0 |

Damit der Mittig-Modus hier passte, mussten drei Dinge gelten:

- **Eine Nummer, die auf `/` endet, ist nicht zu Ende.** SZD bricht 24 von 143
  dort um (`BE-001/` über `SZD-54-2/2018`), und die zweite Hälfte liest sich wie
  eine neue Anweisung. Mit Leerzeichen verbunden entstanden außerdem Nummern, die
  nirgends im Dokument stehen.
- **Eine Zelle in der Nummernspalte setzt die Nummer *über* ihr fort**, egal was
  der Abstand sagt. Perkoz setzt „Revision 1" eine Zeile neben die Nummer der
  *nächsten* Anweisung und drei neben die eigene — nach Abstand landete sie an
  der falschen Zeile, dort über deren Nummernzeile, und fiel weg. Übrig blieben
  zwei Anweisungen mit derselben Nummer.
- **Verwurf-Notizen folgen ihren Zeilen**, sonst wird eine Zeile gegen eine
  Anweisung gemeldet, die sie nie hatte.

Dazu geht die erste Zeile einer Seite nicht mehr verloren: Ihr Text kann über
ihrer Nummer stehen, während noch keine Zeile offen ist.

**Was bei SZD bleibt:** 35 Titel beginnen weiter mitten im Satz, wo zwei
Anweisungen dicht beieinander stehen und unterschiedlich hoch sind. Nummern,
Zählungen und Serienbereiche sind davon nicht betroffen.

### Offene fachliche Frage

SZDs allgemeines Blatt ist als `general: true` eingetragen — im System „ein
Angebot, keine Pflicht". Bei DGs genehmigten Verfahren stimmt das. **Elf der
dreizehn SZD-Zeilen stehen aber unter „Mandatory"**, darunter BE-007/94 zur
Lebensdauer von Steuerseilen. Als Angebot geführt fallen sie aus der Liste der
offenen Punkte. Fachlich zu bestätigen.

## 16. Was noch fehlt

*Stand 2026-08-02 nachgeführt. „Weitere Hersteller" sind inzwischen 48 Quellen
(§17–§23); der PDF-Modus ist gebaut und trägt heute vierzehn Übersichtsblätter.
Was von diesem Abschnitt übrig ist, steht unten — der aktuelle Stand in §23.*
- **DG: deutsche und englische Fassung** — beide kommen herein (siehe §13). Ob
  sie zusammengeführt werden sollen, ist eine Entscheidung, keine Ableitung
- **DGs Vollabruf dauert** — 1894 Dokumente sind rund 190 Seiten, mit zweitem
  Durchlauf und Pause etwa zehn Minuten. Für einen Verein irrelevant, weil nur
  die geflogenen Muster abgerufen werden; für einen Vollabgleich merkbar

## 17. Motorsegler, Motorflugzeuge und die Antriebsseite

Bis hierher las das Modul Segelflugzeughersteller. Mit Motorseglern und
Motorflugzeugen kommt eine **zweite Quellenklasse** dazu, die es vorher praktisch
nicht gab: der Antrieb. Ein Motor hat eigene TMs, eigene Laufzeiten und eigene
ADs, unabhängig vom Flugzeugmuster, an dem er hängt. Die drei
Schleicher-Dateien (`schleicher-rotax`, `-solo`, `-propeller`) lasen fremde
Triebwerks-TMs nur deshalb, weil Schleicher sie weiterveröffentlicht; jetzt
stehen die Musterbetreuer selbst als Quelle da.

| Quelle | Modus | Zeilen | ohne Datum | ohne Dokument |
|---|---|---:|---:|---:|
| C.E.A.P.R. (Robin) | `json` | 286 | 286 (s. u.) | 0 |
| BRP-Rotax | `table` | 490 | 0 | 0 |
| Zlin Aircraft | `table` | 577 | 0 | 0 |
| Stemme | `table` | 379 | 379 | 0 |
| SOLO | `table` | 61 | 0 | 0 |
| Limbach Flugmotoren | `table` | 51 | 0 | 0 |
| MT-Propeller | `table` | 42 | 0 | 0 |
| Austro Engine | `aura` | 43 | 0 | 0 |

Alle acht sind gegen die echte Quelle gemessen, nicht geschätzt.

### C.E.A.P.R.: der Login, den es nicht braucht

Robin Aircraft SAS wurde am 16.11.2023 liquidiert; die Musterzulassungen liegen
bei der Muttergesellschaft C.E.A.P.R. Deren Einstiegsseite sagt „réservée aux
abonnés" und sieht aus wie eine Bezahlschranke. **Die Liste dahinter ist offen:**
286 Einträge, 0 ohne Dokument, neun über die ganze Liste verteilte Stichproben
liefern anonym echte PDFs. Hinterlegte Zugangsdaten wurden nicht verwendet — was
nicht gebraucht wird, gehört nicht in eine Spec.

**Das Datum ist gefüllt und trotzdem unbrauchbar.** In derselben Schreibweise
stehen beide Konventionen nebeneinander; über die 204 Zeilen der Form
„Issue N dtd T/M/J":

```
'Issue 0 dtd 24/02/2026'  →   1 Zeile mit Tag > 12    (Tag zuerst)
'Issue 1 dtd 10/27/2003'  → 117 Zeilen mit 2. Zahl > 12 (Monat zuerst)
```

Es gibt kein Merkmal, an dem sich die Reihenfolge entscheiden ließe, und bei der
Mehrheit sind beide Zahlen ≤ 12 — da fiele die Verwechslung nicht einmal auf.
Das ist **schlimmer als ein fehlendes Datum**, weil es vertrauenswürdig aussieht.
Der Unix-Zeitstempel im JSON ist der Hochladezeitpunkt (dieselbe Zahl steht im
Dateinamen) und damit nach der Modulregel ebenfalls kein Ausgabedatum.

### Rotax: die Verbindlichkeit steht nicht in der Nummer

Bei Diamond und Continental sagt das Präfix, wie bindend ein Dokument ist. Bei
Rotax nicht: `ASB-2026-001` ist **Mandatory**, `SB-2026-002R00` („TBO 1200 →
2000 h") ist **Optional**. Ein `number_marker` im Stil von `diamond.yaml` würde
hier verbindliche Anweisungen still entschärfen.

Rotax druckt sie stattdessen als angekreuzten Block auf die Titelseite jedes
ASB/SB, vierstufig: `MANDATORY · OBLIGATORY · RECOMMENDED · OPTIONAL`.
**Vorgabe vom 31.07.2026: „obligatory ist verbindlich."** Die vierte Stufe wird also
auf die bestehende verbindliche abgebildet — Rotax' eigene Definition („kein
unsicherer Zustand, aber BRP-Rotax verlangt die Umsetzung") liegt bei
verbindlich, nicht bei empfohlen; eine verlangte Maßnahme darf nicht abwählbar
sein. Automatisch lesen ließe sich das nur über die PDF-Titelseite — ein eigener
Verarbeitungsschritt, kein Feld in der Spec.

Zwei stille Fallen, beide in der Spec verankert: neben der Ergebnistabelle steht
eine **zweite** mit identischen Spalten und fremden Dokumenten (deshalb
`topTable` im Muster), und `language=German` liefert HTTP 200 mit null Treffern
statt eines Fehlers.

### Austro Engine: eine Ebene tiefer, und deshalb erst nichts

Austro veröffentlicht nicht selbst, sondern in **Diamonds Portal**. Diamonds
Baum ist `TechPubs DA40` → `Service Bulletins` → Dateien, die Bibliothek benennt
also das Muster. Austro legt alles in EINE Bibliothek und trennt darunter nach
Baureihe:

```
Engine Documentation
  ├── AE300 - AE330 ── Service Bulletins - Mandatory (24) / Recommended, Optional (11)
  └── AE50R ───────── Service Bulletins - Mandatory (6)  / Recommended, Optional (2)
```

Mit Diamonds Annahme gelesen kam **gar nichts** heraus: Die Baureihenordner
passen auf kein Dokumentenmuster, also wurde kein Ordner gewollt und die Quelle
meldete eine leere Liste — ununterscheidbar von „Austro hat nichts
veröffentlicht". `aura.model_folder_pattern` geht diese Ebene optional mit; das
Muster kommt dann aus dem Ordner statt aus der Bibliothek. Zweiter Unterschied:
Der Titel steht in `Title`, `Description` ist durchgängig leer — `diamond.yaml`
übernommen hätte jede Zeile ohne Titel gelassen.

### Zlin: beide Sprachfassungen, weil keine die andere enthält

Jede TM liegt tschechisch und englisch vor. **85 Nummern gibt es nur
tschechisch, 54 nur englisch** — ein Sprachfilter würde also in beide Richtungen
still Anweisungen verlieren. Beide kommen herein, wie bei DG (§13); das
Zusammenführen bleibt eine Entscheidung und keine Ableitung.

Die Typspalte kennt über alle 577 Zeilen genau zwei Werte, `Závazné` (495) und
`Závazné s vlivem na způsobilost` (82). Beide sind verbindlich; eine
„optional"-Kategorie existiert bei Zlin nicht.

### SOLO: Herstellerangaben und Behördenanweisungen in einer Tabelle

SOLO listet seine eigenen TMs und die EASA-Anweisungen zu denselben Motoren in
**derselben** Tabelle, unterschieden allein an der Schreibweise der Nummer:
49 Herstellerdokumente (`TM 4603-1`, `AI 4603-1`) neben 12 Behördenanweisungen
(`AD 2007-0001R1-E`, `EASA AD 214-0269`). Ohne `authority_number_pattern` lägen
zwölf Lufttüchtigkeitsanweisungen als Technische Mitteilungen im Bestand — und
der Unterschied zwischen den beiden ist genau der zwischen „muss" und „kann".
Die Verbindlichkeit ändert sich dadurch nicht; korrigiert wird das Etikett.

Diese Quelle **ergänzt** `schleicher-solo.yaml` und ersetzt es nicht: Schleicher
veröffentlicht nur die für AS-Muster relevanten Solo-TMs weiter.

### Was der Treiber dabei gelernt hat

Neun Ergänzungen, alle generisch — keine herstellerspezifische Sonderlogik:

| Ergänzung | Aufgedeckt durch | Was ohne sie passiert wäre |
|---|---|---|
| Kettenreparatur auch ohne Login | C.E.A.P.R. | Import stirbt an cURL 60, lesbar als „Robin ist nicht erreichbar" |
| `FormFetcher` (POST-Endpunkte) | C.E.A.P.R. | Die Frage lässt sich gar nicht stellen; jedes GET ist 404 |
| Abruf ohne Muster-Id | C.E.A.P.R. | Quelle wird abgelehnt, obwohl sie alles auf einmal liefert |
| `document_url` | C.E.A.P.R. | Toter Link in jedem Datensatz |
| `number_strip`, `bindingness.source` | C.E.A.P.R. | Umstufung legt eine zweite Anweisung an statt zu aktualisieren |
| `number_prefix: false` | Rotax | Nummern wie „SB ASB-2026-001", die es beim Hersteller nicht gibt |
| `aura.model_folder_pattern` | Austro | Leere Liste statt 43 Anweisungen |
| Datum: Leerzeichen, Monatsnamen | Zlin, Limbach, MT | 670 Zeilen ohne Ausgabedatum |
| Relative Dokumentlinks auflösen | Zlin | 577 tote Links, still — das Feld ist gefüllt und sieht richtig aus |
| `page.cell_strip` | Stemme | Nummer als „Date/File SB_914_042" |
| `page.ignore` | MT-Propeller | Die Bulletin-LISTE als Anweisung, die niemand durchführen kann |
| `authority_number_pattern` | SOLO | Zwölf ADs als TM etikettiert |

### Was ausdrücklich nicht gebaut ist, und warum

- **Grob G 109 / G 115 — zwei von drei Hürden genommen, die dritte nicht.**

  Erstens: Grob bricht seine Kopfzeile über **drei Zeilen** („SB No. /" ·
  „Issue" · „Title"). Der Leser verlangte Nummer und Titel auf EINER Zeile und
  verweigerte deshalb ein Blatt, das er sonst findet. **Behoben** — er ankert
  jetzt auf der Nummer und sucht den Gegenstand im übrigen Kopf; beide müssen
  weiterhin vorhanden sein, nur nicht nebeneinander.

  Zweitens: Die Verbindlichkeit steht als **Ankreuzmatrix** über vier eng
  stehende Spalten (Alert | Mandatory | Recommended | Optional). Ein X ist ein
  Zeichen ohne Wort; eine Zuordnung eine Spalte daneben macht aus „empfohlen"
  ein „optional", also aus einer Pflicht eine Wahl. **Umgangen** — dieselbe
  Auskunft steht bei den neueren Bulletins ausgeschrieben in der Bezeichnung
  (`MSB817-59`, `OSB817-61`, `RSB817-75`). Das ist eine Messung; die Kreuze zu
  lesen wäre geraten. `bindingness.source: number` gilt dafür jetzt auch im
  Übersichtsmodus.

  Drittens, und daran scheitert es: **Die Überschriften stehen zentriert über
  linksbündigen Daten, und der Titelblock ist mehrzeilig** — deutscher Text
  über der Nummer, englischer darunter. Der Titel beginnt damit weit links
  seiner eigenen Überschrift und läuft über die Nachbarspalten. Gemessen:
  70 Zeilen werden erkannt, aber die Titel sind Fragmente der falschen Zeile
  („810 kg auf 825 kg zur Erhöhung der" bei 817-1), kein einziges Ausgabedatum
  kommt an, und mit `numbers_centred_in_row` gehen zwei Einträge ganz verloren.

  **Deshalb ist keine Grob-Spec ausgeliefert.** 70 Zeilen mit falschen Titeln
  und ohne Datum in einer verbindlichen Liste sind schlechter als keine Zeile —
  das ist genau der Fehlertyp, gegen den dieses Modul gebaut ist. Was fehlt, ist
  ein Übersichtsleser, der Spaltengrenzen aus den DATEN gewinnt statt aus den
  Überschriftenpositionen. Die HTML-Liste derselben Seite wäre buildbar, liefert
  aber weder Titel noch Datum.
- ~~Continental Aerospace~~ — **gebaut**, siehe unten.
- ~~Federal Register (US-ADs)~~ — **gebaut**, siehe unten.
- **Textron (Cessna/Beechcraft), Cirrus, Reims/ASI, Aero AT, Pipistrel** —
  vollständig hinter Login, auch der Sicherheitsbereich. Textron antwortet dabei
  mit HTTP 200 (die Login-Seite) statt 401; eine Spec dafür bräuchte zwingend
  ein Erfolgsmuster wie bei Schempp-Hirth.
- **Daher/Socata (Rallye), Ruschmeyer, Wassmer, Pützer, Gomolzig, Hirth, Helix,
  Junkers Profly** — kein TC mehr oder nichts Abrufbares veröffentlicht.
- **Sportavia-Pützer (Fournier RF 3 / RF 4 D / RF 5 / RF 5 B, SFS 31).** Der
  TC-Halter existiert (EASA.A.348, Bonn) und ist erreichbar, veröffentlicht aber
  nur die TCDS selbst. Kein Importfall, sondern eine Anfrage.


### Nachtrag: Continental und die FAA

**Continental Aerospace** (`continental`, `json`): **498 Anweisungen über 51
Seiten**, alle mit ISO-Datum und Dokument. Die sichtbare Tabelle der Seite trägt
im HTML nur einen `<thead>` — als Tabelle gelesen ist sie leer, und leer sieht
aus wie ein Hersteller ohne Veröffentlichungen. Gefüllt wird sie per AJAX, und
die Antwort ist JSON, dessen Zeilen eine Tabelle sind. `endpoint.rows_html`
benennt das Feld; alles danach ist der gewöhnliche Tabellenleser.

Zwei Dinge wurden dabei nur durch Messen sichtbar:

- Dieselbe Route beantwortet auch ein GET, **ignoriert dabei aber den
  Seitenparameter** — jede Seite kommt als Seite eins zurück. Mit GET gelaufen
  hätte der Import nach zehn von 498 Bulletins aufgehört und dabei vollständig
  ausgesehen.
- **`max_pages` ist jetzt ein Notnagel und kein Abbruchkriterium.** Mit einer
  Grenze von 40 kamen 400 Einträge zurück, ohne ein Wort darüber, dass 98
  fehlen. Wird die Grenze erreicht, während noch neue Einträge kommen, meldet
  der Treiber das und übernimmt nichts. Eine gekürzte Liste verbindlicher
  Anweisungen ist schlechter als keine, weil niemand sucht, was ihm nicht als
  fehlend gemeldet wurde.

**FAA Airworthiness Directives** (`faa-ad`, `json`) ist die **erste behördliche
Quelle** dieses Moduls. Bisher kamen ADs nur zweiter Hand herein, dort wo ein
Hersteller in seiner eigenen Spalte eine zitiert.

Nicht über `drs.faa.gov`: Das Dynamic Regulatory System ist eine
Angular-Anwendung, jede Route liefert HTTP 200 mit derselben leeren Hülle. Ein
Treiber darauf meldete null Zeilen — „die FAA hat nichts erlassen". Das
Federal Register veröffentlicht dieselben Regeln als JSON, ohne Schlüssel, mit
Datum und PDF. Gemessen: PA-18 **48**, Citabria **8**, beide vollständig.

Die Zuordnung Muster → Suchbegriff steht als `endpoint.terms` ausgeschrieben in
der Spec, nicht abgeleitet: Ein geratener Begriff schlägt nicht fehl, er liefert
eine leere Liste. Zwei Punkte, die dabei zählen:

- **Der Begriff steht in Anführungszeichen.** Ohne sie sucht das Federal Register
  die Wörter einzeln im Volltext: 178 Treffer für die PA-18 statt 48.
- **Feiner als je Hersteller geht es nicht.** Die Titel nennen den Hersteller,
  nicht das Muster, also bekommt eine PA-18 auch die ADs der PA-28. Das ist die
  richtige Richtung: Das Werkzeug neigt zum Ja (§4), „nicht zutreffend" ist eine
  Beurteilung, die ein Mensch einmal trifft. Umgekehrt eine zutreffende AD
  wegzufiltern, weil der Begriff zu eng war, wäre still und gefährlich.

Nur `RULE`, nicht `PRORULE`: Ein Entwurf ist keine Anweisung, und als offener
Punkt geführt blockierte er eine Freigabe, obwohl noch nichts gilt.


### Nachtrag: drei gewöhnliche Tabellen, und wie eine gewöhnliche Tabelle lügt

| Quelle | Modus | Zeilen | ohne Datum | ohne Dokument |
|---|---|---:|---:|---:|
| Hartzell Propeller | `table` | 73 | 1 | 0 |
| Aviat Husky | `table` | 37 | 37 | 0 |
| Aeroclubul României (IS-29 D2) | `table` | 9 | 0 | 0 |

Keine dieser drei brauchte eine neue Treiberfähigkeit. Gebraucht haben sie
dieselbe Sorgfalt wie der Rest — und jede hat auf ihre Weise etwas in der
Tabelle stehen, das keine Anweisung ist:

- **Hartzell** führt den **Index** der Bulletins als erste Zeile, mit Datum und
  Dokument wie jede andere. Als Anweisung importiert ist das ein offener Punkt,
  den niemand durchführen kann.
- **Aviat** hat **zwei Tabellen auf einer Seite**: Bulletins und darunter
  Service Letters. Ein gieriges Tabellenmuster schluckt beide, und ein Service
  Letter als Bulletin geführt behauptet eine Pflicht, die der Hersteller nicht
  ausgesprochen hat.
- Beim **Aeroclubul României** trägt die **Kopfzeile sechs Zellen** wie eine
  Datenzeile und rutscht durch `min_cells`; ihre „Nummer" ist das Wort *SB*.
  Ohne `page.ignore` steht eine Anweisung namens „SB" mit dem Gegenstand
  „Description" in der Liste.

Der Musterhalter der IS-Muster ist belegt: ICA Brașov gibt es nicht mehr, TCDS
EASA.A.453 (Ausgabe 03, 15.12.2017) nennt den **Aeroclubul României**. Der
Motorsegler **IS-28M2** ist dort *nicht* veröffentlicht — er steht in der TCDS,
hat aber keine Seite; fünf Adressvarianten und die vollständige Seitenliste
geprüft.

### Prüfstand: was die Tests festhalten

Die zehn Quellen kamen zunächst ohne Test in den Bestand, und das war die
eigentliche Lücke: Gegen die Live-Website gemessen zu haben fängt nichts ab,
wenn ein Hersteller sein Portal umbaut — dann liefert der Import still null
Zeilen. Inzwischen sind es **309 Tests** im Modul.

Die Fixtures sind die echten Seiten, byte-genau: Rotax und Zlin liefern CRLF,
und das repoweite `text=auto eol=lf` normalisiert das beim Einchecken. Eine
Fixture, die sich beim Klonen ändert, prüft nicht mehr die echte Seite.
*Anmerkung:* Dieselbe Normalisierung trifft auch die älteren Fixtures
(Schleicher, Streifeneder, Scheibe, DG). Deren Tests laufen grün, weil sie gegen
den normalisierten Stand geschrieben wurden — aber wer eine davon neu vom
Hersteller zieht, bekommt eine andere Datei als die im Repo. Bewusst nicht
nachträglich umgestellt.

Festgehalten werden vor allem die **stillen** Fehler, nicht die Zeilenzahlen:
dass CEAPR *kein* Datum liefern darf, dass Rotax' zweite Tabelle nicht
mitgelesen wird, dass Continental per POST gewandert wird (das Double verweigert
GET), dass zwei Austro-Motoren Ordner desselben Namens haben, und dass keine
Antriebsquelle je wieder als Flugzeugmuster im Bestand liegt.


## 18. Grob: drei Defekte im Übersichtsleser, zwei behoben

Der G-109-Fall hat drei Fehler sichtbar gemacht, und **keiner davon ist
Grob-spezifisch** — sie träfen jeden Hersteller, der so druckt. Keiner meldet
sich: Das Blatt wird gelesen, Zeilen kommen zurück, und der Text darin gehört in
die falsche Zelle.

### 1. Eine Überschrift auf jeder Seite wurde zur Spalte — behoben

Spalten werden gemessen, indem gezählt wird, wo Text wiederkehrt; die Schwelle
liegt bei fünf Vorkommen. Ein sechsseitiges Blatt reißt diese Schwelle **mit
seinen eigenen Überschriften**. Gemessen auf dem G-109-Blatt:

| Position | Vorkommen | Inhalt |
|---:|---:|---|
| 0 | 88 | die echten Nummern (`817-1` …) |
| 8 | 6 | `Print Date: 15.04.2026` — der Seitenfuß |
| 24 | 171 | die echten Titel |
| 56 | 1 | das Wort `Title` — die Überschrift selbst |

Die Überschrift snappte damit auf **sich selbst** statt auf ihre Daten. Jetzt
werden Spalten nur noch aus Zeilen gemessen, die nicht wörtlich mehrfach
vorkommen. Das ist ausdrücklich *nur* fürs Messen — §15 hält fest, warum
„kommt mehrfach vor" nicht genügt, um eine Zeile zu *verwerfen* (drei Zeilen der
Club Libelle teilen sich einen Gegenstand). Eine echte Zeile aus der Zählung zu
lassen verschiebt nichts; eine Überschrift mitzuzählen erfindet eine Spalte.

### 2. Mittige Überschriften — behoben, per `headings_centred`

Normalerweise steht eine Überschrift am Anfang ihrer Spalte, und der nächste
gemessene Anfang ist die richtige Antwort. Grob zentriert: `Title` steht auf 57
über einer Spalte, deren Einträge alle auf 24 beginnen — und 57 ist näher an 84
als an 24. Mit `overview.headings_centred: true` gilt „die Spalte, über der die
Überschrift steht": die letzte gemessene Spalte an oder vor ihr.

Erklärt statt erkannt, weil beide Layouts bei einer schmalen Spalte
ununterscheidbar sind — dort *sind* Mitte und Anfang dieselbe Stelle — und ein
falscher Schluss dort still wäre.

### 3. `12-May-1981` — behoben

Das Datumsmuster ließ Punkte und Leerzeichen als Trenner zu, aber keine
Bindestriche. Alle 70 Zeilen kamen ohne Ausgabedatum an. Jetzt 62 von 70.

### Vierter Defekt: die dritte Zeilenform — behoben

Nach den drei Korrekturen stimmten Nummer, Datum, Verbindlichkeit und
Betroffenheit, aber der **Titel** war ein Bruchstück. Der Grund ist eine
Zeilenform, die der Leser nur in ihren beiden Hälften kannte. Ein Eintrag
belegt drei Zeilen, durch Leerzeilen getrennt:

```
A            Änderung des Fluggewichtes von 810 kg auf
B   817-1    Zuladung                     X   12-May-1981
C            Increase of max. weight from 810 kg
```

Gebraucht wird „A **und** B nehmen, C verwerfen". `numbers_centred_in_row`
nimmt A, `bilingual` verwirft C — einzeln richtig, zusammen kürzten sie sich:
Mit beiden blieb nur `Zuladung`, ohne `bilingual` wanderte C an den **nächsten**
Eintrag.

Wo eine Spec beide Merkmale setzt, hält die Suche jetzt nicht mehr an der
Nummernzeile an, sondern steigt hoch. Die Abbruchbedingung ist strukturell und
nicht sprachlich: **eine Zeile, die unter einer Nummernzeile steht, ist deren
Übersetzung** — niemals der Text des Eintrags darunter. Leerzeilen zählen dabei
nicht als Grenze; das zu übersehen kostete beim ersten Anlauf mehrere Titel,
weil Grobs Übersetzung eben *nicht* direkt unter der Nummer steht.

Keine bestehende Quelle setzt beide Merkmale (DG nur `bilingual`, Streifeneder
und SZD nur `numbers_centred_in_row`), Grob ist also die erste — und keine
andere ändert sich dadurch.

### Was bleibt: 6 von 68 Titeln, gezählt und festgepinnt

`grob-g109.yaml` ist ausgeliefert. **62 von 68 Titeln sind vollständig**, alle
68 tragen Nummer, Verbindlichkeit und Betroffenheit, 60 ein Ausgabedatum.

Sechs Titel tragen die englische Fassung des **vorhergehenden** Eintrags als
Vorspann („Increase of max. weight … Abweichungen gegenüber dem
Gerätekennblatt …"). Der eigene deutsche Titel steht vollständig dahinter, die
Zeile ist also durchsuchbar — sauber ist sie nicht. Genauso behandelt wie SZDs
35 mitten im Satz beginnende Titel (§15): benannt, gezählt, und durch einen Test
festgehalten, damit die Zahl nicht unbemerkt wächst.

Vorgabe zur Anforderung, die den Ausschlag gab: „bitte die vollständigen daten …
der user will nicht überall rein schauen müssen und es vereinfacht die
durchsuchbarkeit der liste." Ein Bruchstück wie `Zuladung` erfüllt das nicht —
niemand sucht nach dem letzten Wort.


## 19. Aquila, Korff, Hoffmann -- und eine zweite Liste

### Der Fehler, der die ganze Zeit da war: der Treiber bat um JSON

Gefunden beim Bau der Hoffmann-Quelle, betrifft aber vor allem **Scheibe**. Der
Accept-Header lautete `text/html, application/json` -- gleiche Gewichtung, und
ein Server, der den Inhaltstyp verhandelt, darf sich aussuchen, was er liefert.

Scheibe sucht sich JSON aus. Ihre Musterseiten kamen als **363 kB escaptes
Markup** (`<table class=\"table\">`) statt 84 kB HTML -- mit **null**
gewöhnlichen `href="` darin. Jedes Muster jeder Spec ist gegen HTML geschrieben,
also kam eine Seite an, zerfiel zu nichts, und der Import meldete, der
Hersteller habe nichts veröffentlicht.

Unbemerkt blieb es, weil die Fixtures mit einem Browser gespeichert wurden: **Die
Tests sahen das HTML, das der Treiber nie bekam.** Nach der Korrektur liefert
dieselbe Seite 166 statt 0 Links. Gegengeprüft, dass JSON-Quellen unberührt
bleiben: FAA 48 Zeilen, C.E.A.P.R. 286, beide unverändert.

### Valentin Taifun: ein Blatt, das seitenverkehrt gebaut ist

40 Zeilen, alle mit Ausgabedatum, 31 verbindlich / 9 optional. Zwei Defekte,
beide allgemein:

- Der Leser suchte die Nummer nur **links** der Betroffenheitsspalte -- eine
  Annahme aus den Schleicher- und DG-Blättern, wo die Effektivität der Nummer
  folgt. Korff baut umgekehrt: Baureihe und Werknummern auf Spalte 0 und 11, die
  TM-Nummer auf 39. Damit lag jede Nummer rechts der Betroffenheit und wurde
  übersprungen; gemeldet wurde, keine einzige Zeile passe zum Muster -- für ein
  Blatt, bei dem alle 28 passen.
- Die Nummernzelle steht über drei Zeilen: **Herausgeber, Nummer, Datum**.
  `KOCO` als Nummer gelesen zerfiel jeder Zuliefereintrag in zwei Zeilen. Die
  Herausgebernamen gehören in `overview.ignore`, nicht ins Nummernmuster.

Die LTA-Spalte ist **nicht** zugeordnet: Ihre Überschrift steht zwölf Zeichen
neben der TM-Überschrift, und der Leser bekam dort die TM-Nummer. Eine gesetzte
Behördennummer macht jede Zeile verbindlich -- auch die neun als optional
ausgewiesenen, und zwar still. Eine fehlende Referenz sieht man, eine falsche
Verbindlichkeit nicht.

### Hoffmann Propeller: nicht erreichbar, deshalb über Scheibe

Hoffmanns eigene Seite antwortet HTTP 200 und enthält **null Tabellen, null
PDF-Links**; **1,8 MB** ausgelieferte JS-Bundles tragen weder den Suchhost noch
einen Schlüssel. Die gefährlichste Sorte Quelle -- sieht vollständig aus und ist
leer.

Scheibe veröffentlicht die Hoffmann-Propeller-TMs weiter, die ihre eigenen
Muster betreffen, mit Datum, Behördennummer und Frist. Das ist **nicht**
Hoffmanns vollständige Liste, und die Spec sagt es: Eine Teilliste, die man für
vollständig hält, ist schlechter als eine, deren Grenze man kennt.

### Aquila: 241 Zeilen, kein Datum, und eine zweite Liste

Aquilas Blatt führt alles, was die AT01 betrifft -- eigene TMs neben Rotax,
MT-Propeller, Garmin und EASA-ADs, quer durch das Dokument verschachtelt. Der
Weg dahin ging über vier Durchläufe: 269 unbekannte Einträge, dann 72, 19, 1, 0.
Der lehrreichste Schritt war, dass `EASA AD` *innerhalb* der Zelle von AT01-002
steht und als Nummer gelesen den Eintrag zerriss.

**Die Datumsspalte ist nicht zugeordnet.** Aquilas Spaltenpositionen driften
zwischen den Seiten (Daten auf 28/38/48 und 111/117, gemessen
35/38/52/59/94/105). Der Leser griff dadurch Revisionsdaten vom Deckblatt ab:
AT01-001 bekam 07.05.2021, im Blatt steht 14.07.2014. Dieselbe Entscheidung wie
bei C.E.A.P.R. -- ein falsches Ausgabedatum ist schlimmer als keines.

Neu dafür im Leser: `overview.title_strip`. Wo Spalten driften, schiebt ein Blatt
seine „trifft nicht zu"-Striche in die Nachbarzelle (`Wegfall Vortex Generator
/--- ---`). Entfernt wird nur, was die Spec benennt.

### `secondary_list`: was einem anderen gehört, bleibt dort

Gemessen: **67 der 74** rotaxartigen Nummern auf Aquilas Blatt stehen
zeichengleich auch in `rotax.yaml`. Aquila benutzt die Originalnummern.

| Vorgehen | Folge |
|---|---|
| alles importieren | 67 doppelte Zeilen, jede zweimal abzuhaken |
| Zulieferabschnitte ausschließen | **sieben** Anweisungen verschwinden, die es nur hier gibt |
| `secondary_list: true` | beides vermieden |

Die Richtung war vorgegeben: „ich hätte lieber eine nicht mehr gültige tm, die
ich als alt abhaken kann wie eine zu wenig." Die sieben sind aus Rotax' eigener
Suche verschwunden, für frühe Werknummern aber weiter gültig -- genau die, die
ein Ausschluss gekostet hätte.

Mit dem Schalter bleibt eine Zeile, deren Nummer eine **andere** Quelle schon
führt, bei jener Quelle; was nur auf diesem Blatt steht, wird angelegt. Der
Import **nennt**, was er abgegeben hat (`deferred`), statt es still zu
verschlucken: „nichts importiert" und „steht schon beim Hersteller" sehen in
einer Zahl gleich aus, und nur eines davon ist in Ordnung.

Zwei Grenzen, mit Absicht:

- **Nur wo diese Quelle noch nichts abgelegt hat.** Wurde Aquila vor Rotax
  importiert, trägt die Zeile womöglich schon Beurteilungen; sie nachträglich
  abzugeben hieße, jemandes Arbeit zu löschen.
- **Nur wo die Spec es erklärt.** Zwei Hersteller dürfen dieselbe Nummer für
  verschiedene Dokumente benutzen; eine gewöhnliche Quelle gibt nie etwas ab.


### Piper: drei Hürden, alle gemessen

Der SB/SL-Index (P/N 762-332, 55 Seiten, Stand 30.06.2026) deckt **PA-18 Super
Cub und PA-25 Pawnee** ab -- die beiden klassischen Schleppmaschinen -- und ist
die einzige geprüfte US-Quelle mit Ausgabedatum *und* AD-Querverweis.

**1648 Zeilen, 1592 davon mit Ausgabedatum.**

Drei Hürden lagen davor, jede allgemein und keine Piper-spezifisch:

**Je Seite eigene Spaltenpositionen.** pdftotext setzt jede Seite für sich: Wo
eine Zelle lang läuft, verschieben sich die Spalten daneben und auf der nächsten
Seite wieder zurück. Über 55 Seiten als EIN Dokument gemessen kamen 97 von rund
2000 Einträgen heraus, mit Nummer, Gegenstand und Datum verschiedener Zeilen
vermischt -- Nummern, die `V-Band` oder `Stabilator` hießen.
`overview.columns_per_page` schneidet das Blatt an jeder Wiederholung seines
eigenen Tabellenkopfs und misst jede Seite einzeln. Der Schnitt läuft zwei
Zeilen **über** dem Kopf, weil ein Kopf zwei bis drei Zeilen hoch ist.

Erkannt wird ein Kopf daran, dass Nummern- **und** Gegenstandsüberschrift auf
derselben Zeile stehen: Pipers Fließtext benutzt das Wort „number" neunmal, ehe
die Tabelle beginnt, und darauf zu schneiden ergab Bruchstücke ohne Tabelle. 227
Treffer werden so zu 196 echten.

**Nummer und Gegenstand stehen ein Leerzeichen auseinander.** Mit `-layout`
rendert pdftotext beide als EINEN Textlauf (`2 Special Tubing in Fuselage`) --
und keine Spaltenmessung holt eine Grenze zurück, die in der Ausgabe nicht
steht. `overview.text_mode: '-fixed 2'` legt die Zeichen stattdessen auf ein
festes Raster und hält sie auseinander. Nicht als Default, weil das Raster
gerade die Blätter verzerrt, die `-layout` heute richtig liest.

**Das Datum steht amerikanisch.** `2/15/46` ist eindeutig, `1/7/26` nicht -- und
die meisten sind vom zweiten Typ. `overview.date_order: mdy` wird deshalb
**erklärt und nie erkannt**; C.E.A.P.R. ist der Gegenfall, wo dieselbe
Schreibweise beide Konventionen trägt und die Spalte darum leer bleibt. Das
zweistellige Jahr löst bei 26/27 auf, was das Dokument selbst deckt: Seine Daten
laufen 1946 bis 1999 und dann ab 2010, dazwischen nichts.

Dem Index steht eine mehrspaltige **Supersession List** voran, die zeigt, welches
Bulletin welches ersetzt. Ihre Einträge sind Verweise und keine Anweisungen; sie
stehen in `ignore`, zusammen mit Teilenummern und ATA-Codes, die bei driftenden
Spalten in die Nummernspalte rutschen. Der Trichter dorthin lief über sechs
Durchläufe: 621 unbekannte Einträge, dann 527, 73, 38, 5, 1, 0.


## 20. Die Echo-Klasse fertig -- bis auf eine

| Quelle | Modus | Zeilen | mit Datum |
|---|---|---:|---:|
| American Champion (Citabria, Scout, Decathlon) | `list` | 45 | 0 |
| EXTRA EA-300 | `overview` | 36 | 33 |
| Maule Air | `overview` | 33 | 0 |

**American Champion** ist eine Seite ohne Tabelle: ein Wix-Repeater, im
Roh-HTML kein `<table>`, nur eine Folge aus Nummer, Gegenstand und Link. Die
Komponentenklassen wechseln bei jedem Umbau der Seite und taugen nicht als
Anker -- die **Reihenfolge** dagegen ist der Inhalt selbst. Die Fenster im
Zeilenmuster sind gemessen: zwischen Nummer und Link liegen rund 840 Zeichen
Markup. Zwei Nummernformen erzählen dabei die Firmengeschichte -- `C-135` aus
der Champion-Zeit neben der blossen 400er-Reihe.

**EXTRA** hat eine allgemeine Korrektur an der Kopferkennung erzwungen: Ihre
Seitenkopfzeile wiederholt `Doc. N°: EA-03704` -- was die Nummernüberschrift
enthält -- und der echte Tabellenkopf steht zwei Zeilen darunter. Auf der ersten
Zeile zu ankern, die eine Nummer nur *erwähnt*, und den Gegenstand dann aus dem
Fenster daneben zu borgen, setzte die Nummernspalte auf 167, wo die Nummern
selbst auf 0 stehen. Der Leser hat das bemerkt und verweigert -- er hatte nur den
falschen Kandidaten. Eine Zeile, die **beide** Überschriften selbst trägt, wird
jetzt bevorzugt; Grob, dessen Kopf über drei Zeilen gebrochen ist, fällt
weiterhin auf das Borgen zurück.

**Maule** führt kein Datum -- weder Spalte noch Text -, und die AD-Nummern
stehen im Gegenstand in Klammern statt in einer eigenen Spalte. Sie bleiben dort
stehen: sie herauszuschneiden hiesse, den Satz des Herstellers umzuschreiben,
und sie als Spalte auszugeben hiesse, eine Struktur zu behaupten, die das Blatt
nicht hat. Die Datei trägt ModDate Mai 2017.

### Leonardo F/SF-260: gemessen, nicht gebaut

53 Seiten mit **vier** Tabellen darin -- Service Bulletins, Service
Instructions, Service Letters, Technical Instructions -, jede mit eigener
Kopfzeile und eigenen Spalten. Mit seitenweiser Messung kommen 630 Zeilen und
199 Ausgabedaten heraus, aber die **Titel sind Mus**: mehrzeilige Gegenstände
verschiedener Zeilen laufen ineinander („bellcranks P/N P/N 260-14-bellcranks
the engine cowl").

Nummern und Daten wären brauchbar, die Titel nicht -- und ein Titel ist der
Grund, warum jemand die Zeile findet. Nicht ausgeliefert.

Nebenbefund für einen späteren Anlauf: Die Adresse trägt die **Jahreszahl**, und
der Index des Vorjahres bleibt erreichbar. Ein Import kann dort unbemerkt
einfrieren. Der Cache-Parameter (`?t=…`) der Website ist für den Abruf nicht
nötig.


## 21. EASA: die Anweisungen, auf die es ankommt

Bis hierher kamen EASA-ADs nur **zweiter Hand** herein -- dort, wo ein Hersteller
in seiner eigenen Spalte eine zitiert. Für europäisch zugelassene Muster ist die
EASA-AD aber die verbindliche Anweisung. Jetzt gibt es sie als eigene Quelle.

**Kein Login, kein Schlüssel.** `ad.easa.europa.eu/search/` beantwortet einen
gewöhnlichen GET mit der vollständigen Ergebnistabelle. Das Formular der Seite
postet zwar, aber dieselben Parameter als Query-String liefern Zeichen für
Zeichen dasselbe.

Gemessen gegen die Angabe der Behörde selbst: Schempp-Hirth **57 von 57**,
C.E.A.P.R. **89**, alle mit Ausgabedatum und Dokument.

### Zwei Fallen, beide gemessen

**Die Liste ist gedeckelt und sagt es.** Die Seite zeigt zwanzig Treffer und
schreibt daneben „Displaying records 1 to 20 out of a total of 57". Ohne
Pagination hätte die Quelle stillschweigend das erste Fünftel geliefert -- und
zwar in der Form, die am schwersten auffällt: eine plausible Liste, nur kürzer.

Die Seitenzahl steht dabei im **Pfad** (`/search/page-2/?…`), nicht im
Query-String, und der Filter bleibt erhalten. Als Query-Parameter angehängt käme
immer Seite eins zurück. `pagedUrl()` kennt dafür jetzt einen `{page}`-Platzhalter.

**„Robin" findet Robinson.** Der Suchbegriff für die DR400 war zuerst der
Herstellername -- und lieferte `Main Rotor Drive – Clutch Shaft Forward Yoke`,
also R44-Anweisungen. Ein Verein hätte für seine Schleppmaschine
Hubschrauber-ADs bekommen. Der Begriff ist jetzt `CEAPR`; gegengeprüft liefert er
89 Treffer, ausschliesslich Robin-Muster.

Das ist der Grund, warum die Zuordnung Muster → Begriff **ausgeschrieben** in der
Spec steht und nicht abgeleitet wird: Ein Herstellername ist keine Kennung, und
die Behörde durchsucht Volltext.

### Was der Treiber dafür lernen musste

EASA ist die erste **Tabelle, die paginiert** -- DG und seinesgleichen sind
Listen. Der Paging-Pfad war entsprechend für Listen geschrieben und las jede
Spalte als Regex; im Tabellenmodus sind es Indizes, und `preg_match` weist eine
Zahl als Muster ab. Zwei Stellen lesen jetzt nach Zelle statt nach Muster,
sobald die Spec Spalten über Positionen benennt.

### Was offen bleibt

`easa-ad.yaml` kennt 31 Muster. Jedes weitere braucht dort eine Zeile -- das ist
bewusst Handarbeit, aus demselben Grund wie bei der FAA: Ein geratener Begriff
schlägt nicht fehl, er liefert eine leere Liste, und eine leere Liste von
Lufttüchtigkeitsanweisungen liest sich wie „für Ihr Flugzeug wurde nichts
erlassen". Ein Abruf ohne Begriff liefert **alle** Anweisungen; der Treiber lehnt
ein unbekanntes Muster deshalb namentlich ab.

Ein Abruf dauert rund zwei Minuten -- drei bis fünf Seiten, mit Pause und
zweitem Durchlauf. Für einen Verein irrelevant, weil nur die geflogenen Muster
abgerufen werden.

---

## 22. Die NfL: der zweite Weg, und die einzige Quelle mit deutscher LTA-Nummer

Gewollt war einen zweiten Weg -- „zum einen zum Abgleich, zum anderen weil er
auch andere Behörden umfassen sollte". Die **Nachrichten für Luftfahrer** liefern
beides, und sie sind seit dem **31. März 2026 kostenfrei**; das ist der Grund,
warum das überhaupt geht.

Eine einzige Bekanntmachung trägt Anweisungen von **vier Behörden nebeneinander**:

| deutsche Nummer | Behördennummer | Halter |
|---|---|---|
| D-2026-152 | EASA AD 2026-0132 | Airbus Helicopters |
| D-2024-167R1 | UK CAA AD G-2026-0002 | BAE Systems |
| D-2026-046R2 | FAA AD 2026-14-11 | The Boeing Company |
| D-2026-114R1 | TC Emergency AD … | Pratt & Whitney Canada |

Die deutsche Nummer links ist die, nach der ein deutscher Prüfer fragt -- und sie
existiert **sonst nirgends**: nicht im EASA-Werkzeug, nicht beim Hersteller.

### Warum eine Klasse und keine YAML

`SourceSpec` sagt es selbst: der konfigurierte Treiber liest Hersteller, die eine
Tabelle veröffentlichen; „wer ein PDF oder eine JavaScript-gerenderte Liste
ausliefert, braucht weiter eine Klasse". Die NfL sind **beides zugleich** -- ein
DHTMLX-Grid, dessen Zeilen auf PDFs zeigen -- und obendrein eine Sitzung, die von
Aufruf zu Aufruf mitgeführt werden muss. Nichts davon steht in der Seite.

Die Kette, gemessen am Livedienst:

```
1. startApplication.php                 eine Sitzung (PHPSESSID)
2. connGrid_1470_<hash>.php?grBag=…     die Liste als XML, 400 Zeilen je Aufruf
3. POST CustomPDF_NfL.php               grVariableBag mit NfL_ID  ->  eine GUID
4. getNfL.php?GUID=…                    die Bekanntmachung als PDF
```

### Drei Fallen, jede eine Stunde wert

**Die Signaturparameter der Oberfläche gehören der Oberfläche.** Das UI hängt
`uschrift=…` an, gebunden an *seine* Sitzung. Mitgeschickt antwortet der
Connector 403. Weggelassen antwortet er.

**403 heisst „deine Sitzung ist alt", nicht „du bist gesperrt".** Dieselbe URL,
die eben noch 403 lieferte, antwortete mit frischer PHPSESSID sofort wieder. Wer
403 als Wand liest, gibt einen weit offenen Dienst auf.

**Das Dokument hängt nicht an der Zeilen-ID.** Schritt 3 nimmt einen anders
benannten Parameter -- `grVariableBag`, nicht `grBag` -- und darin `NfL_ID`,
nicht `rowID`. Mit dem falschen antwortet der Dienst `{"PDF":{"URL":""}}`:
Status ok, kein Fehler, kein Dokument. Das ist genau die Form, vor der dieses
Modul den meisten Respekt hat, und sie war nur zu sehen, indem ein echter
Chromium ferngesteuert und **seine Request-Bodies mitgeschnitten** wurden (CDP).
Eine leere Dokumentadresse gilt im Client deshalb als Fehlschlag und
ausdrücklich nicht als „kein Dokument".

### Aus dem PDF die einzelnen Anweisungen

Vorgabe: „ich brauch die einzelnen datensätze." Die Bekanntmachung druckt vorn
eine Übersichtstabelle und dahinter **jede Anweisung noch einmal vollständig**.

**Der erste Treffer gewinnt** -- und das ist keine Ordnungsliebe. In den
Wiederholungsseiten steht dieselbe Nummer erneut, nur sind die Spalten daneben
dort der Kolumnentitel und die Seitenzahl. Der spätere Treffer gab
D-2024-199R1 den Titel **„275/2026"**: eine Seitenzahl, wo ein Hersteller
hingehört, und in einer Liste wäre daran nichts aufgefallen. Die Tabelle steht
vorn, also ist der erste Treffer der aus der Tabelle.

**Umbrochene Halternamen.** „ROLLS-ROYCE" steht in einer Zeile, „DEUTSCHLAND Ltd
& Co KG" in der nächsten -- eine halbierte Zeile liest sich wie ein anderer
Hersteller. Die Fortsetzung wird **nur an den Halter** angehängt; an den ganzen
Rest gehängt entstand einmal „ROLLS-ROYCE EASA.E.036 DEUTSCHLAND Ltd".

Dazwischen druckt das Blatt seine eigene Möblierung -- „28 JUL 2026" auf einer
Zeile, „275/2026" auf einer anderen. Die Fortsetzung ist also nicht verlässlich
die nächste Zeile. Drei Zeilen Vorschau, Möblierung nach Form übersprungen,
Abbruch an der nächsten nummerierten Zeile, damit nie eine Fortsetzung vom
Eintrag darunter geborgt wird.

**Verbindlich, und zwar nicht mangels besserer Angabe:** Die NfL veröffentlichen
diese unter § 14 LuftBO -- das ist, was sie in Deutschland überhaupt verbindlich
macht.

### Keine Dopplungen: die Behördennummer ist das Band

EASA führt dasselbe Dokument als `2026-0132`, Deutschland als `D-2026-152`. Ein
Vergleich der beiden nationalen Nummern fände die Übereinstimmung **nie**. Das
Band ist die Behördennummer, die im Blatt danebensteht.

Die NfL sind deshalb eine `SecondaryList`, wie Aquila: Was eine andere Quelle
bereits führt, bleibt dort; was es **nur** hier gibt, wird angelegt. Und das ist
der eigentliche Grund für die Quelle -- für ein Annex-I-Muster gibt es keine
EASA-Anweisung, und dann ist dies die einzige Stelle, die eine führt. Pauschales
Nachgeben würde genau die wegwerfen.

Der Abgleich probiert die eigene Nummer, die Behördennummer wie geschrieben und
die nackte Nummer daraus (`EASA AD 2026-0132` → `2026-0132`).

### Das Kennblatt hängt am Muster, nicht an der Zeile

Vorgabe: „die kennblattnummer ist im kfz typ im flottenmodul hinterlegt." Das
Blatt nennt Halter und Kennblatt (`EASA.A.189`), **nie das Muster**. Aus der
Zeile ist das Muster also nicht zu lesen -- nachschlagen lässt es sich schon.

Gefragt wird über `Fleet\Types\TypeLookup`, nicht über Fleets Eloquent-Modell:
Das LTA-Modul darf die Flotte kennen (`requires: ['fleet']`), aber die Frage
lautet nur „fliegen wir das, und unter welcher ID". Wie ein Kennblatt
geschrieben wird, dass ein Muster mehrere tragen kann (`EASA.R.008, EASA.R.146`)
und dass eine Nummer, die niemand fliegt, ein **normales Ergebnis** ist und kein
Fehler -- das ist Wissen der Flotte und bleibt dort. Das meiste, was eine Behörde
veröffentlicht, betrifft Flugzeuge, die dieser Verein nicht hat; das als Fehler
zu behandeln, machte aus jedem Import eine Wand aus Warnungen.

### Was es bewusst nicht tut

**Nicht das ganze Archiv lesen.** Rund **664 der 9838 Einträge** sind
LTA-Bekanntmachungen, jede ein eigenes PDF. Das Fenster ist ein Parameter
(Vorgabe: sechs Bekanntmachungen, neueste zuerst) -- das ist die Reihenfolge des
Blattes selbst, und ein Verein will das, was seit dem letzten Lauf erschienen
ist. Ein vollständiger Erstimport über das Archiv ist offen.

Gelesen wird nur **Teil II**; Teil I ist Luftraum und Flugplätze.

Findet der Treiber in den geholten Einträgen **keine einzige** Bekanntmachung,
bricht er ab statt „nichts Neues" zu melden: Das gibt es nicht, und es hiesse
entweder, die DFS hat die Liste umgebaut, oder die Sitzung liefert nicht, was sie
soll.

---

## 23. Die Restliste: SZD gelöst, zwei Quellen dazu, der Rest gemessen

### SZD: die Zeilengrenzen ausrechnen statt raten

Die SZD-Blätter waren als **nicht produktionstauglich** angepinnt — der einzige
Punkt im Modul, der eine gebaute Quelle blockierte. Ursache: SZD setzt die
Nummer senkrecht mittig und trennt die Zeilen nicht durch Leerzeilen (Puchacz
12 von 70 Übergängen, Junior 0 von 13). Der Leser nahm den Block ab der
Nummernzeile, also lief jeder Titel in die Nachbarzeile — `control surfaces`
landete bei BE-023 statt bei BE-022 — und aus übergelaufenem
Betroffenheitstext wurden falsche Serienbereiche. **Ein falscher Serienbereich
lässt eine Anweisung stillschweigend entfallen.**

Die Anordnung ist aber exakt beschreibbar. Die Zeilen **stoßen aneinander** und
jede ist **symmetrisch um ihre Nummer**, also:

```
Ende = 2 · Mitte − Anfang     nächster Anfang = Ende + 1
```

Nichts daran ist geschätzt; jede Grenze folgt aus der vorigen, und gebraucht
wird nur der Anfang der ersten Zeile jeder Seite. Am Junior-Blatt von Hand
nachgerechnet, alle 14 Zeilen, einschließlich der sieben- und dreizehnzeiligen.

Als eigener Modus `overview.rows_tile_around_numbers`, nicht als Änderung an
`numbers_centred_in_row`: Streifeneder setzt seine Nummern zwar auch mittig,
trennt seine Zeilen aber durch Leerzeilen und kachelt nicht. Es diesem Blatt
zu unterstellen wäre eine Aussage, die seine Anordnung nicht macht.

Drei Nebenbefunde kamen dabei mit ans Licht:

- **Drei Zeilen Gnadenfrist unter der Kopfzeile.** Sie galten bedingungslos als
  Seitenkopf. Bei SZD beginnt die erste Datenzeile zwei Zeilen darunter — sie
  wurde als Seitenmöbel verworfen, und das war der Serienbereich der ersten
  Zeile. Kachelnde Blätter bekommen diese Frist nicht mehr.
- **„Jantar Standard-3"** steht unter jeder Nummer, sechzehnmal, erste Spalte:
  nach der Wiederholungsregel Seitenmöbel. Die Spec führt es aber unter
  `ignore`, sagt also ausdrücklich, dass es Zeileninhalt ist. Von der Spec
  benannte Nicht-Nummern gelten seither nicht mehr als Möbel.
- **Das Jantar-Blatt wiederholt seine Spaltenüberschrift auf Seite 2 nicht.**
  Bei einem kachelnden Blatt sind drei aufeinanderfolgende Leerzeilen eine
  Seitengrenze — aneinanderstoßende Zeilen können keine Lücke dieser Größe
  enthalten.

Alle sieben Blätter lesen jetzt die Sätze des Herstellers. Was bleibt, ist eine
**Messgrenze und keine Zeilengrenze**: pdftotext setzt „Vrs." und „S/N" zwei bis
vier Zeichen auseinander, beide sind dieselbe gemessene Spalte, der
Sprachmarker steht daher im Serientext (`From S/N B-903 to B-EN 907`). Mit
`headings_centred` und `subject: ['s/n']` versucht — das verschiebt ihn nur nach
`skipped()`. Im Test angepinnt statt versteckt. `markTestIncomplete` entfällt.

**Grob wurde gegengeprüft und bleibt, wie es ist:** die Kachelung macht es
schlechter (817-11 verliert sein Datum), weil Grobs Zeilen durch Leerzeilen
getrennt sind. Eine andere Anordnung, also der andere Modus.

### NfL: das Archiv, und eine Quelle, die fünfmal geholt wurde

Das Fenster der neuesten Bekanntmachungen ist für den wöchentlichen Lauf
richtig, füllt aber keine frische Installation: Die noch gültigen Anweisungen
wurden lange vorher veröffentlicht. Mit `--all-types` liest die Quelle jetzt
das ganze Archiv — rund 664 Bekanntmachungen aus 9838 Einträgen, jede ein
eigenes PDF, gut eine Stunde. Einmal; danach reicht das Fenster wieder.

Dabei fiel auf, dass **die Gazette einmal je Flugzeugmuster geholt wurde**. Der
Refresh fragt Quellen musterweise, was für einen Hersteller stimmt — der
veröffentlicht ein Blatt je Muster. Eine konfigurierte Quelle sagt über ihre
Spec, dass sie einseitig ist; eine klassenbasierte konnte es gar nicht sagen.
Kaputt war nichts, die Zeilen sind identisch und der zweite Import aktualisiert
den ersten — deshalb fiel es nicht auf. Es ist der Server der DFS, der dieselbe
Frage fünfmal beantwortet. Dafür jetzt `SinglePageSource`.

### Zwei neue Quellen

**UL Power Aero Engines** (23 Anweisungen, 001 bis 2026-001). Ein
Motorenhersteller; eine Antriebsanweisung gehört dem Antrieb und erreicht das
Flugzeug über die Komponente. Die sauberste Seite dieses Moduls — Nummer,
Gegenstand und Datum je in einem eigenen `div`.

Der Leser lernte dabei ein drittes Datumsformat: „Friday 24 September, 2010",
mit **Komma zwischen Monat und Jahr**. Bekannt waren „23 March 2006" und
„June 10, 2026". Alle 23 Daten kamen als `null` zurück, während sonst alles
gesund aussah.

**REMOS** (19 Dokumente). Eine reine Linkliste ohne Datum, ohne Dringlichkeit,
ohne Musterangabe. Das Datum bleibt leer: aus dem Pfad `/2017/03/` ließe es
sich scheinbar gewinnen, aber das ist der Upload nach WordPress — fünfzehn der
neunzehn liegen unter demselben Monat.

Der eigentliche Fund dort: REMOS führt auf einer Seite **Service Bulletins und
Service Directives**, 13 und 6, wobei die Directive die schärfere Gattung ist.
Ein Muster nur auf `SB-` las dreizehn Zeilen und meldete nichts als fehlend —
die Liste sah vollständig aus, und es fehlten genau die verbindlichen
Dokumente.

### Die Restliste ist gebaut

Alle vier erreichbaren Quellen aus dem vorigen Abschnitt stehen jetzt.

**Bristell / BRM Aero** (38 Anweisungen). Die B23 trägt ein EASA-Kennblatt
(EASA.A.642), ist also ein zugelassenes Muster und kein Ultraleicht. Die
reichste Tabelle dieses Moduls: der Hersteller benennt die Verbindlichkeit in
einer eigenen Spalte, in sechs Stufen. Abgebildet auf die drei des Moduls, mit
einer bewussten Entscheidung — **„Could become AD" bleibt verbindlich**. Als
wahlweise eingestuft verschwände genau die Zeile aus der Aufmerksamkeit, die
morgen eine Lufttüchtigkeitsanweisung ist.

**Aerospool WT9 Dynamic** (50 Anweisungen). Drei Publikationstabellen — Service
Bulletins, Information Bulletins, Recommendations — und eine vierte auf
derselben Seite, die der Cookie-Hinweis ist. Sie fällt durch die *Form* heraus
(drei Zellen statt sechs) und nicht über ein Wort in der Überschrift, das der
Betreiber morgen ändert. Dokumente werden als PDF **oder ZIP** erkannt: ein
Muster nur auf `.pdf` liess die Archiv-Zeilen auf die Seite selbst zeigen —
eine Adresse, die aussieht wie ein Nachweis und keiner ist.

**Neuform Propeller** (4 Mitteilungen). Die dünnste Quelle des Moduls: kein
Titel, kein Datum, keine Dringlichkeit. Die Mitteilungen liegen zwischen
Betriebshandbüchern unter „Downloads", und der Linktext ist der Dateiname.
Gebaut trotzdem — der Unterschied zwischen „vier Mitteilungen, hier sind sie"
und „von Neuform wissen wir nichts" ist der Punkt dieses Moduls. Die Anlage
„Autorisierte Betriebe" ist ausgeschlossen: sie gehört zur TM-08-01 und ist
keine eigene Anweisung.

**Flight Design** (304 Dokumente in 17 Tabellen) — die grösste Quelle des
Moduls, und die einzige, die eine Runde zuvor **gemessen und bewusst nicht
gebaut** wurde. Die Seite ist ein verschachteltes Akkordeon aus Reitern, und
welches Muster eine Tabelle betrifft, steht nirgends in ihrer Nähe; die Reiter
heissen nur nach der Zulassungsbasis (ASTM, LT-UL, SECS). 304 Zeilen auf 17
Muster zu verteilen hätte einen DOM-Parser gebraucht, und eine Anweisung am
falschen Flugzeug ist schlimmer als keine.

Die Auflösung stand im Dokument selbst. Flight Design baut seine Nummern aus
vier Teilen:

```
SB   -   ASTM   -   CTLS   -   03
^        ^          ^          ^
Gattung  Zulassung  Muster     laufende Nummer
```

Damit trägt **jede Zeile ihr Muster selbst**, unabhängig von der Tabelle, in
der sie steht — die Verschachtelung wird gar nicht gebraucht.

Das Datum ist dort tagesführend, und das ist gemessen statt geschlossen: von
304 Daten haben 230 eine erste Zahl über zwölf und **kein einziges** eine
zweite über zwölf. Deshalb steht `date_order: dmy` ausgeschrieben in der Spec —
ohne diese Erklärung bliebe das Feld leer, wie bei C.E.A.P.R.

### Drei Funde im Leser, alle beim Bauen aufgefallen

**Nur die erste Tabelle einer Seite wurde gelesen.** Bristell druckt zwei — eine
für die B23, eine für alle übrigen Muster — und in der zweiten stehen 11 der 38
Anweisungen, darunter vier Safety Alerts. 27 Zeilen kamen zurück, der
Vollständigkeitsbericht war leer, und nichts sagte, dass eine ganze Tabelle
übergangen wurde.

Nicht als Verhaltensänderung repariert, sondern als `page.all_tables`: **Aviat
trennt seine Service Letters absichtlich in eine zweite Tabelle**, und die sind
keine Anweisungen. Beide Formen sind einander von aussen nicht anzusehen, also
wird es deklariert. Vorgabe bleibt „eine".

**Ein drittes Datumsformat mit Bindestrichen.** `26-10-2023` — bekannt waren
ISO und die deutsche Punktschreibweise. Neu gelesen wird es **nur**, wenn die
Spec `date_order` erklärt; ohne Erklärung bleibt das Feld leer, weil
`05-10-2020` zweimal ein gültiges Datum ist.

**Wiederholte Kopfzeilen mit voller Spaltenzahl.** Flight Design wiederholt
„Download | Date | Subject | …" in jeder der 17 Tabellen. Diese Zeile hat sechs
Spalten wie eine Datenzeile, `min_cells` kann sie also nicht abweisen — 51
Zeilen namens „Download" wären im Bestand gelandet. Über `page.ignore`
namentlich ausgeschlossen.

### Nicht erreichbar, und damit nicht vorhanden

Vorgabe: „was es online nicht gibt, das gibt es halt nicht."

| Hersteller | Befund |
|---|---|
| Jabiru | HTTP 403 auf die Bulletin-Seite — Bot-Sperre, nicht abrufbar |
| Sensenich | Startseite nennt keine Bulletin-Seite |
| McCauley | keine öffentliche Bulletin-Liste gefunden |
| Aeroprakt | Verbindung schlägt fehl (kein Antwortcode) |
| Sauer | Verbindung schlägt fehl (kein Antwortcode) |
| Leonardo (SF-260) | schon in §20 gemessen und bewusst nicht gebaut |
