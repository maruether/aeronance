# Änderungen

Das Format folgt [Keep a Changelog](https://keepachangelog.com/de/1.1.0/),
die Versionierung [SemVer](https://semver.org/lang/de/).

Jede Fassung nennt unter **Beim Update beachten** ausdrücklich, was ein Verein
tun muss, bevor er sie einspielt. Steht dort nichts, reicht `deploy/update.sh`.

## [Unveröffentlicht]

### Geplant für 0.2.0

Vorgemerkt, noch nicht gebaut — ein Eintrag wandert nach „Neu", sobald er es
ist. Ein Changelog beschreibt, was passiert ist; dieser Abschnitt sagt nur,
woran als Nächstes gearbeitet wird.

- **Ultraleichtflugzeuge.** Die Flotte soll ULs führen können. Sie leben
  regulatorisch in einer anderen Welt als EASA-Luftfahrzeuge — Nachprüfung,
  Kennblätter und die Mitteilungswege der Verbände funktionieren anders —,
  und diese Unterschiede brauchen eigene Antworten statt aufgebogener
  EASA-Felder. Der genaue Zuschnitt wird vor dem Bau entschieden und hier
  begründet.

- **Demomodus.** Eine öffentlich erreichbare Spielwiese mit Beispieldaten
  (`demo.aeronance.de`): ausprobieren ohne Installation, schreibend, aber
  folgenlos — der Bestand setzt sich regelmäßig selbst zurück. Was genau ein
  Demomodus abschalten muss (Mails, Updates, echte Herstellerabrufe), ist
  Teil des Zuschnitts.

## [0.1.8] — 2026-08-14

Feldtest-Runde mit zwei unangenehmen Funden: Zwei Dinge, die in früheren
Fassungen als „gebaut" galten, waren für einen Benutzer schlicht nicht
erreichbar. **Beim Update beachten:** Das neue Recht **„Freigabe eines
externen Prüfers eintragen"** (Gruppe *Werkstatt — Freigabe und Befunde*)
den Rollen zuteilen, die das dürfen sollen; der Administrator hat es
automatisch. Sonst genügt `deploy/update.sh` bzw.
`deploy/docker/update.sh`; eine Migration läuft mit.

### Neu

- **Freigabe durch einen vereinsfremden Prüfer.** Ein freiberuflicher
  Part-66-Prüfer oder ein LTB hat hier kein Konto — jetzt lässt sich seine
  Freigabe eintragen: Die Bescheinigung trägt seinen Namen, seine Lizenz-
  oder Betriebsnummer und den Betrieb, daneben steht, wer sie eingetragen
  hat. Auf dem Ausdruck steht ausdrücklich, dass die Nummer nach der
  Unterschrift abgeschrieben und hier nicht geprüft wurde. Alle übrigen
  Voraussetzungen einer Freigabe gelten unverändert.
- **Bestandsübersicht „Bestand".** Was tatsächlich im Lager liegt — für
  jede Art von Bestand, nicht nur für seriennummerngeführte Teile, die
  bisher als Einzige in der Lose-Liste auftauchten. Drei Zahlen je Teil
  (verfügbar, nicht verwendbar, gesamt), Filter nach Lagerort,
  Unterschreitung und gesperrtem Bestand.
- **Die Freigabe zeichnet fertiggemeldete Karten mit ab.** Wer freigibt,
  ist der Prüfer — seine Unterschrift ist dieselbe, die sonst einzeln unter
  jede Karte käme. Vorher nennt eine Warnung die betroffenen Karten beim
  Namen. Ohne Fertigmeldung bleibt es gesperrt: Sonst wüsste die
  Unterschrift nicht, worüber.
- **Arbeitszeit beim Fertigmelden.** Im selben Dialog, optional („1:30");
  für weitere Personen weiterhin über „Zeit erfassen".

### Behoben

- **Wartungsunterlagen ließen sich nicht anlegen — und damit auch nichts
  hochladen.** Die Liste hatte keinen „Neu"-Knopf; die Anlegeseite war
  zwar vorhanden, aber unverlinkt, und jede Zeilen-Aktion braucht einen
  Datensatz, den es so nie gab. Zusätzlich begrenzte Livewire alle Uploads
  auf 12 MB, während die Formulare 20 versprechen — ein Handbuch liegt
  genau dazwischen und brach ohne verständliche Meldung ab.
- **Die Wägeskizzen erschienen nie, weil nie Auflagen entstanden.** Beide
  Zeichnungen hängen an den Auflagen-Zeilen; angelegt hat sie niemand,
  obwohl die Wägungsart sie seit jeher kennt (zwei beim Segelflugzeug,
  drei beim Motorflugzeug). Jetzt entstehen sie mit dem Blatt — auf beiden
  Anlegewegen —, und der Knopf zum Nachtragen heißt nicht mehr „Zu support
  entries hinzufügen".
- **Auswahllisten im Dunkelmodus** waren weiß auf weiß — alle fünf im
  Projekt, nicht nur die im Inventurformular.
- **Der Vereinsname in der Kopfzeile** blieb nach dem Ändern stehen: Die
  Einstellungsseite tauschte nur ihren eigenen Bereich aus, während Name
  und Logo zum Seitenrahmen gehören.

## [0.1.7] — 2026-08-14

Direkt aus dem Feldtest der vorigen Fassung. **Beim Update beachten:**
nichts — `deploy/update.sh` bzw. `deploy/docker/update.sh` genügt. Der
Menüpunkt „Was liegt an" ist aus dem Lager verschwunden; sein Inhalt steht
jetzt auf der Startseite.

### Geändert

- **„Was liegt an" ist ein Startseiten-Widget**, keine Lagerseite mehr:
  Eine Liste offener Punkte hilft nur, wenn man sie sieht, ohne sie zu
  suchen. Wer daran denkt, sie aufzurufen, weiß meist ohnehin, was ansteht.
  Steht nichts an, erscheint der Kasten gar nicht — ein Widget, das täglich
  „alles in Ordnung" meldet, wird nach einer Woche überlesen, und dann auch
  an dem Tag, an dem etwas drinsteht. Die Direktlinks bleiben: Abgelaufenes
  führt weiter zur Vernichtung mit vorbelegtem Los.

### Geändert (Fortsetzung)

- **Der Fund bei der Inventur steht einmal am Ende**, nicht in jeder
  Bauteil-Kachel: mit Auswahl des Bauteiltyps, Menge und Notiz, beliebig
  oft. Gezählt wird jedes Teil, gefunden fast nie eins — ein Feld je Kachel
  kostete an jeder Zeile Aufmerksamkeit für den seltenen Fall. Zur Auswahl
  stehen losgeführte Teile; bei Sammelbestand ist Mehrbestand die Zählzahl
  oben. Am Ergebnis ändert sich nichts: eigenes Los, gesperrt, ohne
  Nachweis und ohne erfundenes Verfallsdatum.

### Behoben

- **Der Nachweis ließ sich nicht nachtragen.** Die rote Meldung verwies auf
  die Los-Ansicht — und die hatte überhaupt keine Aktionen; sie wohnten alle
  in der Liste nebenan. Die Ansicht trägt jetzt dieselben: Nachweis
  eintragen, Sperren, Zustand feststellen.
- **Leere Lose standen weiter auf der Mängelliste.** Was keine Restmenge
  mehr hat, kann niemand einbauen; der Datensatz bleibt als Nachweis, die
  Meldung verschwindet.
- **„Verfügbar" zählte Bestand, den die Ausgabe verweigert.** Die Zahl maß
  allein den Zustand, also stand ein Form-1-Los ohne Nachweis darin. Sie
  misst jetzt denselben Maßstab wie das Buchen; der aktuelle Bestand zeigt
  die Ware weiterhin — sie ist ja da.
- **Die Form-1-Pflicht nachträglich zu setzen ließ den vorhandenen Bestand
  unberührt.** Genau so entsteht der Fall aus dem Feldtest: Haken gesetzt,
  und die Lose sagten weiter „verwendbar" für Ware, die sich nicht mehr
  ausgeben ließ. Das Speichern sperrt diesen Bestand jetzt — mit Begründung
  im Protokoll und der Angabe, welche Lose es traf. Gesperrt, nicht
  gelöscht: Sobald der Nachweis am Los steht, wird regulär freigegeben.
  Ausbau-Lose und leere Lose bleiben ausgenommen.

## [0.1.6] — 2026-08-14

Runde sechs des Feldtests — und eine Lufttüchtigkeits-Naht, die fehlte.
**Beim Update beachten:** nichts — `deploy/update.sh` bzw.
`deploy/docker/update.sh` genügt (eine Migration läuft mit). Nach dem Update
lohnt ein Blick auf „Lager → Was steht an": Der neue Abschnitt
„Form-1-pflichtig, aber ohne Nachweis" zeigt Bestand, der ab jetzt nicht
mehr ausgegeben wird.

### Behoben

- **Form-1-pflichtige Teile ohne Nachweis waren einbaubar.** Der
  Wareneingang verweigert sie seit jeher — die AUSGABE prüfte den Nachweis
  nie, und die Freigabe aus der Quarantäne auch nicht. Damit half die
  Eingangswache nur so lange, wie niemand einen anderen Weg ins Regal fand
  (nachträglich gesetzte Form-1-Pflicht, Inventurfund, Reparaturrückkehr).
  Jetzt gilt: ohne Nachweis kein Einbau und keine Freigabe (ML.A.501).
  Ausgenommen bleibt der Rückbau in DAS Luftfahrzeug, aus dem das Teil
  stammt — dort trägt die Feststellung des Ausbaus, und weiter reicht sie
  nicht.
- **Eingangsprüfung:** Ein durchgefallener Zertifikats-Punkt führt nicht
  mehr zur Freigabe. Angenommen wird die Ware trotzdem — sie liegt ja da —,
  aber das Los bleibt gesperrt. Annehmen ist nicht Freigeben.
- **„Was steht an" nannte fehlende Nachweise beruhigend.** „Nachweis
  erfasst, Dokument fehlt" stand auch über Losen, für die nie einer erfasst
  wurde. Jetzt zwei Abschnitte: die Sperre oben in Rot, die Audit-Mahnung
  für fehlende Scans darunter.
- **Der DR300-Import brach die Seite ab.** Der Import lief durch, die
  Erfolgsmeldung riss ab — sie reichte das ganze Ergebnis an den Übersetzer
  durch, samt Listen. Die Bescheinigung darüber, was importiert wurde,
  erscheint wieder.
- **Die Wägeskizze erschien bei Motorflugzeugen nie:** Sie verlangte genau
  zwei Auflagen, ein Motorflugzeug steht auf dreien. Es gibt jetzt zwei
  Zeichnungen — Hebel fürs Segelflugblatt, Momente fürs Motorflugblatt —
  und die Maske zeichnet live beim Ausfüllen statt erst nach dem Speichern.

### Neu

- **Nachweis nachtragen:** Am Los lässt sich der Nachweis eintragen — Art,
  Nummer und, wenn vorhanden, der Scan. Ohne diesen Weg wäre die neue
  Sperre eine Sackgasse.
- **Befund melden ist ein Knopf**, keine eigene Seite mehr: Er sitzt im
  Kopf der Befundliste, und das Melderecht öffnet die Liste. Wer meldet,
  sieht damit auch, was aus seiner Meldung wird.
- **LTA/TM-Liste aufräumen:** Ein Knopf entfernt alle Zeilen, auf die kein
  Luftfahrzeug im Bestand passt — mit Vorschau im Bestätigungsdialog.
  Beurteilte Zeilen bleiben (ein Nachweis wird nicht weggeräumt), gelöscht
  wird weich (kommt das Muster später in die Flotte, holt der nächste
  Import die Zeile zurück), und eine leere Flotte räumt gar nichts.
- **Nicht abgeschlossene Wägeberichte sind löschbar.** Eine versehentlich
  angelegte Wägung muss nicht stehen bleiben; abgezeichnete Blätter bleiben
  unantastbar.
- **LTA/TM-Import fragt weniger:** Bei den Herstellerquellen sind Art,
  Gegenstand, Verbindlichkeit und Herausgeber ausgeblendet — die
  Quellendatei bestimmt sie ohnehin. Beim Einfügen einer Liste liest der
  Import die Art aus der NUMMER („TM 300/12" ist eine TM). Und wo noch
  gewählt wird, sind es zwei Paare statt vier Werten: „LTA / AD" und
  „TM / SB" sind jeweils dasselbe auf Deutsch und Englisch.

## [0.1.5] — 2026-08-13

Nachschlag aus dem laufenden Feldtest. **Beim Update beachten:** nichts —
`deploy/update.sh` bzw. `deploy/docker/update.sh` genügt (eine Migration
läuft mit).

### Neu

- **Die Wägeskizze steht jetzt auch in der Maske**, nicht nur auf dem
  Ausdruck: Sobald die zwei Auflagen gespeichert sind, zeichnet der
  Ergebnis-Abschnitt denselben Hebelplan wie das Formularblatt.
- **Abgezeichnete Arbeit endet mit ihrer Freigabe:** Ein Vorgang, dessen
  Karten alle abgezeichnet sind, bleibt offen, bis das CRS erteilt ist —
  von Hand schließen lässt sich nur noch, was keine freizugebende Arbeit
  trägt (irrtümlich eröffnet, nur storniert). Die Freigabe schließt den
  Vorgang wie bisher selbst.

### Behoben

- **C.E.A.P.R.-Import bricht an Sammel-Anweisungen ab.** Bulletins wie
  SB 090702 gelten für die komplette Robin-Palette — die Musteraufzählung
  ist bis 142 Zeichen lang, die Spalte war 96 breit, und der Import starb
  mit „Data too long" für die ganze Quelle. Die Musterspalten sind jetzt
  breit genug (300 Zeichen), mit der echten Liste als Regressionstest.
  Verbreitert statt gekürzt: Hinten abgeschnitten wäre still genau das
  „gilt auch für DR 315" verloren gegangen, das für irgendeinen Verein
  das entscheidende ist.
- **Die Freigabebescheinigung nannte jede Berechtigung „Pilot-Owner"** —
  auch eine Part-66-Lizenz. Das Etikett kommt jetzt aus derselben
  Übersetzung wie überall sonst.
- **Der Drucken-Knopf der Druckansichten war tot** — auf allen vier
  Blättern (Freigabe, LTA-Übersicht, Erfahrungsnachweis, Wäge-/
  Ausrüstungsblatt): Der Inline-Klick-Handler lief unter der
  Sicherheitsrichtlinie (CSP) nie. Der Knopf hängt jetzt an einem
  regulären Skript eigener Herkunft.

## [0.1.4] — 2026-08-13

Runde fünf des Feldtests — der Befundbericht als neuer Meldeweg für jeden
P/O, dazu Sidebar-Auto-Aktualisierung, der optionale C.E.A.P.R.-Zugang und
der Datei-Upload an Wartungsunterlagen. **Beim Update beachten:** Das neue
Recht **„Befunde melden (Befundbericht)"** (Gruppe *Werkstatt — Melden*) den
Rollen zuteilen, die melden dürfen sollen — der Administrator hat es
automatisch. Für die Abzeichnung der Berichte gehört die
**Flugscheinnummer** des P/O in das Feld „Nummer" seiner
Pilot-Owner-Qualifikation (bislang steht dort als Platzhalter das
Kennzeichen). Sonst genügt `deploy/update.sh` bzw.
`deploy/docker/update.sh`; zwei Migrationen laufen mit.

### Neu

- **Befundbericht:** Die Seite „Befund melden" nimmt beliebig viele Punkte
  entgegen — jeder wird ein eigener Befund mit Nummer, immer blockierend
  (herabstufen bleibt eine Feststellung mit Qualifikation). Abgezeichnet
  wird mit der Nummer, die zu Freigaben berechtigt: Part-66-Lizenz, sonst
  die Pilot-Owner-Berechtigung für genau dieses Luftfahrzeug — als
  unveränderliche Kopie am Befund. Unter dem Formular zeigt „Meine offenen
  Meldungen", was aus den eigenen Berichten geworden ist. Das Melderecht
  ist grob verteilbar und trägt sonst nichts aus der Werkstatt.
- **Punkte werden Arbeitskarten:** In der Befundliste lassen sich mehrere
  Befunde anhaken und auf EINE Karte heben — in einen offenen Vorgang oder
  einen implizit neu eröffneten. Die Abzeichnung der Karte erledigt alle
  Punkte darauf; ein schon eingeplanter Punkt lässt sich nicht auf eine
  zweite Karte heben (die erste verlöre ihre Spur).
- **Die Seitenleiste aktualisiert sich selbst:** Die Zähler an den
  Menüpunkten (offene Vorgänge, fällige Prüfungen, Mindestbestände) bauen
  sich alle 30 Sekunden neu — ohne eigenes JavaScript, im
  Hintergrund-Reiter gedrosselt.
- **C.E.A.P.R. mit freiwilligem Zugang:** Wer ein Abonnement hat, trägt es
  unter Hersteller-Zugänge ein; jeder Abruf läuft dann als Abonnent. Ohne
  Zugang liest Aeronance die Liste weiter anonym (nachgemessen: 286
  Einträge) — ein Pflicht-Login hätte Vereinen ohne Abo eine
  funktionierende Quelle weggenommen. Der Unterbau (`login.optional`)
  steht damit für weitere Hersteller bereit.
- **Wartungsunterlagen tragen ihre Datei:** Upload beim Anlegen, Bearbeiten
  und im „Neue Revision"-Dialog; „Öffnen" in der Liste liefert das PDF
  auth-geprüft von der privaten Ablage. Ein Eintrag ohne Datei bleibt
  erlaubt — der Verweis auf den Papierordner.
- **Werkzeug und Verein im Kopf:** Oben links steht „Aeronance -
  Vereinsname", und das Profilmenü zeigt die laufende Fassung mit Link
  auf die Veröffentlichungen.
- **LFZ-Auswahl statt Freitext:** Beim Pilot-Owner-Qualifikationseintrag
  ist das Kennzeichen eine Auswahl aus der Flotte — Tippfehler dort
  kosteten sonst still die Berechtigung.

### Behoben

- **Neue Rechte erreichen bestehende Installationen.** Rechte entstehen im
  Rechte-Abgleich, und den rief bisher kein Update-Schritt auf — ein mit
  einer neuen Fassung eingeführtes Recht existierte nach dem Update
  schlicht nicht. Der Abgleich läuft jetzt als Migration bei jedem Update
  mit (holt auch still fehlende Rechte früherer Fassungen nach) und
  zusätzlich in beiden Update-Skripten.
- **Erneutes Listen im Instandhaltungsprogramm** überschreibt eine von Hand
  gepflegte Nummer der Pilot-Owner-Berechtigung nicht mehr.
- **Abo-Zugangsdaten reisen nicht mehr als Basic-Header** auf
  Listen-Anfragen von Formular-Login-Quellen mit, eine Login-Sitzung folgt
  keinem Hostwechsel, und „Speichern" ohne je gesetztes Passwort wird
  benannt abgewiesen statt mit „gespeichert" quittiert.

## [0.1.3] — 2026-08-12

Runden drei und vier des Feldtests — Werkstatt-Komfort, die Bescheinigung
in der Lebenslaufakte, das Wägeformular mit bebilderter Erklärung und die
Kennblatt-/LTA-Automatik. **Beim Update beachten:** nichts —
`deploy/update.sh` bzw. `deploy/docker/update.sh` genügt (eine Migration
läuft mit: Dokumentverweise am Luftfahrzeug).

### Neu

- **Schnellreparatur:** Ein Dialog legt Arbeitskarte UND Vorgang in einem
  Zug an (Titel geteilt, Vorgang implizit — abgekürzt, nicht übersprungen:
  Nummernkreis, Zählerstände und Freigabeweg laufen unverändert). Dabei
  serverseitig nachgezogen: Eine kritische Karte ohne „woran genau" lehnt
  jetzt auch die Aktion ab, nicht nur das Formular.
- **Freigaben:** „Bescheinigung drucken" als Knopf am Vorgangskopf; jede
  erteilte (und jede korrigierende) Freigabe legt sich selbst als
  Dokumentverweis in die Lebenslaufakte des Luftfahrzeugs — die Akte zeigt
  auf die Bescheinigung, statt eine Zweitdatei zu führen. Die Druckansicht
  öffnet jetzt auch mit Flotten-Leserecht.
- **Arbeitszeit als hh:mm:** Das Dauer-Feld nimmt „90" wie „1:30" und
  schreibt beim Verlassen die eine Anzeige hin; gespeichert wird weiter in
  Minuten. „1,5" wird benannt abgelehnt statt still fehlgedeutet.
- **Neue Muster ziehen ihre Herstellerlisten selbst an.** Beim Anlegen
  eines Musters sucht das LTA/TM-Modul die passenden Quellen — gebunden an
  den Hersteller, bewusst weich verglichen, damit „Robin" die
  C.E.A.P.R.-Quelle trifft. Der Import läuft im Hintergrund; fehlen einer
  Quelle die Zugangsdaten, sagt es die Oberfläche sofort. Ohne Hersteller,
  ohne angemeldete Person oder ohne Import-Recht übernimmt wie bisher der
  Sonntagslauf. Dazu als Test-Härtung: Kein Test spricht mehr mit dem
  Internet (Http::preventStrayRequests).
- **Kennblatt suchen & anlegen:** Muster und Komponentenmuster lassen sich
  jetzt direkt aus einem Suchtreffer anlegen — vorher gab es die Suche nur
  an bestehenden Einträgen, und wer neu anlegte, tippte erst blind.
- **Rotax & Co. bekommen ihr EASA-Kennblatt:** Die EASA-Suche beantwortet
  jetzt auch Komponenten (Kategorie im Pfad: engine-cs-e, propeller-cs-p —
  nachgemessen); für einen 912er steht der EASA-Kandidat mit echtem TCDS
  neben dem LBA-Treffer. Und das Blaue Buch findet Baureihen, die nur im
  Zeilenblock stehen („DR 300" in der Zeile „DR 315") — die Suche läuft
  jetzt über den Blocktext mit.
- **LTA-Arbeitskarte ohne vorhandenen Vorgang:** Der Dialog bietet „Neuen
  Vorgang dafür eröffnen" an (Titel = Anweisung, implizit wie bei der
  Schnellreparatur) — vorbelegt, wenn das Luftfahrzeug keinen offenen
  Vorgang hat.
- **Wägeformular mit bebilderter Erklärung:** Die Massenübersicht trägt
  jetzt Skizze und vorgerechneten Formelkasten (X = G2·b/G + a, mit den
  Zahlen der Wägung und benannter Vorzeichenkonvention) — Struktur nach dem
  klassischen Wägeformular, Zeichnung eigenständig.
- **„Was liegt an" führt hin:** Jede Meldung verlinkt auf die Seite, die
  das Problem behebt — Abgelaufenes direkt ins Vernichten-Formular mit
  vorgewähltem Los; Links erscheinen nur, wenn die Zielseite für diese
  Person aufgeht.

### Behoben

- **LTA/TM-Import:** Die Quellenauswahl ist alphabetisch und durchsuchbar
  (Hand und CSV zuerst); „Wie durchgeführt" erklärt jetzt, was hinein
  gehört; die Hersteller-Zugangsseite sagt, warum dort nur Quellen mit
  Login stehen (CEAPR & Co. liest Aeronance ohne Anmeldung).
- **Kennblattsuche:** „DR300" findet jetzt auch, was die EASA als „DR 300"
  führt — die Suche probiert zusätzlich die Schreibweise mit Leerzeichen
  an der Buchstaben/Ziffern-Grenze (an der echten Bibliothek gemessen).

## [0.1.2] — 2026-08-12

Die zweite Feldtest-Runde, plus die Antwort auf „das Update-Skript hat
nicht funktioniert". **Beim Update beachten:** nichts — `deploy/update.sh`
bzw. `deploy/docker/update.sh` genügt. Wer das Docker-Update zuletzt von
Hand gemacht hat (pull + up): genau dabei fehlte die Migration — diesmal
bitte das Skript.

### Neu

- **Komponentenmuster kennen ihren Bauteiltyp — und der Einbau erbt die
  Laufzeiten.** Feldtest: „Eine Schleppkupplung oder ein Höhenmesser können
  beides sein." Ein Muster kann jetzt optional den Lager-Bauteiltyp benennen
  und Muster-Laufzeiten tragen („24 Monate", „500 Starts"). Die Entnahme aus
  dem Lager in ein Luftfahrzeug katalogisiert den Einbau automatisch und
  kopiert die Laufzeiten an ihn — als Kopie, nie als Verweis: Wer später die
  Vorlage ändert, ändert keinen bestehenden Einbau. Ohne Kopplung bleibt
  alles exakt wie vorher; pro Bauteiltyp ist genau ein Muster zulässig, und
  die Verweise bleiben lose (kein Fremdschlüssel über die Modulgrenze).

### Behoben

- **Das Docker-Update-Skript scheiterte an der Sicherung — und die
  Sicherung an einer Raute im Datenbank-Passwort.** Die Optionsdatei für
  mariadb-dump schrieb das Passwort unquotiert; ab `#` beginnt in einer
  my.cnf ein Kommentar, das Passwort kam abgeschnitten an, „Access denied",
  und update.sh verweigerte ohne Sicherung zu Recht. Die Anwendung selbst
  verband sich fehlerfrei — der Fehler existierte nur im Werkzeugweg.
  Werte stehen jetzt zitiert und maskiert in der Optionsdatei.
- **Das v0.1.1-Image hatte die zip-Extension ohne ihre Bibliothek:** Das
  `autoremove` nach dem Bau riss libzip4 mit, jeder Artisan-Aufruf warnte.
  Die Laufzeit-Bibliotheken stehen jetzt ausdrücklich in der Paketliste,
  und der Image-Bau beweist selbst, dass zip, intl und gd laden — sonst
  bricht er ab.

Aus dem Feldtest (docs/ISSUES.md, 2026-08-12):

- **Eingangsprüfung entkoppelt von Part-66.** Die Annahme des Wareneingangs
  verlangte über die Lagerregel eine Part-66-Lizenz — für eine Papier- und
  Zustandsprüfung nach 145.A.42, die keine Freigabe ist. Neues Recht
  `stock.quarantine.release` (Rechtefrage, keine Lizenzfrage); unbrauchbar
  erklären, der Weg zurück daraus und Ausmustern bleiben qualifizierte
  Feststellungen mit Part-66. Auch die lizenzfreie Annahme trägt den Namen
  dessen, der sie getroffen hat.
- **Lager/Lose:** Der Anlegen-Knopf führte in ein leeres Formular — er ist
  weg, denn Lose entstehen ausschließlich über Buchungen.
- **Werkzeuge:** Es gab keinen Weg, eines anzulegen — Formular und Seite
  existierten, nur der Knopf fehlte. Er steht jetzt da.
- **Befunde:** Die leere Liste erklärt jetzt, dass Befunde aus einem Vorgang
  heraus erfasst werden und dies die flottenweite Übersicht ist; die
  Datumsspalte trug außerdem das falsche Label.
- **Luftfahrzeug-Dokumente:** Der Dialog nahm nur Metadaten an — jetzt kann
  die Datei (PDF/JPG/PNG) mit hochgeladen werden, privat abgelegt und nur
  angemeldet ausgeliefert; die Bezeichnung wird zum Link.
- **Rollen-Editor:** 26 Rechte aus fünf Modulen standen als rohe Schlüssel
  da (die Sprachdatei endete bei der Lager-Ära) — und die Abschnittstitel
  waren wegen der Punkte in Gruppennamen nie aufgelöst worden. Beides
  behoben; ein Test läuft seither über alle Rechte und Gruppen.
- **VF-Abgleich:** Ein scheiternder Kategorien-Abruf (z. B. Update ohne
  Migration) riss den ganzen Lauf ab, bevor die Betriebszeiten gelesen
  wurden. Der Schritt ist jetzt ein Warnhinweis, kein Abbruch.
- **VF-Kopplungen:** „Jetzt lesen" an jeder Zeile holt die Betriebszeiten
  sofort; das Kennzeichen-Feld schlägt die eigene Flotte vor (Vereinsflieger
  bietet keinen Flugzeuglisten-Endpunkt — nachgemessen).

## [0.1.1] — 2026-08-09

Die Lehren des ersten echten Feldtests: 0.1.0 wurde am Tag nach dem Release
auf test.aeronance.de installiert und von vorn bis hinten durchgeklickt.
Alles hier stammt aus dieser einen Sitzung. **Beim Update beachten:** nichts —
`deploy/update.sh` genügt; die enthaltene Migration repariert die
Rechtezuweisung bestehender Installationen von selbst.

### Behoben

- **Der erste Start scheiterte am eigenen Setup-Assistenten.** Mit
  `SESSION_DRIVER=database` (der Vorgabe) las jede Web-Anfrage die
  sessions-Tabelle — die erst durch die Migrationen entsteht, die der
  Assistent ausführen soll, der ohne Session-Tabelle mit einem Fehler 500
  stirbt. Gemessen am ersten Docker-Start; der Webserver- und der LXC-Weg
  hätten denselben Fehler gezeigt. Solange die Installation weder
  abgeschlossen ist noch benutzt aussieht, weichen database-Treiber für
  Session und Cache jetzt auf Dateien aus; danach gilt wieder die
  Konfiguration. Die eine Folge ist gewollt: Nach dem letzten Setup-Schritt
  meldet man sich einmal regulär an.

- **Alle Module aktiv, Oberfläche leer.** Sieben von acht Modulen legten
  ihre Rechte beim Aktivieren ohne Rollenzuweisung an — sie gehörten
  niemandem, auch der admin-Rolle nicht, und der Administrator stand vor
  einer Anwendung, in der es scheinbar nichts gab. Jetzt hängt der Admin
  zentral an jeder Rechte-Deklaration (ein Modul kann es nicht mehr
  vergessen), und eine Migration reicht bestehenden Installationen die
  fehlenden Rechte nach.

- **Der Rollen-Editor konnte keine Rechte vergeben.** Alle Checkbox-Gruppen
  hießen intern gleich; validiert wurde gegen die Optionen der letzten
  Gruppe, ein Haken in jeder anderen scheiterte mit „validation.in".
  Jede Gruppe ist jetzt ein eigenes Feld, gespeichert wird die Vereinigung —
  und ein Test speichert wirklich, statt nur Beschriftungen zu prüfen.

- **Validierungsfehler zeigten rohe Schlüssel** („validation.in" statt eines
  Satzes): Die deutschen Validierungstexte fehlten komplett und
  `APP_FALLBACK_LOCALE=de` schneidet auch den Rückfall auf Englisch ab.
  `lang/de/validation.php` ist jetzt an Bord (übernommen aus
  Laravel-Lang, MIT).

- **Die Modulverwaltung zeigte Status ohne Schalter.** Der Blade-Slot hieß
  „footerActions", die Komponente kennt nur „footer" — einen unbekannten
  Slot verwirft Blade stillschweigend, die Knöpfe wurden nie gerendert.
  Der Rendering-Test prüft seither auf den Knopf, nicht nur auf die Seite.

- **Das Protokoll starb mit Fehler 500,** sobald ein Eintrag ein Array in
  seinen Eigenschaften trug (ein JSON-Cast oder ein Modul-Schaltvorgang
  reicht). Arrays werden jetzt als JSON dargestellt; die Seite baut sich
  auch leer — der Zustand jeder frischen Installation.

- **„Qualifikation eintragen" brach mit einem Klassenfehler ab:** Das
  Filament-Plugin zur Medienbibliothek stand nie in composer.json, und kein
  Test hatte das Formular je geöffnet. Paket nachgezogen, Test öffnet jetzt
  das Formular.

- **Ein Einstellungs-Abschnitt zeigte seinen rohen Schlüssel**
  (`settings.group_help.mail`): Die Beschreibung der Mail-Gruppe fehlte in
  der Sprachdatei. Ergänzt; ein Test geht seither alle Gruppen durch.

- **Das Profilbild war ein „nicht gefunden"-Bild.** Filaments Vorgabe lädt
  Platzhalter von ui-avatars.com — einem Fremddienst, den die eigene CSP zu
  Recht blockt (und der sonst bei jedem Seitenaufbau Mitgliedernamen
  erführe). Initialen entstehen jetzt in der Anwendung selbst; ein eigenes
  Bild lässt sich im Profil hochladen (privat abgelegt, Auslieferung nur
  angemeldet).

- **Text zur Arbeitsstunden-Rückschreibung korrigiert:** „Akzeptiert" sperrt
  den Eintrag drüben für das *Mitglied* — die Abzeichner des Vereins kommen
  weiterhin dran. Der alte Text behauptete vollständige Unveränderlichkeit.

### Neu

- **„Jetzt abgleichen" an jeder VF-Anbindung:** der volle nächtliche
  Abgleich auf Knopfdruck, für die Ersteinrichtung. Er läuft als
  Hintergrund-Job (gemessen: gut eine halbe Minute bei knapp 400
  Mitgliedern) — der Knopf sagt das dazu, und das Ergebnis steht wie immer
  an der Anbindung unter „Letzter Lauf".

- **Die Arbeitsstunden-Kategorie ist eine Auswahlliste.** Der Abgleich liest
  die Kategorien aus Vereinsflieger mit; die Einstellung bietet sie mit
  Namen an, statt nach einer nackten Nummer zu fragen. Drüben abgeschaltete
  Kategorien sind gekennzeichnet, ein konfigurierter Wert außerhalb der
  Liste bleibt sichtbar. Module melden solche Listen über eine neue
  Kern-Schnittstelle an (`SettingOptions`) — der Kern liest weiterhin keine
  Modultabellen.

- **Die Auslagerung fragt nur noch nach dem, was ihr Ziel braucht**
  (Verzeichnis, SFTP oder S3) — und ist gesperrt, solange keine
  Backup-Verschlüsselung eingestellt ist: Der Lauf würde ohnehin
  verweigern; jetzt sagt es die Seite vorher.

### Entfernt

- **Schalter „Instandhaltungspunkte zurückschreiben".** Er hatte keine
  Funktion — Vereinsfliegers einziger Wartungs-Endpunkt ist lesend.
  Funktionslose Schalter taugen nix; kommt der Schreibweg je, kommt der
  Schalter mit der Funktion wieder.

### Für bestehende 0.1.0-Installationen

Wer am Setup-Assistenten hängt: einmalig `php artisan migrate --force`
(im Docker-Kanal per `docker compose exec app …`), dann läuft er. Alles
Übrige erledigt das reguläre Update.

## [0.1.0] — 2026-08-08

Die erste Fassung, für die das Projekt Bruchfreiheit bei Updates verspricht —
und damit die erste, die die selbsttätige Aktualisierung einspielt.

### Neu

- **Benutzerhandbuch** (`HANDBUCH.md`). Alle Module und Abläufe aus Sicht der
  Menschen, die damit arbeiten — vom Setup-Assistenten bis zur Freigabe, samt
  vollständiger Kommandoreferenz für den Betrieb. Es liegt im
  Wurzelverzeichnis und fährt im Release-Paket mit; das README ist im
  Gegenzug kurz geworden und verweist dorthin.

- **Der Setup-Assistent nimmt die MariaDB-Zugangsdaten entgegen.** Solange
  die Verbindung nicht steht, bietet er ein Formular für Server, Port,
  Datenbank, Benutzer und Passwort. Gespeichert wird erst nach erfolgreichem
  Verbindungstest — ein Tippfehler ist eine Fehlermeldung, keine kaputte
  Konfiguration. Die Bedenken gegen ein Webformular, das die eigene
  Konfiguration schreibt, bleiben berechtigt und sind eingezäunt statt
  umgangen: Das Formular existiert nur im uninstallierten Zustand, verlangt
  bei vorhandenem Administrator dessen Anmeldung, und Werte mit
  Steuerzeichen werden abgelehnt statt bereinigt — der Zeilenumbruch im
  Passwortfeld wäre sonst eine eigene Konfigurationszeile. In Docker- und
  LXC-Umgebungen kommen die Daten weiter aus der Umgebung, und der Schritt
  bleibt übersprungen.

- **Unabhängige Kontrolle kritischer Arbeiten** (Arbeitskarten). Eine Karte
  lässt sich beim Anlegen als *kritisch* markieren — gedacht für Arbeiten, bei
  denen ein Fehler unmittelbar gefährlich wird, Steuerungsanschlüsse an erster
  Stelle. Solche Karten werden erst freigegeben, wenn eine **zweite Person, die
  nicht daran gearbeitet hat**, die Arbeit angesehen und das schriftlich
  festgehalten hat.

  Die Lücke, die das schließt, war real: Wer eine Part-66-Lizenz hat, darf seine
  eigene Arbeit abzeichnen — das ist richtig so, hieße bei einer kritischen
  Arbeit aber, dass es gar kein zweites Augenpaar gibt. Wer eine Steuerung
  angeschlossen hat, sieht seinen eigenen Fehler nicht; er bringt beim
  Nachsehen dieselbe Erwartung mit, die ihn beim Anschließen geleitet hat.

  Ausgeschlossen ist **jeder, der Stunden auf der Karte gebucht hat**, nicht nur
  wer sie fertiggemeldet hat. Eine Lizenz ist für die Kontrolle ausdrücklich
  *nicht* nötig: In einem Verein mit einem einzigen Lizenzinhaber ist genau der
  derjenige, der gearbeitet hat — mit Lizenzpflicht fiele die Kontrolle nicht
  strenger aus, sondern aus. Wer eine hat, dessen Nummer wird mitgeschrieben.

  Die Markierung wird beim Anlegen gesetzt und nicht später: Wer sie
  nachträglich entfernen könnte, könnte die Kontrolle nach Bedarf abschalten.

  Auf der Vorgangsseite steht der Stand der Kontrolle — nur bei kritischen
  Karten: ausstehend oder erledigt, wer nachgesehen hat und was festgehalten
  wurde. Ohne diese Ansicht wäre der Nachweis zwar in der Datenbank, aber
  nicht lesbar.

- **Werkzeugausgabe** (Werkzeuge). Wer hat was, seit wann, und ist es zurück.
  Ein Werkzeug mit **überfälliger Kalibrierung wird gar nicht erst
  herausgegeben** — das ist der einzige Zeitpunkt, an dem die Sperre noch etwas
  nützt. Zurückgenommen wird dagegen immer, auch wenn die Frist inzwischen
  abgelaufen ist; sonst blieben Karteileichen in der Liste, und eine solche
  Liste liest niemand mehr.

  Dazu lässt sich eintragen, **woran** gearbeitet wird. Fällt das Werkzeug bei
  der nächsten Kalibrierung durch, ergibt sich daraus, welche Vorgänge
  nachzuprüfen sind: Der Nachprüfzeitraum liefert das Zeitfenster, die
  Ausgabeliste die Vorgänge darin. Nicht handgriffgenau, aber vorgangsgenau —
  und genau das verlangt 145.A.40, keine lückenlose Werkzeugzuordnung.

  Die Vorgangsnummer ist ein freies Feld und kein Verweis auf die
  Arbeitskarten: Das Werkzeugmodul bleibt damit allein lauffähig.

  Am Werkzeug hängt die vollständige Ausgabehistorie als reine
  Nachweis-Tabelle: wer, seit wann, zurück oder noch draußen. Bearbeiten
  lässt sich dort nichts — Ausgabe und Rücknahme laufen über die Aktionen,
  die Historie zeigt nur, was war.

- **Zulassung am Betrieb** (Lager). Lieferanten können eine Zulassungsnummer,
  einen Umfang und ein Ablaufdatum tragen. Ein **Versand zur Instandsetzung an
  einen Betrieb mit abgelaufener Zulassung wird abgelehnt** — was von dort
  zurückkommt, trägt eine Bescheinigung, die nichts wert ist, und das fällt
  sonst erst Jahre später auf, rückwirkend für alles aus dieser Zeit. Bald
  ablaufend ist nur eine Vorwarnung.

  Leeres Ablaufdatum heißt ausdrücklich *unbefristet*, nicht *unbekannt*: Viele
  Zulassungen gelten, bis die Aufsicht sie entzieht. Wer es nicht weiß, trägt
  besser gar keine Nummer ein.

- **Fremdvergabe ans Betriebsverzeichnis** (Flotte). Geht ein Luftfahrzeug
  außer Haus, lässt sich der Betrieb aus dem Lieferantenverzeichnis wählen
  statt frei einzutippen: Name und Zulassungsnummer werden **kopiert**, nicht
  verwiesen — wohin es ging und unter welcher Nummer, muss lesbar bleiben,
  auch wenn der Betrieb später umbenannt wird oder seine Zulassung wechselt.
  Ein Betrieb mit **abgelaufener Zulassung wird abgelehnt** — dieselbe Regel
  wie beim Teileversand zur Instandsetzung, und bei einem ganzen Luftfahrzeug
  wiegt sie schwerer als bei einem Bauteil.

  Die Flotte greift dafür nicht in die Lagertabellen: Eine Kapsel fragt
  zuerst, ob das Lagermodul überhaupt aktiv ist. Ohne Lager gibt es schlicht
  kein Verzeichnis, und die Fremdvergabe läuft wie bisher über Freitext —
  die Flotte steht allein.

- **Schulungsnachweise an der Person** (Kern). Neben Part-66-Lizenz und
  Pilot/Owner-Berechtigung gibt es einen dritten Qualifikationstyp: den
  Schulungsnachweis — Musterschulung (Rotax), Klebeverfahren, Human Factors,
  was immer jemand belegen kann. Bewusst ohne Auswahlliste und ohne
  Unterarten: Jede Liste wäre nach dem dritten Verein unvollständig, und die
  fehlende Zeile hieße dann „das können wir nicht führen".

  Getragen wird er von drei Feldern, die eine Lizenz nicht braucht:
  **Gegenstand** (worum es ging), **Aussteller** (bei wem) und der Urkunde
  selbst als Anhang — abgelegt auf der privaten Dokumentenablage wie Form 1,
  denn sie enthält personenbezogene Daten und hat im Webroot nichts verloren.
  Die Nummer bleibt die Nummer.

  Er verleiht ausdrücklich **keine Befugnis**: Über Berechtigungen entscheidet
  eine Positivliste, in der nur Lizenz und Pilot/Owner vorkommen — ein
  Zertifikat sagt „diese Person wurde geschult", nicht „diese Person darf
  freigeben". Ein eigener Test hält genau das fest. Und er hängt am
  **Menschen**, nicht am Gerät: Wer auf Rotax geschult ist, bleibt es, auch
  wenn der Verein den Motor verkauft.

- **Wartungsunterlagen mit Revisionsstand** (Flotte). Welches Handbuch gilt, in
  welcher Revision, seit wann — für ein Muster oder für ein einzelnes
  Luftfahrzeug. Eine neue Revision **überschreibt nichts**: Sie entsteht als
  neuer Eintrag und löst den alten ab, der stehen bleibt. Nur so bleibt
  beantwortbar, nach welchem Stand im Mai gearbeitet wurde — und das ist die
  Frage, die im Ernstfall zählt.

  Die Arbeitskarte kann festhalten, nach welchem Stand gearbeitet wurde, und
  zwar als Kopie. Ein Verweis würde mitwandern und die Karte rückwirkend
  behaupten lassen, nach dem neuen Stand gearbeitet worden zu sein.

  Zurückziehen (ohne Nachfolger) gibt es getrennt vom Ablösen, mit Pflichtgrund.
  Gelöscht wird nichts.

- **Eingangsprüfung** — neues Modul, der erste der Part-145-Bausteine. Ware, die
  angeliefert wird, ist nicht sofort verwendbar: Das Los wird beim Wareneingang
  gesperrt und erst durch die unterschriebene Prüfung freigegeben.

  Die Checkliste steht fest im Code und ist bewusst nicht editierbar — eine
  Liste, die man kürzen kann, wird an der Bescheinigung gekürzt, weil das der
  Punkt ist, der Arbeit macht, wenn das Papier fehlt. Gefragt wird nur, was zu
  dieser Lieferung passt: Ein Beutel Nieten wird nicht nach einer Form 1
  gefragt, sonst gewöhnt man sich das gedankenlose „Entfällt" an.

  Abgeschlossen wird in einem Durchgang: erst die Liste, dann die Entscheidung.
  Zwei getrennte Knöpfe „Annehmen"/„Zurückweisen" hießen, die Entscheidung vor
  der Prüfung zu treffen. Ein Punkt darf offen bleiben — dann gibt es keine
  Unterschrift, auch nicht bei einer Zurückweisung. „Beanstandet" und „Entfällt"
  brauchen eine Bemerkung. Annehmen trotz Beanstandung ist erlaubt (eine
  gedellte Verpackung um ein gutes Teil kommt vor), aber nur mit Begründung.

  **Die Annahme geht durch die Lagerfreigabe** und erbt damit deren
  Qualifikationspflicht: Wer nicht freigeben darf, gibt auch über die
  Eingangsprüfung nicht frei. **Zurückweisen bewegt nichts** — die Ware bleibt
  gesperrt; was mit ihr geschieht, ist ein eigener Vorgang.

  Geprüft wird auch, was aus der Reparatur zurückkommt: Genau dort trägt die
  Lieferung eine fremde Bescheinigung, und genau die anzusehen ist der Zweck.

  Grenze, ausdrücklich: **Sammelbestand ohne Los** (Normteile) lässt sich nicht
  sperren — die Menge ist sofort verfügbar. Dort ist die Prüfung ein Nachweis,
  keine Sperre. Das ist eine Grenze des Lagermodells, keine Nachlässigkeit hier.

  Ohne das Modul verhält sich der Wareneingang exakt wie bisher; ein eigener
  Test hält das fest.

- **Werkzeuge** — neues Modul, der zweite Part-145-Baustein. Werkzeugbestand mit
  Kalibrierfristen: Inventarnummer, Aufbewahrungsort, Zustand, und für alles,
  wo Genauigkeit zählt, ein Intervall in Monaten. Das Modul steht allein, ohne
  Lager und ohne Flotte.

  **Der eigentliche Punkt ist der Nachprüfzeitraum.** Jede Kalibrierung wird mit
  ihrem Befund erfasst — wie das Werkzeug beim Labor *ankam*, vor einer etwaigen
  Justage. Daraus ergibt sich, welche zurückliegende Arbeit in Frage steht:

  - **Außer Toleranz** — zurück bis zur letzten Messung mit gutem Befund. Ab
    wann das Werkzeug abgewichen ist, weiß niemand; belegt ist nur, dass es beim
    letzten guten Befund stimmte. Das ist der Fall, den die Vorschrift meint.
  - **Zu spät kalibriert, aber in Ordnung** — nur ab dem Fälligkeitsdatum.
    Deutlich schwächer: Das Werkzeug war womöglich einwandfrei, nachgewiesen war
    es nur nicht.

  Beides verlangt eine dokumentierte Bewertung, und beides wäre mit dem nächsten
  Kalibrierschein verschwunden, wenn es nicht im Moment der Erfassung
  festgehalten würde.

  Kalibrierpflichtig und noch nie kalibriert zählt als überfällig — sonst wäre
  ein frisch angelegter Drehmomentschlüssel unbegrenzt gültig, bis ihn jemand
  zum ersten Mal weggibt. Das Fälligkeitsdatum entsteht ausschließlich aus
  einem Kalibrierschein und ist nicht von Hand setzbar. Was auf dem Schein
  steht, schlägt das hinterlegte Intervall.

  **Zu den Intervallen**, nachrecherchiert statt angenommen: Die Vorschrift
  nennt selbst keines. 145.A.40(b) und CAO.A.030 verlangen nur Kalibrierung nach
  einem „officially recognised standard" samt Nachweisen; die AMC verweist auf
  die Herstellervorgabe und spricht dabei von *time period*. Ausschließlich
  zeitbasiert ist es aber nicht: EN ISO 6789:2017 nennt für Drehmomentwerkzeuge
  „12 Monate **oder** 5.000 Betätigungen, was zuerst eintritt".

  Aeronance zählt Betätigungen trotzdem nicht mit — ein Zähler, den niemand
  pflegt, zeigt „1.200 von 5.000" und ist eine Lüge mit Nachkommastelle.
  Stattdessen gibt es am Werkzeug das Feld **Grundlage des Intervalls**, in dem
  die Norm oder Herstellervorgabe steht. Wer die zweite Grenze erreichen könnte,
  setzt das Zeitintervall entsprechend kürzer.

  Offen bleibt die Verbindung zu den Arbeitskarten: Das Modul weiß nicht, wer
  womit gearbeitet hat. Der Zeitraum, in dem nachzusehen ist, steht aber fest —
  und das ist der Teil, der auch ohne diese Erfassung trägt.

- **Bestellungen** im Lagermodul — Lieferverfolgung, keine Warenwirtschaft. Es
  geht um eine einzige Frage: *Kommt das eigentlich noch?* Ein Lieferant, der
  sich nicht meldet, fällt sonst erst auf, wenn das Luftfahrzeug schon steht.

  Bestellt wird weiterhin außerhalb — am Telefon, per Mail, im Webshop.
  Eingetragen wird hinterher, was man bestellt hat: Bestellnummer des
  Lieferanten, Lieferant, voraussichtliches Lieferdatum, Teile mit Mengen.
  Keine Preise, keine Rechnungen, keine Konditionen.

  **Die Erinnerung gibt es doppelt.** Eine Mail (Vorgabe: 07:30, danach frühestens
  alle drei Tage wieder — täglich dasselbe wischt jeder weg, ohne es zu lesen)
  *und* ein Hinweis auf der Startseite. Der Hinweis ist kein Beiwerk: Die Mail
  hängt an einem Mailserver, der bei einer frischen Installation gar nicht
  eingerichtet ist, dessen Zugang abläuft, dessen Postfach vollläuft. Dann fiele
  still genau das aus, wofür das Ganze gebaut wurde.

  Das **Lieferdatum ist vorbelegt** mit Bestelldatum plus einer Woche, weil viele
  Lieferanten gar keins zusagen — ohne Vorbelegung gäbe es ausgerechnet bei
  denen keine Erinnerung. Überschreibbar; wer das Feld leert, wird an diese
  Bestellung nicht erinnert.

  **Eingebucht wird je Position** über den gewohnten Wareneingang, weil jede
  gelieferte Charge ihr eigenes Form 1 hat. Form-1-Pflicht, Seriennummernregel,
  Losbildung und Etikett gelten unverändert weiter. Teillieferungen sind der
  Normalfall; erledigt ist eine Bestellung erst, wenn jede Position vollständig
  angekommen ist. Storniert wird mit Pflichtgrund — sonst ist die Bestellung in
  einem halben Jahr eine offene Frage — und nur, solange noch etwas aussteht:
  Eine vollständig gelieferte Bestellung ist erledigt, dort gibt es nichts mehr
  abzubrechen. Bereits Geliefertes bleibt beim Storno eingebucht, es liegt ja
  im Regal.

  Bestellte Mengen sind **kein Bestand**. Sie liegen nicht im Regal und tauchen
  in keiner Bestandsauswertung auf; erst das Einbuchen erzeugt eine Bewegung.

  Zwei neue Einstellungen, beide optional: `AERONANCE_ORDER_LEAD_DAYS` (Vorgabe
  7) und `AERONANCE_ORDER_REMINDER_DAYS` (Vorgabe 3).

- **Veröffentlichen ist ein eigener Schritt** (`deploy/publish.sh`). Die
  automatische Spiegelung nach GitHub ist abgeschaltet: Die
  Aktualisierungsprüfung liest die Tags des öffentlichen Repositorys, und
  gespiegelt wäre jeder interne Tag sofort ein Update für jede laufende
  Installation — auch der, mit dem nur etwas ausprobiert werden sollte.

  Jetzt gilt: intern taggen, bauen, prüfen — und danach bewusst
  veröffentlichen. Die öffentliche Historie ist dadurch eine Release-Historie,
  je Fassung ein signierter Commit mit der Changelog-Passage als Nachricht.

- **Selbsttätige Aktualisierung**, in allen drei Auslieferungswegen und ab Werk
  aus. `AERONANCE_AUTO_UPDATE=true` plus ein mitgelieferter systemd-Timer; im
  LXC ist der Timer bereits installiert und wartet nur auf die Zeile in der
  `.env`.

  **Automatisiert wird der Ablauf, nicht der Weg daran vorbei.** Der Timer ruft
  nicht `git pull` oder `docker pull`, sondern das reguläre Update-Skript — mit
  Signaturprüfung, Sicherung, Wartungsmodus und Migration. Genau darin liegt der
  Unterschied zu Watchtower und Ähnlichem, das Images zieht und alle drei
  Schritte überspringt.

  Nachts um halb vier mit Zufallsverzögerung, damit nicht jede Installation zur
  selben Minute dieselbe API fragt. Während 0.0.x weigert es sich: Vor 0.1.0
  sind Brüche zwischen zwei Fassungen erlaubt, und die will niemand nachts um
  halb vier bekommen.

  Für Docker liegt der Timer auf dem **Wirt**. Ein Updater-Container bräuchte
  den Docker-Socket — wer den hat, ist auf dem Wirt root, und für eine
  Anwendung, die ins offene Internet zeigt, ist das der falsche Handel.

- **Update-Weg für Docker** (`deploy/docker/update.sh`). Bisher gab es ihn nur
  für den eigenen Server; im Docker-Betrieb blieb „Tag ändern, `pull`, `up -d`" —
  und das lässt den Schritt aus, auf den es ankommt: **die Migration**. Der
  Entrypoint spiegelt nur `public/`, er migriert nicht; nach einem reinen
  Image-Wechsel lief die neue Anwendung gegen das alte Schema.

  Dieselbe Reihenfolge wie beim Webserver-Pack — Sicherung, Wartungsmodus,
  Migration, Caches —, mit einem Unterschied: Das Image wird **zuerst** geholt.
  Bricht das ab, weil der Tag vertippt oder die Registry nicht erreichbar ist,
  läuft die alte Installation unverändert weiter, und niemand musste dafür eine
  Sicherung anfertigen.

  Ausdrücklich **kein** Watchtower und kein `latest`: Ein Dienst, der Images von
  selbst zieht, aktualisiert ohne Sicherung, ohne Wartungsmodus und ohne
  Migration. Automatisch ist die Benachrichtigung (`aeronance:update-check`),
  nicht die Ausführung.

- **Update-Weg für Tarball-Installationen** (`deploy/update.sh`). Bisher setzte
  das Update-Skript eine Git-Installation voraus — die es im Webserver-Pack und
  im LXC nie gab: Beide entstehen aus dem Release-Tarball, ohne `.git`. Und
  selbst ein Checkout hätte nicht geholfen, denn `vendor/` und die gebauten
  Assets liegen bewusst nicht im Repo, sondern nur im Artefakt. **Diese
  Installationen konnten sich schlicht nicht aktualisieren.**

  Jetzt erkennt das Skript die Installationsart selbst. Ohne `.git` lädt es den
  Release-Tarball aus den GitHub-Releases, prüft dessen **abgetrennte Signatur**
  gegen den mitgelieferten Schlüsselbund — dieselbe Aussage, die im Git-Modus
  `git tag -v` trifft — und spielt ihn per rsync ein; `.env`, `storage/` und
  der `storage`-Link bleiben unberührt. Composer und Node sind weiterhin nicht
  nötig. Wer aus einem eigenen Spiegel installiert, setzt `AERONANCE_RELEASE_URL`.

  Dazu gehört die andere Hälfte: `deploy/publish.sh` signiert das pack-Artefakt
  der CI und lädt es als GitHub-Release hoch. Ein Tag ohne Artefakt hieße für
  diesen Kanal „nichts zu holen" — das Skript sagt das jetzt deutlich, statt
  still fertig zu sein.

### Behoben

- **Der Notzugang läuft jetzt wirklich ab.** `--hours` schrieb ein „gültig
  bis" in den Datensatz, aber nichts las es je wieder: Der Zugang blieb
  bestehen, bis jemand von Hand widerrief — eine Frist, die nicht abläuft,
  ist ein Versprechen, das keiner hält. Der Scheduler zieht abgelaufene
  Gewährungen jetzt alle fünf Minuten zurück; überlappende Gewährungen
  nehmen sich dabei nicht gegenseitig den Boden weg. Der Handweg
  (`aeronance:break-glass-revoke`) bleibt der verlässliche — auch für den
  Fall, dass ausgerechnet der Scheduler zu den kaputten Dingen gehört.

- **`deploy/update.sh` endete bei jedem Lauf mit Exitcode 1.** Die Aufräum-Trap
  referenzierte Variablen einer älteren Fassung, die längst niemand mehr setzte
  — mit `set -u` starb daran auch das erfolgreiche Update, ganz am Ende.
  systemd hätte jede gelungene automatische Aktualisierung als Fehlschlag
  gemeldet, und im LXC wäre der Update-Pfad hörbar gescheitert.

- **Das Docker-Image konnte weder sichern noch aktualisiert werden.** Es fehlte
  `mariadb-client` — `aeronance:backup` verlangt `mariadb-dump` und brach ab,
  womit die nächtliche Sicherung des Schedulers strukturell fehlschlug und
  `deploy/docker/update.sh` zwingend am Sicherungs-Schritt scheiterte.
  Ausgerechnet der CHANGELOG nannte das Paket längst als Voraussetzung.

- **Mengenprüfungen im Lager greifen jetzt unter Zeilensperre.** Verfügbarkeit
  wurde auf ungesperrten Daten geprüft und erst danach gebucht — zwei parallele
  Ausgaben desselben Loses lasen beide „5 übrig", beide bestanden, das Los
  stand negativ; im append-only-Journal ist das nur per Gegenbuchung
  reparabel. Betroffen waren Ausgabe, Vernichtung, Zustandswechsel und der
  Rückläufer aus der Reparatur (ein Doppelklick buchte dort dieselbe Sendung
  doppelt ein). Alle Pfade sperren jetzt die betroffene Zeile und prüfen
  innerhalb der Transaktion erneut — das Muster stand mit Begründung schon in
  der Freigabe, nur das Lager hatte es nicht. Ein Storno derselben Buchung ist
  zusätzlich per Unique-Index unmöglich gemacht, wie bei den
  Freigabekorrekturen.

- **Eine rückdatierte Inventur rechnet gegen den Stand des Zähltags.** Bisher
  rechnete sie gegen den heutigen: Samstag gezählt, Sonntag ausgegeben, Montag
  erfasst — und der Buchbestand lag um genau die Sonntags-Ausgabe zu hoch.
  `stockAsOf()` gab es längst und dokumentierte selbst, wofür; benutzt hat es
  die Inventur nur nicht. Ein Zähldatum in der Zukunft wird abgelehnt.

- **Ein nie abgelesener Zähler ist jetzt „unbekannt", nicht null.** Die
  tröstliche 0,0 wurde in die Einbau-Schnappschüsse eingefroren: Wer eine
  Komponente vor der ersten Zählerablesung einbaute und danach den echten
  Stand eintrug (etwa 3000 h), dessen Komponente bekam die gesamte Lebenszeit
  des Luftfahrzeugs als Laufzeit geschenkt. Jetzt gilt: Nie abgelesen und
  weiterhin ohne Ablesung — die Betriebszeiten aus den Papieren sind die ganze
  Antwort. Abgelesen ohne Einbau-Basis — die Differenz ist unbeantwortbar, und
  genau das steht dann da.

- **Das Backup-Aufräumen sah verschlüsselte Sicherungen nicht.** Mit
  eingeschalteter Verschlüsselung heißen die Dateien `.enc`, und beide
  Suchmuster griffen ins Leere — `--keep` löschte nie etwas, das Verzeichnis
  wuchs täglich, bis die Platte voll war; und da ein Auslagerungsziel die
  Verschlüsselung erzwingt, war das der Regelfall, nicht die Ausnahme.
  Außerdem wird das Dokumenten-Archiv jetzt beim Schreiben geprüft — eine
  volle Platte meldete sonst ein kaputtes Archiv als Erfolg.

- **Wer Benutzer verwalten darf, bearbeitet kein Konto mehr, das mehr darf als
  er selbst.** Das Formular enthält ein Passwortfeld — die Berechtigung allein
  hätte gereicht, einem Administrator ein neues Passwort zu setzen und sich als
  er anzumelden. Die Regel ist eine Teilmengenprüfung der Rechte, keine
  Rollenliste; Gleichstand bleibt erlaubt, sonst könnte kein Administrator den
  anderen pflegen.

- **Eine Karte aus der LTA-Liste anzulegen verlangt jetzt auch das
  Arbeitskarten-Recht.** Bisher genügte das Leserecht der LTA-Liste — eine
  zweite Tür mit niedrigerer Schwelle in denselben Vorgang, dessen reguläre
  Tür `CARDS_WORK` verlangt.

- **Arbeitskarten schreiben Audit-Trail.** Das Modul war das einzige fachliche
  ohne activitylog — ausgerechnet das mit den Wartungsakten. Die
  Unveränderlichkeit nach der Freigabe war erzwungen; was fehlte, war die Spur
  für die editierbare Phase davor: wer einen Vorgang umbenannt, geschlossen,
  eine Stunde geändert hat.

- **Ein deaktiviertes Modul redet nicht mehr im Lufttüchtigkeits-Check mit.**
  Die Beiträge der Arbeitskarten und der LTA-Liste wurden bedingungslos
  registriert — mit dem Kommentar, jeder Beitrag frage selbst beim
  ModuleManager nach. Das tat keiner: Ein bewusst abgeschaltetes Modul meldete
  weiter offene Punkte und hätte eine Freigabe blockieren können.

- **`TRUSTED_PROXIES` wird jetzt gelesen.** Die Zeile stand in der
  Docker-Vorlage als Zusage — und nichts im Code kannte sie. Hinter einem
  vertrauten Proxy stimmen damit Client-IP (Audit-Log, Login-Drossel) und
  Schema (HSTS, signierte URLs) auch app-seitig; der Docker-nginx wertet
  `X-Forwarded-Proto` außerdem nur noch bei `https` als verschlüsselt, statt
  jeden nicht leeren Wert.

- **`league/commonmark` auf 2.9.0.** Sechs neu veröffentlichte Meldungen, vier
  davon *high* — Denial of Service beim Parsen präparierter Markdown-Eingaben,
  dazu ein Filter-Bypass über eingebettete Steuerzeichen. Das Paket steckt
  hinter Laravels Markdown-Mails und damit hinter den Bestell-Erinnerungen;
  angreifbar wäre es nur mit kontrollierter Eingabe.

### Beim Update beachten

Damit die Erinnerungsmail rausgeht, muss der Laravel-Scheduler laufen — im
Webserver-Pack und im LXC über die mitgelieferte systemd-Unit, im Docker-Setup
über den `scheduler`-Dienst. Läuft er nicht, bleibt der Hinweis auf der
Startseite trotzdem.

Bestehende Tarball-Installationen (Webserver-Pack, LXC) brauchen für den neuen
Update-Weg einmalig `rsync` (`apt-get install rsync`); neue LXC-Installationen
bringen es mit. Der Weg lädt aus den GitHub-Releases — diese Fassung selbst
wird noch von Hand eingespielt, ab ihr trägt der Mechanismus.

Mit 0.1.0 endet der Vorabstand: Die selbsttätige Aktualisierung
(`AERONANCE_AUTO_UPDATE=true`) weigert sich ab jetzt nicht mehr und spielt
künftige Fassungen nachts ein — mit Sicherung, Wartungsmodus und Migration.

## [0.0.2] — 2026-08-06

### Neu

- **Testmail-Knopf** neben den Mail-Einstellungen. Er prüft, was gerade im
  Formular steht — nicht was gespeichert ist. Sonst müsste man erst speichern,
  um zu erfahren, ob der Zugang stimmt, und hätte im Fehlerfall einen kaputten
  Zugang in der Datenbank. Die Antwort des Mailservers wird durchgereicht statt
  zu „Versand fehlgeschlagen" verkürzt.

  Ein leeres Passwortfeld heißt auch hier „nicht ändern": Geheimnisse werden
  nie zurückgezeigt, das Feld ist nach jedem Seitenaufruf leer.

  `php artisan aeronance:mail-test <adresse>` gibt es weiterhin und tut
  dasselbe — wer die Einstellungen in der Oberfläche pflegt, hat in dem Moment
  aber keine Konsole.

### Beim Update beachten

Nichts. `deploy/update.sh` genügt.

## [0.0.1] — 2026-08-05

**Die erste getaggte Fassung.** Vorabstand: Bis alles steht, läuft die Zählung
in 0.0.x weiter, und Brüche sind bis 0.1.0 jederzeit möglich.

### Was drin ist

- **Kern** — Benutzer, Rollen und Rechte, Qualifikationen (Part-66),
  Audit-Trail, Dokumentenablage, Modulverwaltung, Erst-Setup-Assistent,
  Sicherung und getestete Wiederherstellung, Zwei-Faktor-Anmeldung,
  Einladungs- und Passwort-Mails.
- **Lager** — Bauteiltypen, Lagerorte, Lose mit Form-1-Bezug, Ein- und
  Ausbuchung, Sperrzettel, Inventur mit Zählliste, Reparaturversand,
  Losaufkleber und Regalschilder mit QR-Code.
- **Flotte** — Luftfahrzeuge, Halter, Komponenten, Betriebszeiten,
  Wägungen, Fälligkeiten.
- **Arbeitskarten** — Vorgänge, Karten, Befunde, Arbeitszeiten,
  Teileentnahme aus dem Lager, Freigabebescheinigung.
- **Part-66** — Erfahrungslogbuch als Auswertung der Arbeitskarten,
  Recency, Druckansicht.
- **LTA/TM** — Lufttüchtigkeitsanweisungen und Technische Mitteilungen aus
  48 Quellen, Zuordnung je Luftfahrzeug.
- **Vereinsflieger** — Mitglieder- und Funktionsabgleich, Flugzeugzeiten,
  Übertragung von Arbeitsstunden. Mehrere Vereine gleichzeitig möglich.
- **Scanner** — QR-Codes auf Losaufklebern und Regalschildern, gelesen mit
  der Kamera in der Anwendung selbst. An der Teilentnahme setzt ein Scan
  Bauteiltyp und Los in einem Schritt; in der Inventur wählt das
  Regalschild den Ort.
- **Aktualisierungsprüfung** — schaut einmal täglich nach neuen Fassungen
  und meldet sie auf der Startseite. Abschaltbar mit
  `AERONANCE_UPDATE_CHECK=false`; dann geht keine Anfrage nach außen.

### Beim Installieren beachten

- **`poppler-utils` ist Voraussetzung.** Aeronance liest die Kennblatt-Listen
  des LBA mit `pdftotext`. Fehlt das Paket, meldet jede Kennblatt-Suche lautlos
  „kein Treffer". `php artisan aeronance:requirements` sagt, was fehlt.
  Debian/Ubuntu: `apt install poppler-utils`
- **PHP 8.4 ist Mindestversion**, nicht 8.3.
- **`mariadb-client` ist Voraussetzung** für `php artisan aeronance:backup` —
  und damit für `deploy/update.sh`, das ohne Sicherung nicht aktualisiert.
- **HTTPS ist Voraussetzung**, nicht Empfehlung: Der Kamera-Scanner läuft nur
  im sicheren Kontext, und das Sitzungscookie wird in `production` als
  `Secure` gesetzt.
- **Die `.env` muss vollständig sein.** `APP_LOCALE`, `APP_FALLBACK_LOCALE`
  und `DB_CONNECTION` gehören hinein — `.env.example` ist die Vorlage. Die
  Rückfallwerte im Code sind zwar seit dieser Fassung richtig gesetzt, aber
  eine Installation, die von der Vorlage abweicht, weiß am besten selbst, was
  sie will.
