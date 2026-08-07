# Änderungen

Das Format folgt [Keep a Changelog](https://keepachangelog.com/de/1.1.0/),
die Versionierung [SemVer](https://semver.org/lang/de/).

Jede Fassung nennt unter **Beim Update beachten** ausdrücklich, was ein Verein
tun muss, bevor er sie einspielt. Steht dort nichts, reicht `deploy/update.sh`.

## [Unveröffentlicht]

### Neu

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

- **Zulassung am Betrieb** (Lager). Lieferanten können eine Zulassungsnummer,
  einen Umfang und ein Ablaufdatum tragen. Ein **Versand zur Instandsetzung an
  einen Betrieb mit abgelaufener Zulassung wird abgelehnt** — was von dort
  zurückkommt, trägt eine Bescheinigung, die nichts wert ist, und das fällt
  sonst erst Jahre später auf, rückwirkend für alles aus dieser Zeit. Bald
  ablaufend ist nur eine Vorwarnung.

  Leeres Ablaufdatum heißt ausdrücklich *unbefristet*, nicht *unbekannt*: Viele
  Zulassungen gelten, bis die Aufsicht sie entzieht. Wer es nicht weiß, trägt
  besser gar keine Nummer ein.

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
  angekommen ist. Stornieren geht jederzeit — mit Pflichtgrund, sonst ist die
  Bestellung in einem halben Jahr eine offene Frage. Bereits Geliefertes bleibt
  dabei eingebucht, es liegt ja im Regal.

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

### Behoben

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
