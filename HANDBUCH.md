# Aeronance — Benutzerhandbuch

Dieses Handbuch richtet sich an die Menschen, die mit Aeronance arbeiten:
Mitglieder, Werkstattleiter, freigabeberechtigtes Personal und die Person, die
die Installation betreibt. Es beschreibt, was die Anwendung tut, wie die
Abläufe gedacht sind und warum sie manches verweigern. Die Kommandoreferenz am
Ende versammelt sämtliche Terminal-Befehle.

Für Entwickler und Beitragende: siehe `README.md` und `CONTRIBUTING.md`.

---

## Inhalt

1. [Was Aeronance ist](#1-was-aeronance-ist)
2. [Installation](#2-installation)
3. [Der Erst-Setup-Assistent](#3-der-erst-setup-assistent)
4. [Anmeldung, Profil, Zwei-Faktor](#4-anmeldung-profil-zwei-faktor)
5. [Benutzer, Rollen, Qualifikationen](#5-benutzer-rollen-qualifikationen)
6. [Einstellungen, Module, Protokoll](#6-einstellungen-module-protokoll)
7. [Lager](#7-lager)
8. [Eingangsprüfung](#8-eingangsprüfung)
9. [Flotte](#9-flotte)
10. [LTA / TM](#10-lta--tm)
11. [Arbeitskarten und Freigabe](#11-arbeitskarten-und-freigabe)
12. [Werkzeuge](#12-werkzeuge)
13. [Erfahrungslogbuch (Part-66)](#13-erfahrungslogbuch-part-66)
14. [Vereinsflieger-Anbindung](#14-vereinsflieger-anbindung)
15. [Betrieb: Sicherung, Updates, Notzugang](#15-betrieb-sicherung-updates-notzugang)
16. [Kommandoreferenz](#16-kommandoreferenz)

---

## 1. Was Aeronance ist

Aeronance ist ein selbstgehostetes Werkstatt- und Lagerverwaltungssystem für
Luftsportvereine — vom kleinen Verein, der nur sein Lager führen will, bis zum
kleinen Betrieb mit Part-145-Bausteinen. Es besteht aus einem Kern
(Benutzer, Rollen, Einstellungen, Protokoll) und einzeln aktivierbaren
Modulen:

| Modul | Zweck |
|---|---|
| **Lager** | Bauteiltypen, Lose, Bestände, Ein-/Ausbuchung, Nachweise (Form 1), Bestellungen |
| **Flotte** | Luftfahrzeuge, Zählerstände, Komponenten, Laufzeitgrenzen, Wartungsunterlagen, Wägung, Fremdvergabe |
| **LTA / TM** | Lufttüchtigkeitsanweisungen und Technische Mitteilungen, herstellerweise aktualisiert, je Luftfahrzeug beurteilt |
| **Arbeitskarten** | Vorgänge, Karten, Arbeitszeiten, Befunde und die Freigabe (CRS) |
| **Eingangsprüfung** | Part-145-Baustein: angelieferte Ware bleibt gesperrt, bis die Prüfung unterschrieben ist |
| **Werkzeuge** | Part-145-Baustein: Werkzeugbestand mit Kalibrierfristen, Nachprüfzeiträumen und Ausgabe |
| **Erfahrungslogbuch** | Part-66-Erfahrungsnachweis, vollständig aus den Arbeitskarten abgeleitet |
| **Vereinsflieger** | Mitgliederabgleich, Betriebszeiten und Arbeitsstunden-Rückschreibung |

Module lassen sich jederzeit aktivieren; Abhängigkeiten werden erklärt und
automatisch mitaktiviert. **Deaktivieren blendet aus und stoppt
Hintergrundläufe — es löscht keine Daten.**

### Grundsätze, die überall gelten

- **Nichts wird überschrieben.** Bestände sind Summen von Bewegungen,
  Zählerstände werden korrigiert statt geändert, Korrekturen sind neue
  Einträge, die auf das Original verweisen. Deshalb ist jeder vergangene
  Stand exakt rekonstruierbar.
- **Drei Unterschriften an der Arbeit:** *fertig gemeldet* (die Arbeit ist
  getan) → *abgezeichnet* (sie war in Ordnung) → *Freigabe/CRS* (das
  Luftfahrzeug darf fliegen). Bei kritischen Arbeiten schiebt sich die
  unabhängige Kontrolle zwischen die ersten beiden.
- **Berechtigung und Qualifikation sind zwei Stufen.** Eine Rolle sagt, was
  jemand *bedienen* darf. Feststellungen — freigeben, abzeichnen, Zustand
  eines Teils erklären, eine LTA beurteilen — verlangen zusätzlich eine
  gültige Qualifikation (Part-66-Lizenz oder, wo zulässig, eine
  Pilot-Owner-Berechtigung). Der verwendete Nachweis wird im Moment der
  Feststellung unveränderlich mitgeschrieben.
- **Namen werden kopiert, nicht verwiesen.** Wer etwas unterschrieben hat,
  bleibt lesbar — auch wenn das Konto später umbenannt, deaktiviert oder
  pseudonymisiert wird.
- **Nach der Freigabe ist eingefroren.** Vorgänge mit erteilter CRS lassen
  sich nicht mehr ändern; Korrekturen sind neue, referenzierende Einträge.

---

## 2. Installation

Aeronance wird über drei Wege ausgeliefert. Alle drei nutzen dasselbe
Release-Artefakt; die Wahl ist eine Frage der eigenen Infrastruktur.

**Voraussetzungen (alle Wege):** PHP 8.4+ mit `pdo_mysql`, `intl`, `gd`,
`zip`, `bcmath`, `fileinfo`; **MariaDB 10.11+** (MySQL wird ausdrücklich nicht
unterstützt); `poppler-utils` (für die Kennblatt-Suche); `mariadb-client`
(für Sicherungen); bei Tarball-Installationen `rsync` (für Updates). Ob alles
da ist, sagt:

```
php artisan aeronance:requirements
```

### Weg 1: Eigener Server (Webserver-Pack)

Der Release-Tarball enthält `vendor/` und die gebauten Assets — auf dem
Zielsystem sind weder Composer noch Node nötig.

```
# 1. Tarball auspacken
tar -xzf aeronance-vX.Y.Z.tar.gz
mv aeronance-vX.Y.Z /var/www/aeronance
cd /var/www/aeronance

# 2. Konfiguration anlegen
cp .env.example .env
php artisan key:generate

# 3. Rechte
chown -R www-data:www-data storage bootstrap/cache

# 4. Webserver einrichten (Vorlagen: deploy/nginx.conf, deploy/apache.conf)
#    Der Document-Root zeigt auf public/ — nie eine Ebene höher.

# 5. Dienste
install -m 644 deploy/aeronance-worker.service deploy/aeronance-scheduler.service /etc/systemd/system/
systemctl daemon-reload
systemctl enable --now aeronance-worker.service aeronance-scheduler.service
```

Danach die Adresse im Browser aufrufen — der Setup-Assistent übernimmt
(Kapitel 3).

### Weg 2: Docker

`deploy/docker/` enthält `docker-compose.yml`, Dockerfile und die
Umgebungsvorlage:

```
cd deploy/docker
cp .env.docker.example .env      # ausfüllen: Passwörter, APP_URL, TRUSTED_PROXIES
                                 # und AERONANCE_IMAGE (getaggtes Release, kein latest)
docker compose up -d
```

Der Stack bringt App (PHP-FPM), nginx, MariaDB, Worker und Scheduler mit.
TLS terminiert der Reverse Proxy davor (Traefik, Caddy, nginx-proxy-manager);
`TRUSTED_PROXIES` in der `.env` sorgt dafür, dass Client-Adressen und
HTTPS-Erkennung stimmen.

### Weg 3: Proxmox LXC

Das Skript nach den Konventionen der Proxmox-VE-Community-Scripts erstellt den
Container, installiert nginx, PHP-FPM und MariaDB, zieht das Release und
übergibt an den Setup-Assistenten. Die Adresse des Release-Tarballs kommt aus
der Umgebungsvariable `AERONANCE_RELEASE_URL`.

---

## 3. Der Erst-Setup-Assistent

Unter `/setup` erreichbar, solange die Installation nicht abgeschlossen ist.
Er führt einmalig durch:

1. **Datenbank** — Solange die Verbindung nicht steht, bietet der Assistent
   ein Formular für die MariaDB-Zugangsdaten (Server, Port, Datenbank,
   Benutzer, Passwort). Gespeichert wird **erst nach erfolgreichem
   Verbindungstest** — ein Tippfehler ist eine Fehlermeldung, keine kaputte
   Konfiguration; geschrieben wird in die `.env`. Ist der Server keine
   MariaDB, wird das klar benannt — auch MySQL wird abgelehnt. In
   Docker-Umgebungen haben die dort gesetzten Variablen Vorrang.
2. **Tabellen anlegen** — Migrationen für *alle* mitgelieferten Module, auch
   die nicht ausgewählten. Ein Modul später zu aktivieren ist dann ein
   Schalter, kein Wartungsfenster.
3. **Administratorkonto** — Name, E-Mail, Passwort (mindestens 12 Zeichen,
   Buchstaben und Ziffern). Existiert bereits ein Administrator, wird der
   Schritt verweigert.
4. **Organisation** — Vereinsname und Zeitzone. Beides erscheint auf jedem
   Ausdruck; die Zeitzone entscheidet, welches Datum ein Sperrzettel trägt.
5. **Modulauswahl** — Abhängigkeiten werden erklärt und mitaktiviert.
6. **Abschließen** — danach verriegelt sich der Assistent dauerhaft
   (Marker-Datei `storage/installed`). Die Setup-Routen sind ab dann nicht
   mehr erreichbar; und sobald ein Administrator existiert, dürfte ohnehin
   nur noch dieser (angemeldet) Setup-Schritte ausführen.

In Docker- und LXC-Installationen sind Datenbank-Schritte bereits aus der
Umgebung vorkonfiguriert und werden übersprungen.

---

## 4. Anmeldung, Profil, Zwei-Faktor

- Die Anwendung läuft unter `/verwaltung`; die Startseite leitet dorthin.
- **Zwei-Faktor-Anmeldung** (Authenticator-App, TOTP) ist ein Angebot, kein
  Zwang: Jede Person schaltet sie im eigenen Profil ein und erhält
  Wiederherstellungscodes. In der App steht die E-Mail-Adresse — ein Verein
  hat mehrere Müllers.
- **Passwort ändern** geht im Profil. Bei Konten aus einem Identity-Provider
  (z. B. Vereinsflieger) sind Name und E-Mail gesperrt — der nächtliche
  Abgleich würde sie ohnehin zurücksetzen; das Passwort gehört dagegen der
  Person und bleibt änderbar.
- **Profilbild:** im Profil hochladbar (JPG/PNG/WebP, bis 2 MB), sichtbar
  für angemeldete Mitglieder. Ohne Bild stehen die Initialen — erzeugt in
  der Anwendung selbst, kein externer Avatar-Dienst wird angefragt.
- **„Passwort vergessen"** erscheint nur, wenn Mailversand eingerichtet ist.
  Die Antwort ist immer dieselbe, egal ob die Adresse bekannt ist — sonst
  ließe sich per Formular abfragen, wer Mitglied ist.
- Erfolgreiche und fehlgeschlagene Anmeldungen stehen im Protokoll (mit
  Kennung und IP-Adresse, nie mit dem Passwort).

---

## 5. Benutzer, Rollen, Qualifikationen

Navigation: Gruppe **Personen**.

### 5.1 Benutzer

Sehen: Recht `core.users.view` · Verwalten: `core.users.manage`.

- **Konto anlegen:** Name, E-Mail (eindeutig), Passwort (mind. 12 Zeichen,
  Buchstaben + Ziffern), Schalter „Aktiv". Ein deaktiviertes Konto kann sich
  weder anmelden noch sonst etwas tun — die Rechte bleiben erhalten, wirken
  aber nicht.
- **Konten werden nie gelöscht**, nur deaktiviert: Der Name kann in einer
  Freigabe stehen und muss Jahre später lesbar bleiben.
- **Provider-Konten** (aus Vereinsflieger): Name, E-Mail und „Aktiv" führt
  der nächtliche Abgleich — die Felder sind serverseitig gesperrt, das Feld
  „Herkunft" erklärt warum. Das Passwortfeld gibt es dort nicht: Ein Passwort
  entsteht nur über Einladung oder Passwort-Reset durch die Person selbst.
  Rollen und Qualifikationen bleiben auch bei Provider-Konten lokal änderbar.
- **Niemand bearbeitet ein Konto, das mehr darf als er selbst.** Bearbeiten
  verlangt, dass die handelnde Person alle Rechte des Zielkontos selbst hält
  (Gleichstand erlaubt — sonst könnte kein Administrator den anderen
  pflegen). Ohne diese Regel könnte ein Benutzerverwalter einem Administrator
  per Passwortfeld ein neues Passwort setzen und sich als er anmelden.
- **Not-Aus („Zugang sperren"):** sofort wirksam, beendet auch laufende
  Sitzungen, verlangt einen Grund (er steht im Protokoll — in drei Monaten
  weiß sonst niemand mehr, warum die Sperre da ist). Das eigene Konto lässt
  sich nicht sperren. Die Sperre überlebt jeden Mitgliederabgleich. Aufheben
  zeigt den damaligen Grund noch einmal an.
- **Einladungen:** Die Aktion „Einladen" verschickt einen begrenzt gültigen
  Link zum Passwort-Setzen — sichtbar nur, wenn Mailversand eingerichtet ist.
  Die Einstellung „Einladungen automatisch versenden" (ab Werk aus) lädt
  jedes *neu* angelegte Konto aus dem Abgleich sofort ein.

### 5.2 Rollen und Rechte

Verwalten: Recht `core.roles.manage`.

Rollen sagen, was jemand im System **bedienen** darf; wofür jemand
**einstehen** kann, steht unter Qualifikationen. Standardrollen ab Werk:

| Rolle | Gedacht für |
|---|---|
| Administrator | Systemverwaltung (Benutzer, Rollen, Module, Einstellungen, Protokoll) |
| Werkstattleiter | Personalüberblick, Qualifikationen eintragen, Protokoll, fremde Logbücher |
| Freigabeberechtigtes Personal | die Feststellungen — zusammen mit einer gültigen Lizenz |
| Mechaniker | tägliche Arbeit |
| Mitglied | Lesen |

Modulrechte werden bei der Modulaktivierung **nicht** automatisch verteilt —
welche Rolle was darf, ist Vereinsentscheidung und in der Rollenverwaltung je
aktivem Modul einstellbar. Rollen lassen sich nicht löschen (das entzöge
still allen Trägern die Rechte); eigene Rollen sind ausdrücklich vorgesehen.

**Keine Rolle kann alles.** Den Fall „jemand muss jetzt alles können"
beantwortet der Break-glass-Zugang an der Konsole (Kapitel 15), keine
Superrolle.

**Rollenzuordnungen** (sichtbar, sobald ein Identity-Provider eingerichtet
ist): ordnen Vereinsfunktionen, Provider-Rollen oder einzelne Personen einer
Aeronance-Rolle zu. Ohne Zuordnung bekommt niemand von außen eine Rolle —
anmelden geht trotzdem, nur eben ohne Rechte. Die Freigabeberechtigung steht
hier bewusst nicht zur Wahl: Sie wird nur von Hand, gegen Lizenznachweis,
vergeben.

### 5.3 Qualifikationen

Reiter „Qualifikationen" am Benutzer. Eintragen: Recht
`core.qualifications.manage` — ein eigenes Recht, weil das Eintragen die
Behauptung ist, dass der Nachweis existiert.

Drei Arten:

- **Part-66-Lizenz** — Nummer (wird bei Feststellungen unveränderlich
  mitgeschrieben), Kategorie, Aussteller.
- **Pilot-Owner-Berechtigung** — gilt nur für das Luftfahrzeug, für das die
  Person im Instandhaltungsprogramm genannt ist (Feld „Gilt für", z. B.
  „D-KABC").
- **Schulungsnachweis** — Gegenstand (Freitext: Rotax-Schulung,
  Klebeverfahren, Human Factors …), Aussteller, Urkunde als Anhang. **Er
  verleiht ausdrücklich keine Befugnis** — er dokumentiert „diese Person
  wurde geschult", nicht „diese Person darf freigeben".

Gemeinsam: Gültig ab/bis (leer = unbefristet; abgelaufen wird rot markiert
und deckt keine Feststellung mehr), Urkunden-Upload (PDF/JPEG/PNG, private
Ablage außerhalb des Webverzeichnisses).

---

## 6. Einstellungen, Module, Protokoll

Navigation: Gruppe **System**.

### 6.1 Einstellungen (`core.settings.manage`)

Alles, was eine Organisation selbst festlegt — ohne eine Datei anzufassen.
Gruppen: **Organisation** (Name, Logo, Zeitzone), **E-Mail** (SMTP; ohne
Eintrag werden schlicht keine Mails verschickt), **Sicherung**
(Verschlüsselung: aus / öffentlicher Schlüssel / Passwort), **Auslagerung**
(eingehängtes Verzeichnis, SFTP oder S3), **Aufbewahrung** (alle Regeln ab
Werk aus), **Betrieb** (Dokumentgröße, Fälligkeits-Vorschau, Virenscanner),
**Vereinsflieger** (Arbeitsstunden-Rückschreibung).

Drei Regeln, die man kennen sollte:

- **Datenbank gewinnt, Umgebung nur initial:** Solange nichts gespeichert
  ist, gilt die Env-Variable (docker-compose wirkt wie erwartet). Nach dem
  ersten Speichern gilt die Datenbank — dauerhaft. „Zurücksetzen" je Feld
  macht den Weg zur Umgebung wieder frei.
- **Geheimnisse werden nie zurückgezeigt.** Leeres Feld beim Speichern heißt
  „unverändert".
- **Ohne Verschlüsselung verlässt keine Sicherung das System:** Ist ein
  Auslagerungsziel eingerichtet und die Verschlüsselung aus, schlägt der
  Sicherungslauf fehl — mit Ansage. Die Auslagerung ist deshalb gesperrt,
  bis eine Verschlüsselung eingestellt ist, und zeigt danach nur die Felder
  des gewählten Ziels (Verzeichnis, SFTP oder S3).

Der Knopf **„Testmail senden"** prüft, was gerade im Formular steht — auch
ungespeichert — und zeigt im Fehlerfall die Antwort des Mailservers.

### 6.2 Module (`core.modules.manage`)

Aktivieren und Deaktivieren mit Erklärung der Abhängigkeiten („Wird mit
aktiviert: …"). Deaktivieren blendet aus und stoppt die Hintergrundläufe des
Moduls; die Daten bleiben erhalten und stehen nach erneuter Aktivierung
wieder zur Verfügung.

### 6.3 Protokoll (`core.audit.view`)

Der Audit-Trail: Wann, Wer, Bereich, Was, Betroffen, Änderung (alt → neu).
Gedacht zum gezielten Nachschlagen, nicht zum Mitlesen. **Einträge lassen
sich nicht ändern und nicht löschen** — der einzige Weg hinaus sind die
Aufbewahrungsregeln, und deren Lauf protokolliert sich selbst.

---

## 7. Lager

Navigation: Gruppe **Lager**. Das Modul funktioniert allein — ohne Flotte
sind Kennzeichen Freitextfelder, die Hinweise sagen es dazu.

### Grundbegriffe

**Bauteiltyp** = was es ist (Stammdaten). **Los** = eine konkrete
Liefermenge mit eigenem Nachweis. **Bewegung** = eine Zeile im Journal.
Bestand ist immer die Summe der Bewegungen — nichts wird übertippt.

Ob ein Teil **losgeführt** ist, wird nicht gefragt, sondern abgeleitet:
Form-1-pflichtig, seriennummerngeführt oder mit Lagerzeit versehen ⇒
losgeführt. Schrauben und Niete sind Sammelbestand.

**Zustandskette eines Loses:** brauchbar ⇄ gesperrt → unbrauchbar →
ausgemustert → entsorgt. „Gesperrt" ist vorsorglich und jederzeit
rücknehmbar. „Unbrauchbar", „ausgemustert" und jede Freigabe zurück auf
„brauchbar" sind **Feststellungen**: Berechtigung *und* gültige
Part-66-Lizenz, Name und Lizenz werden festgeschrieben. **Ausgemustert ist
eine Einbahnstraße** — zurück ins Versorgungssystem gibt es nicht, für
niemanden.

Wichtige Rechte (Auszug): `stock.view` (ansehen), `stock.receive`
(einbuchen, Ausbau, Inventur), `stock.issue` (entnehmen),
`stock.quarantine` (vorsorglich sperren), `stock.quarantine.certify`
(Feststellungen), `stock.scrap` (ausmustern/vernichten), `stock.transfer`
(umlagern), `stock.correct` (Journal-Korrektur), `stock.repair`
(Reparaturversand), `stock.report` (Berichte), `parts.types.manage`,
`storage.locations.manage`, `stock.orders.manage`, `suppliers.manage`.

### 7.1 Bauteiltypen

Klassifizierung entscheidet den Nachweis: **Bauteil** (Form 1),
**Standard Part** (Konformitätsbescheinigung), **Verbrauchsmaterial**
(Herstellererklärung). Dazu: Seriennummernführung, maximale Lagerzeit,
Laufzeitbegrenzung (**TBO** überholbar / **TBR** Austausch — ein TBR-Teil
darf nach Ausbau nie wieder eingelagert werden), Einheit (es wird nie
umgerechnet), Mindest-/Maximalbestand, Lagerort.

### 7.2 Lagerorte

Lagerorte enthalten Fächer. Ein Fach kann als **Sperrlager** markiert sein:
Unbrauchbares und Ausgemustertes wird getrennt gelagert, aus einem Sperrlager
wird nicht entnommen. „Regalschild drucken" erzeugt QR-Schilder für die
Inventur.

### 7.3 Einbuchen

Das Formular passt sich an, was der Bauteiltyp verlangt. Bei
Form-1-pflichtigen Teilen folgen die Nachweisfelder den Blöcken des
Vordrucks, damit das Papier von oben nach unten abgeschrieben werden kann;
der Scan wird mit hochgeladen (private Ablage, nur nach Anmeldung abrufbar).

- **Die Form-1-Pflicht ist hart:** Ohne Nachweistyp „Form 1" mit Nummer wird
  nicht eingebucht — die Ware bleibt im Wareneingang, bis der Nachweis
  vorliegt. Ein selbst benanntes Papier gilt nicht als Form 1, auch wenn es
  so heißt.
- **Seriennummerngeführte Teile sind Lose von eins** — zwei Stück sind zwei
  Buchungen.
- **Losnummer:** die Form-1-Nummer, wo eine da ist (bei mehreren Positionen
  mit `-2`, `-3` …); sonst erzeugt (`JJJJMM-NNN`). Eine vergebene Losnummer
  ändert sich nie.

### 7.4 Entnehmen

Vorgeschlagen wird das Los, das zuerst verfällt (FEFO) — ein anderes zu
wählen ist zulässig und braucht keine Begründung. Bei Serienteilen gibt es
bewusst keinen Vorschlag: Die Auswahl anhand der Seriennummer *ist* die
Identifikation. Kennzeichen und Vorgang sind freiwillig, schließen aber die
Kette vom Nachweis bis zum Luftfahrzeug.

Nicht entnommen wird aus Losen, die: nicht brauchbar sind, in einem
Sperrlager-Fach stehen, abgelaufen sind oder weniger enthalten als verlangt.
**Ausbau-Lose ohne Form 1 dürfen nur zurück in ihr Herkunfts-Luftfahrzeug** —
solche Lose werden für andere Kennzeichen ausgeblendet, und der Hinweis sagt,
wie viele und warum.

### 7.5 Ausbau einlagern

Für Teile, die aus einem Luftfahrzeug kommen: Kennzeichen, Ausbaudatum und
Grund sind Pflicht. Das Häkchen „Beim Ausbau als brauchbar festgestellt" ist
eine Feststellung (Berechtigung + Part-66-Lizenz); ohne sie wird trotzdem
gebucht, das Los liegt aber im Sperrbestand. Auch als brauchbar festgestellt
bleibt das Teil ohne Form 1 an sein Herkunfts-Luftfahrzeug gebunden — der
einzige Weg, diese Bindung zu lösen, ist der Reparaturversand. TBR-Teile
werden nicht wieder eingelagert.

### 7.6 Vernichten

Bestand ausbuchen, weil er vernichtet wurde — mit Pflichtgrund, teilmengenfähig,
endgültig („Eine Gegenbuchung würde behaupten, das Teil liege wieder im
Regal."). Verfallenes steht oben auf der Seite zum Übernehmen bereit. Das
Vernichten eines *Bauteils* ist eine Feststellung (Part-66-Lizenz);
Verbrauchsmaterial braucht nur die Berechtigung. Nachts um 04:00 setzt das
System abgelaufene Lose automatisch auf „unbrauchbar" — als überschrittenes
Datum, nicht als Urteil.

### 7.7 Reparaturversand

Der dritte Weg aus dem Lager — und der einzige rechtmäßige, mit dem ein an
ein Luftfahrzeug gebundenes Ausbauteil wieder frei verwendbar wird: Ein
zugelassener Betrieb setzt es instand und stellt ein Form 1 aus.

- Der Betrieb kommt idealerweise **aus dem Lieferantenverzeichnis** — dann
  werden Name und Zulassungsnummer übernommen, und **ein Betrieb mit
  abgelaufener Zulassung wird abgelehnt** (was von dort zurückkäme, trüge
  eine wertlose Bescheinigung).
- **Rückkehr buchen:** Die Kernfrage ist, ob ein Form 1 zurückkam. Mit
  Form 1: neues brauchbares Los, die Bindung ans Luftfahrzeug erlischt.
  Ohne: neues Los im Sperrbestand, Bindung bleibt — kein Fehlerfall
  (Kostenvoranschlag, unrepariert zurück). Es entsteht immer ein neues Los.
- „Als verloren abschreiben" schließt einen Versand, der nie zurückkommt
  (Grund Pflicht).

### 7.8 Inventur

Aufgebaut wie die druckbare **Zählliste**: Lagerort für Lagerort, Los für
Los, der erwartete Bestand steht daneben (bewusst kein Blindzählen). Ein
gescanntes Regalschild wählt den Lagerort.

- Differenzen werden **gegen den Bestand zum Zähldatum** gerechnet — Samstag
  gezählt, Sonntag entnommen, Montag eingetragen geht damit richtig aus. Ein
  Zähldatum in der Zukunft wird abgelehnt.
- Fehlmengen dürfen aufs Los; **Mehrbestand nie** — das würde behaupten, das
  zusätzliche Teil sei vom Form 1 des Loses gedeckt. Gefundenes wird als
  eigenes Los ohne Nachweis im Sperrbestand erfasst, bis jemand die Herkunft
  klärt.

Der **Inventurbericht** (`/inventurbericht`, Recht `stock.report`)
beantwortet exakt, was zu einem Stichtag da war — mit Abschnitten für
Mindestbestand, Verfall, Sperrbestand, Nachweislücken und optionalem
Journal. Die Seite **„Was liegt an"** ist der Alltags-Gegenpart: abgelaufen,
unter Mindestbestand, lange gesperrt, Dokument fehlt.

### 7.9 Lose, Journal, Storno

Lose werden nie von Hand angelegt oder bearbeitet — sie entstehen durch
Einbuchen. Die Detailansicht zeigt Nachweis (in Vordruck-Reihenfolge),
Bewegungen und Zustandshistorie mit Sperrzettelnummern und der Angabe, wer
festgestellt hat (qualifiziert, mit Lizenz) oder vorsorglich gesperrt hat.

Im **Journal** steht jede Bewegung. **„Korrektur buchen"** erzeugt eine
Gegenbuchung mit Pflichtgrund; Original und Korrektur verweisen aufeinander.
Nichts wird zweimal storniert; Entsorgungen sind nicht rückbuchbar; ein
Zugang, aus dem schon entnommen wurde, lässt sich nicht mehr voll
zurücknehmen (dann: Inventurdifferenz).

### 7.10 Drucken und Scannen

Losaufkleber (Rolle oder A4-Bogen), Regalschilder und Sperrzettel
(Anhängerbogen, Aufkleber oder Einzelzettel) druckt der Browser; je ein
**Kalibrierbogen** prüft, ob der Drucker skaliert. Sperrzettel tragen
`JJJJMM-NNN`-Nummern, die nie wiederverwendet werden.

Die QR-Codes enthalten bewusst keine Web-Adresse — nur `AER1:L:<Losnummer>`
bzw. `AER1:S:<Lagerort>`. Kamera und Tastatur sind gleichwertig: Eine
abgetippte Losnummer wirkt wie ein Scan. Die Kamera braucht HTTPS.

### 7.11 Bestellungen und Lieferanten

**Bestellungen sind der Erinnerer, nicht die Beschaffung** — bestellt wird
weiterhin am Telefon oder im Webshop; hier steht, worauf jemand wartet.
Keine Preise, keine Rechnungen.

- Das zugesagte Lieferdatum ist mit Bestelldatum + 1 Woche vorbelegt (viele
  Lieferanten sagen keines zu — und gerade bei denen will man erinnert
  werden). Feld leeren heißt: nicht erinnern.
- Überfällige Bestellungen lösen morgens (07:30) **eine Mail je Person** aus,
  frühestens alle drei Tage wieder; unabhängig davon erscheint ein Hinweis
  auf der Startseite (der keinen Mailserver braucht).
- **Eingebucht wird je Position** über den regulären Wareneingang — jede
  Charge hat ihr eigenes Form 1; alle Lagerregeln gelten unverändert.
  Teillieferungen sind der Normalfall; erledigt ist die Bestellung erst,
  wenn alles da ist.
- **Stornieren** verlangt einen Grund und geht nur, solange noch etwas
  aussteht; Geliefertes bleibt eingebucht.

**Lieferanten** tragen optional eine **Zulassung als Betrieb**
(Zulassungsnummer, Umfang, Gültig bis — leer heißt unbefristet, nicht
unbekannt). Der Reparaturversand und die Fremdvergabe der Flotte prüfen
dieses Verzeichnis.

---

## 8. Eingangsprüfung

Part-145-Baustein, setzt das Lager voraus. Rechte: `inspection.view`,
`inspection.perform`.

Mit aktivem Modul wird **jede** Einbuchung (auch die Rückkehr aus der
Reparatur) sofort gesperrt und eine Eingangsprüfung geöffnet. Erst die
unterschriebene Annahme gibt das Los frei. Sammelbestand ohne Los lässt sich
nicht sperren — dort ist die Prüfung ein Nachweis, keine Sperre.

- Die **Checkliste steht fest im Code** und wird je Lieferung zugeschnitten:
  Eine Tüte Niete wird nicht nach einem Form 1 gefragt — sonst gewöhnt man
  sich das gedankenlose „Entfällt" an. Punkte: Teilenummer, Menge,
  Bescheinigung und Ausstellende Stelle (wo ein Nachweis erwartet wird),
  Kennzeichnung, Zustand/Verpackung, Restlaufzeit (bei Lagerzeit).
- Antworten: In Ordnung / Beanstandet / Entfällt. **„Beanstandet" und
  „Entfällt" brauchen eine Bemerkung** — sonst ist es von „nicht
  hingeschaut" nicht zu unterscheiden.
- **Erst die Liste, dann die Entscheidung** — Annehmen und Zurückweisen sind
  eine Auswahl am Ende desselben Dialogs, keine zwei Knöpfe. Ein offener
  Punkt verhindert die Unterschrift, auch bei einer Zurückweisung. Annehmen
  trotz Beanstandung ist erlaubt (gedellte Verpackung um ein gutes Teil),
  aber nur mit Begründung.
- **Die Annahme geht durch die Lagerfreigabe** und braucht deren Recht:
  `stock.quarantine.release` — eine Rechtefrage, **keine Lizenzfrage**. Die
  Eingangsprüfung ist Papier- und Zustandsprüfung nach Verfahren des
  Betriebs (145.A.42), keine Freigabe am Luftfahrzeug; eine Part-66-Lizenz
  ist dafür nicht nötig. Qualifiziert bleiben die Urteile über den
  *Zustand*: unbrauchbar erklären, der Weg zurück aus unbrauchbar,
  ausmustern (`stock.quarantine.certify` bzw. `stock.scrap` + Part-66).
  **Zurückweisen bewegt nichts** — die Ware bleibt gesperrt; was mit ihr
  geschieht, ist ein eigener Vorgang.
- Prüfungen werden nie gelöscht und nie nachträglich geändert — eine
  Korrektur ist ein neuer Eintrag.

Ohne das Modul verhält sich der Wareneingang exakt wie vorher.

---

## 9. Flotte

Navigation: Gruppe **Flotte**. Rechte (Auszug): `fleet.view` (alles
ansehen), `fleet.manage` (Stammdaten, Wartungsunterlagen),
`fleet.counters.record` (Zählerstände), `fleet.components.manage`
(Komponenten, Laufzeitgrenzen), `fleet.programme.manage`
(Pilot-Owner-Nennungen, Dokumente), `fleet.reviews.record` (Nachprüfung,
Wägung), `fleet.external_work.manage` / `fleet.external_work.accept`
(Fremdvergabe).

### 9.1 Luftfahrzeuge und Zähler

Jedes Luftfahrzeug führt **Flugzeit und Landungen** immer (gesetzlich zu
erfassen, nicht abwählbar); Motorlaufzeit, Starts und Zyklen sind zuwählbar.
Zählerstände werden als absolute Ablesungen erfasst und **nie überschrieben**
— ein falscher Eintrag wird durch einen weiteren korrigiert, beide bleiben
sichtbar.

**„—" statt 0:** Ein nie abgelesener Zähler zeigt einen Strich. Null sähe
aus wie ein fabrikneues Luftfahrzeug — und ein vor der ersten Ablesung
eingebautes Teil würde sonst beim ersten echten Stand die gesamte Lebenszeit
des Flugzeugs als Laufzeit geschenkt bekommen.

**„Luftfahrzeug übernehmen"** erfasst die Anfangsstände — nur möglich,
solange noch kein einziger Zählerstand existiert; ein seit Jahren geführtes
Flugzeug kann niemand still neu beziffern.

Luftfahrzeuge werden nie gelöscht, nur „außer Betrieb" genommen — die
Lebenslaufakte ist der Zweck des Datensatzes.

### 9.2 Muster und Kennblätter

Muster machen die LTA/TM-Zuordnung exakt (statt Namensvergleich) und tragen
das Kennblatt. **„Kennblatt suchen"** fragt die hinterlegten Behörden ab
(EASA-Dokumentenbibliothek, LBA Blaues Buch — auch für Motoren, Propeller
und Schleppkupplungen) und legt das Dokument auf Wunsch ab; welcher Treffer
der richtige ist, entscheidet ein Mensch.

Das Kästchen **„Kein Musterbetreuer mehr vorhanden"** ist eine eigene
Aussage (nicht dasselbe wie ein leeres Feld) und löst auf der
LTA/TM-Übersicht die Warnung aus, dass eine vollständig aussehende Liste
keine sein muss.

**Kopplung ans Lager:** Ein Komponentenmuster kann seinen **Bauteiltyp**
benennen (eine Schleppkupplung ist beides — Lager-Stammsatz *und*
Flotten-Muster) und **Muster-Laufzeiten** tragen, z. B. „24 Monate" und
„500 Starts". Wird ein solches Teil aus dem Lager in ein Luftfahrzeug
entnommen, ist der Einbau automatisch katalogisiert und erbt die
Laufzeiten — als **Kopie**: Eine spätere Änderung am Muster fasst
bestehende Einbauten nicht an. Ohne Kopplung bleibt alles wie gehabt
(Laufzeiten von Hand); pro Bauteiltyp ist genau ein Muster zulässig.

### 9.3 Komponenten und Laufzeiten

Eingebaute Komponenten zeigen TSN/TSO nebeneinander, „fällig in" und
„fällig bei" (das Datum oder der Zählerstand, den das Instrument zeigen wird
— am Flugzeug soll niemand addieren müssen).

- **Vorhandenes Bauteil erfassen (Onboarding):** Ein Luftfahrzeug kommt nie
  leer. Pflicht ist das **Quellendokument** („Betriebszeitenübersicht des
  Vorbetriebs vom …") und das Einbaudatum **laut Unterlagen** — heute
  einzutragen würde jeder Kalendergrenze frische Jahre schenken. Solche
  Einträge bleiben dauerhaft als „bei Übernahme erfasst" gekennzeichnet.
- **Laufzeitgrenzen:** Monate, festes Datum oder Zähler (TSN/TSO-Basis);
  mehrere je Bauteil sind der Normalfall („2 Jahre oder 500 Starts, was
  zuerst eintritt" — fällig ist die früheste). Zulässige Überziehung in
  Prozent und/oder absolut; die kleinere gewinnt.
- **Wartung abhaken:** Wird überzogen, rechnet das nächste Intervall ab der
  *alten* Fälligkeit (sonst wandert der Termin bei jeder Nutzung nach
  hinten); wird zu früh gewartet, ab dem tatsächlichen Stand.
- **Ausbau:** Grund Pflicht; „serviceable" ist eine Feststellung
  (Part-66-Lizenz, wird festgeschrieben). Mit aktivem Lager landet das Teil
  dort, samt Bindungsregeln; die Zähler des Bauteils frieren beim Ausbau ein.
- TSO wird **nie aus einer Reparatur gefolgert** — nur eine ausdrückliche
  Grundüberholung (mit Nachweis) nullt TSO; TSN läuft weiter.

### 9.4 Nachprüfung (ARC), Dokumente, Wägung

- **Nachprüfung:** „Gültig bis" wird angezeigt, nicht abgefragt — die Regel
  ist bekannt: innerhalb 90 Tagen vor Ablauf ausgestellt trägt das alte
  Datum (+ 1 Jahr), sonst gilt Ausstellung + 364 Tage. Ein Luftfahrzeug ohne
  hinterlegte Nachprüfung wird als **überfällig** gemeldet — es sähe sonst
  aus wie eines, bei dem alles in Ordnung ist.
- **Dokumente** (AMP, Wägebericht, Lärmzeugnis, …): „Gültig bis" darf leer
  bleiben — leer heißt „läuft nicht ab", nicht „vergessen". Die **Datei**
  (PDF/JPG/PNG) kann mit hochgeladen werden; in der Übersicht wird die
  Bezeichnung dann zum Link. Ohne Datei wird nur die Frist geführt — für
  Papier, das im Ordner bleibt.
- **Wägung:** eigenes Formular je Bauart (Segelflugzeug bauteilweise mit
  M.N.T.; Motorflugzeug auf Auflagen mit Abzügen samt Hebelarm), lebende
  Ergebnisrechnung mit Befunden vor der Unterschrift, Beladeplan. **„Speichern
  und drucken" friert die Werte ein** — eine Korrektur ist danach eine neue
  Wägung, wie auf Papier. Ein Ergebnis außerhalb der Grenzen wird trotzdem
  abgezeichnet: Es ist eine echte Messung; sie verhindert das Fliegen, nicht
  die Unterschrift. „Neue Wägung (Werte übernehmen)" kopiert
  Handbuchwerte und Sitzplätze — die Waagen-Abstände bleiben leer, die
  werden jedes Mal neu gemessen.

### 9.5 „Noch offen" und Fälligkeiten

Der Kasten **„Noch offen"** am Luftfahrzeug sammelt: fehlende/abgelaufene
Nachprüfung, überzogene Laufzeitgrenzen, ausgebaute und nicht ersetzte
Mindestausrüstung, zurückgekehrte aber nicht freigegebene Fremdaufträge,
abgelaufene Dokumente und Wägungen — plus die Beiträge anderer Module
(offene Befunde, LTA/TM). Er ist ausdrücklich **keine Feststellung der
Lufttüchtigkeit**: „Nichts gefunden" heißt nicht „lufttüchtig".

Die Seite **Fälligkeiten** zeigt flottenweit, was abläuft (Vorschaufenster
30–180 Tage); der Navigationszähler zählt nur Überfälliges — ein Badge, das
nie null ist, wird nicht mehr gelesen.

### 9.6 Fremdvergabe

Drei getrennte Akte: **vergeben** (Betrieb aus dem Lieferantenverzeichnis,
abgelaufene Zulassung wird verweigert; beauftragte Arbeiten sind Pflicht),
**Rückkehr erfassen** (setzt „zurück" — und gibt bewusst *nicht* frei: Das
Flugzeug steht in der Halle und sieht fertig aus, genau dann wird es
geflogen), **Freigabe erfassen** (durch den Fremdbetrieb — Unterzeichner
Pflicht — oder „durch uns": das verlangt `fleet.external_work.accept` und
eine gültige Part-66-Lizenz; eine Pilot-Owner-Berechtigung genügt nie, denn
sie deckt nur selbst ausgeführte Instandhaltung).

### 9.7 Drucken

Ausrüstungsverzeichnis (Mindestausrüstung zuerst, mit Hebelarm — das Blatt
ist Teil des Wägungsnachweises), Betriebszeitenübersicht (ausgebaute
Bauteile bleiben enthalten — es ist eine Historie) und Wägebericht im
BWLV-Layout.

---

## 10. LTA / TM

Modul „LTA/TM" (setzt die Flotte voraus; Arbeitskarten optional). Rechte:
`directives.view` (lesen), `directives.manage` (Zeilen anlegen, Listen
importieren, Zugänge pflegen), `directives.assess` (beurteilen — eine
Feststellung).

**Die Liste wird länger, nie kürzer.** Gelöscht wird nie; eine Anweisung,
die nicht mehr gilt, wird ersetzt oder als nicht zutreffend beurteilt —
beides hinterlässt eine lesbare Spur.

### 10.1 Begriffe

- **Art** (LTA, AD, TM, SB) und **Verbindlichkeit** (verbindlich /
  empfohlen / optional) sind getrennt — eine TM wird verbindlich, sobald
  eine Behörde sie übernimmt, ohne ihre Nummer zu ändern.
- **Rotax-Sonderfall:** Die Verbindlichkeit steht bei Rotax nicht in der
  Nummer, sondern vierstufig auf der PDF-Titelseite (MANDATORY · OBLIGATORY ·
  RECOMMENDED · OPTIONAL) — **obligatory ist verbindlich**. Der Import setzt
  Rotax-Zeilen pauschal auf verbindlich (die sichere Richtung); wer
  differenzieren will, liest die Titelseite und korrigiert die Zeile.
- **Beurteilung je Luftfahrzeug:** nicht beurteilt / durchgeführt / nicht
  zutreffend / nicht durchgeführt. „Nicht beurteilt" heißt: Da hat noch
  niemand hingesehen — und genau das blockiert die Freigabe.

### 10.2 Quellen und Aktualisierung

48 Quellen-Definitionen liegen bei (EASA-AD, FAA-AD, NfL, Rotax, Schleicher,
Schempp-Hirth, DG, SZD, Grob, Aquila, Tost u. v. m.); eigene Definitionen in
`storage/app/directive-sources/` überleben Updates und gewinnen bei
Namensgleichheit. Aktualisiert wird wöchentlich (sonntags 03:00) oder von
Hand:

```
php artisan aeronance:refresh-directives
```

Standardmäßig werden nur die Muster der eigenen aktiven Flotte abgefragt.
Der Import fügt hinzu oder aktualisiert, **entfernt nie**, fasst keine
handgepflegten Zeilen an und berührt keine Beurteilungen. Hersteller mit
Kundenlogin (z. B. Schempp-Hirth) bekommen ihre Zugangsdaten unter
**Hersteller-Zugänge** (verschlüsselt gespeichert, nie zurückgezeigt,
„Anmeldung testen" prüft sofort).

**Beurteilt wird nichts automatisch.** Neue Zeilen erscheinen als „noch
keine Beurteilung" und blockieren die Freigabe, bis jemand Qualifiziertes
sie Zeile für Zeile beurteilt hat.

### 10.3 Beurteilen

Auf der Seite der Anweisung, je Luftfahrzeug. Alle drei Antworten verlangen
neben `directives.assess` eine Qualifikation (Part-66-Lizenz oder
Pilot-Owner-Nennung für genau dieses Kennzeichen) — auch „nicht zutreffend",
denn falsch gesetzt verschwindet eine verbindliche Anweisung still aus der
Liste.

- **Durchgeführt:** „Wie durchgeführt" ist Pflicht; die Arbeitskarten-Nummer
  gehört als Nachweis dazu, wo das Werkstattmodul läuft. Bei wiederkehrenden
  Anweisungen rechnet die Wiedervorlage **ab dem Tag der Durchführung**.
- **Nicht zutreffend:** Begründung Pflicht; die Zeile bleibt in der Liste —
  ein Prüfer will sehen, dass hingeschaut wurde.
- **Nicht durchgeführt:** gibt es nur für empfohlene/optionale Zeilen (eine
  verbindliche ist gemacht, trifft nicht zu, oder das Flugzeug fliegt
  nicht) — und verlangt strenger: Part-66-Lizenz **oder** den Halter dieses
  Luftfahrzeugs. Eine Pilot-Owner-Berechtigung genügt hier nicht: Sie
  zeichnet Instandhaltung ab, sie hebt keine Herstellerempfehlung auf.

**„Arbeitskarte anlegen"** (sichtbar mit Werkstattmodul, verlangt
`directives.view` **und** `workorders.cards.work`) hängt die Anweisung als
Karte an einen offenen Vorgang. Die Karte organisiert die Arbeit — erledigt
ist die Anweisung erst mit der ausdrücklichen Beurteilung „durchgeführt".

### 10.4 Die Übersicht je Luftfahrzeug

**LTA/TM-Übersicht**: die Liste aus Sicht eines Luftfahrzeugs, mit
Kopfzahlen (Zeilen / nicht beurteilt / offen / blockierend) — die Ansicht
für die Jahresnachprüfung. **„Übersicht drucken"** erzeugt das Blatt für die
Bordunterlagen mit Datum und Unterschrift: Ohne ist es ein Ausdruck, mit ist
es die Aussage, dass jemand die Liste durchgegangen ist. Fehlt der
Musterbetreuer, wird die Warnung mitgedruckt.

---

## 11. Arbeitskarten und Freigabe

Navigation: Gruppe **Werkstatt** → „Vorgänge" und „Befunde". Setzt die
Flotte voraus; Teileentnahme zusätzlich das Lager. Rechte:
`workorders.view`, `workorders.manage` (Vorgang eröffnen/abschließen),
`workorders.cards.work` (Karten, Zeiten, fertig melden),
`workorders.cards.certify` (abzeichnen, Freigabe — plus Qualifikation),
`workorders.cards.inspect` (unabhängige Kontrolle),
`workorders.findings.record` / `workorders.findings.defer` (Befunde).

### 11.1 Vorgang und Karten

Ein **Vorgang** ist die Klammer („D-KABC zur Jahresnachprüfung"); beim
Eröffnen werden die Zählerstände automatisch vom Luftfahrzeug kopiert —
niemand tippt sie ein, und eine Wochen später geschriebene Karte weiß, bei
welchem Stand die Arbeit begann. Nummern: Vorgänge `JJJJ-NNN`, Karten
`JJJJ-NNN/NN`, Befunde `BJJJJ-NNN`, Freigaben `CRS-JJJJ-NNN`.

**Arbeitskarte anlegen:** Arbeit, Tätigkeitsart (Prüfung, Wartung,
Reparatur, Änderung, LTA-Durchführung, Sonstiges), ATA-Kapitel (frei, mit
Vorschlägen — im Segelflug wird ATA oft nicht geführt), optional eine
fällige **Laufzeitgrenze** (das Abzeichnen erledigt sie), die
Arbeitsanweisung, **„Gearbeitet nach"** (der Wartungsunterlagen-Stand, als
Abschrift festgehalten — eine spätere Revision ändert nichts daran, was hier
stand) und der Schalter **„Kritische Arbeit"** mit Pflichtfeld „Woran
genau".

**Kritisch wird beim Anlegen entschieden und ist danach unveränderlich** —
wer die Markierung nachträglich setzen oder wegnehmen könnte, könnte die
Kontrolle nach Bedarf an- und abschalten. Und: sparsam verwenden — wäre jede
Karte kritisch, wäre die Kontrolle nach zwei Wochen ein Haken, den man
setzt, ohne hinzusehen.

### 11.2 Arbeitszeit und Fertigmeldung

**Arbeitszeit erfassen:** je Person und Karte, in Minuten (auf dem Zettel
steht „1:45", nicht „1,75"), mit Art der Mitwirkung (ausgeführt /
unterstützt / beaufsichtigt) — das Erfahrungslogbuch zählt genau das. Auf
abgezeichneten Karten geht nichts mehr: Nachträgliche Stunden würden ändern,
wofür jemand seinen Namen gegeben hat.

**Fertig melden** verlangt die „Ausgeführte Arbeit" (was tatsächlich gemacht
wurde — die Anweisung sagt, was gefordert war) und **erfasste Arbeitszeit**:
Eine Karte ohne Zeiten hat es für kein Logbuch je gegeben. Fertigmelden
braucht keine Qualifikation — sonst müsste ein Lizenzinhaber für einen
Nachmittag unterschreiben, den er nicht verbracht hat.

### 11.3 Unabhängige Kontrolle (kritische Arbeiten)

Zwischen Fertigmeldung und Abzeichnung: Eine **zweite Person, die nicht an
der Karte gearbeitet hat** — ausgeschlossen ist jeder mit gebuchten Stunden,
nicht nur der Fertigmelder —, sieht die Arbeit an und hält schriftlich fest,
**was** sie geprüft hat („Anlenkung beidseitig gezogen, Sicherung sichtbar"
ist ein Nachweis; „kontrolliert" ist eine Behauptung).

Eine Lizenz ist dafür bewusst **nicht** nötig: In einem Verein mit einem
einzigen Lizenzinhaber ist genau der derjenige, der gearbeitet hat — mit
Lizenzpflicht fiele die Kontrolle nicht strenger aus, sondern aus. Hat der
Kontrolleur eine, wird ihre Nummer mitgeschrieben.

### 11.4 Abzeichnen

Verlangt `workorders.cards.certify` **und** eine gültige Qualifikation.
Eine kritische Karte ohne Kontrolle wird nicht abgezeichnet — sonst
entstünde der Nachweis genau dann nicht, wenn es eilig ist.

Die **Pilot-Owner-Grenze** ist streng: Die Berechtigung deckt nur Karten,
auf denen jede Zeitbuchung von der Person selbst stammt und „ausgeführt"
ist. Lizenzen mit Einschränkungen (z. B. „ausgenommen Zellen in
Metallbauweise") oder dem Vermerk „no maintenance exceeding MA.803(b)"
werden entsprechend geprüft; die Meldung nennt den Grund.

**Stornieren** (Grund Pflicht) ist der Weg für überflüssige Karten — eine
abgezeichnete Karte wird nie storniert, das löschte eine Unterschrift; dafür
gibt es eine neue Karte.

### 11.5 Befunde

Ein Befund ist etwas, das auffiel, ohne dass es jemand suchte — er gehört zu
keinem Auftrag und verschwindet nicht, weil eine Karte fertig ist.
**Melden darf jeder** mit `workorders.findings.record` (mehr Augen auf
Mängeln ist der Zweck); der Haken „Verhindert den Betrieb" ist mit Ja
vorbelegt — ob ein Riss kosmetisch ist, kann nur ein Mensch sagen.

- **Einplanen:** Aus einem Befund entsteht eine Reparatur-Karte — angeboten
  werden alle offenen Befunde des Luftfahrzeugs, nicht nur die dieses
  Vorgangs. Der Befund gilt erst als behoben, wenn die Karte abgezeichnet
  ist.
- **Zurückstellen** (Frist optional, Begründung Pflicht) ist eine
  Feststellung: Recht `workorders.findings.defer` plus Qualifikation. Eine
  abgelaufene Zurückstellung blockiert wieder — auch die Freigabe.
- **Verwerfen** („kein Befund") und **manuell als behoben erfassen** sind
  ebenso qualifikationspflichtig.

### 11.6 Teile auf die Karte

Sichtbar mit aktivem Lagermodul und Lagerrecht `stock.issue`. Gebucht wird
über das Lager mit allen dortigen Regeln; das Kennzeichen geht automatisch
mit, die Losauswahl zeigt nur zulässige Lose. Das **Scan-Feld** setzt
Bauteil und Los in einem Griff — FEFO ist nur eine Annahme, welche Packung
in der Hand lag; der Scan ersetzt die Annahme durch eine Beobachtung, damit
an der Freigabe das richtige Form 1 hängt.

### 11.7 Freigabe (CRS)

Die dritte und letzte Unterschrift: „Fertig gemeldet" heißt, die Arbeit ist
getan; „abgezeichnet" heißt, sie war in Ordnung; die Freigabe heißt, das
Luftfahrzeug darf fliegen.

Voraussetzungen: mindestens eine Karte, jede abgezeichnet oder storniert;
kein blockierender Befund (eine *gültige* Zurückstellung blockiert nicht —
sie ist eine Entscheidung, für die jemand einsteht); keine ungeklärten
Punkte der Lufttüchtigkeitsprüfung (insbesondere keine unbeurteilte
LTA/TM-Zeile). Ein abgelaufenes ARC blockiert die Freigabe dagegen nicht —
eine CRS bescheinigt Arbeit, nicht Flugtüchtigkeit.

Ein **Pilot-Owner** kann nur Vorgänge freigeben, die vollständig aus eigener
Arbeit bestehen — eine einzige fremde Karte genügt, dass ein
Part-66-Inhaber unterschreiben muss.

Mit der Freigabe werden Name, Qualifikation und Zählerstände auf der
Bescheinigung eingefroren, der Vorgang wird geschlossen und ist ab dann —
samt Karten und Zeiten — **unveränderlich**. **„Freigabe korrigieren"**
erzeugt eine neue Bescheinigung mit Pflichtgrund; die alte bleibt bestehen
und verweist auf die Nachfolgerin. Der Druck einer ersetzten Bescheinigung
trägt einen unübersehbaren Hinweis — das Papier im Ordner darf nie aktuell
aussehen, wenn es das nicht ist.

---

## 12. Werkzeuge

Part-145-Baustein, steht allein (braucht weder Lager noch Flotte). Rechte:
`tools.view`, `tools.manage` (Bestand, Kalibrierscheine), `tools.issue`
(Ausgabe/Rücknahme), `tools.assess` (Lücken bewerten).

- **Werkzeug anlegen:** Inventarnummer (eindeutig), Bezeichnung,
  Aufbewahrungsort, Zustand, Kalibrierpflicht mit **Intervall (Monate)** und
  **Grundlage des Intervalls** (z. B. „EN ISO 6789: 12 Monate oder 5.000
  Betätigungen" — Betätigungen zählt Aeronance bewusst nicht; wo diese
  Grenze zuerst greifen könnte, das Zeitintervall kürzer setzen).
  Kalibrierpflichtig sind nur Werkzeuge, deren Genauigkeit zählt — stünde
  jeder Schraubendreher hier, wäre die Warnliste nach einer Woche
  Hintergrundrauschen.
- **Das Fälligkeitsdatum ist nicht von Hand setzbar** — es entsteht
  ausschließlich aus einem Kalibrierschein. Was auf dem Schein steht,
  schlägt das Intervall. **Kalibrierpflichtig und noch nie kalibriert zählt
  als überfällig.**
- **Kalibrierung eintragen:** Messdatum (nicht in der Zukunft), **Befund
  ohne Vorauswahl** (In Toleranz / Außer Toleranz — wie das Werkzeug beim
  Labor *ankam*, vor einer Justage), Gültig bis, Kalibrierstelle,
  Scheinnummer, **Kalibrierschein als Datei** (hängt am Kalibrierdatensatz).
- **Nachprüfzeitraum:** entsteht automatisch. Außer Toleranz → zurück bis
  zur letzten Messung mit gutem Befund (ab wann es abwich, weiß niemand).
  Zu spät, aber in Ordnung → nur ab dem Fälligkeitsdatum. Beide Fälle
  verlangen eine **dokumentierte Bewertung** („Lücke bewerten", Recht
  `tools.assess`) — was wurde angesehen, mit welchem Ergebnis.
- **Ausgabe:** an wen, für welchen Vorgang (freies Feld — genau diese Angabe
  beantwortet später, welche Arbeit nachzuprüfen ist), bis wann. **Ein
  überfälliges Werkzeug wird nicht ausgegeben** — das ist der einzige
  Zeitpunkt, an dem die Sperre noch etwas nützt. **Zurückgenommen wird
  immer**, auch nach Fristablauf — sonst blieben Karteileichen in der Liste.
- Werkzeuge werden nie gelöscht — ausgesondert wird über den Zustand; ein
  gelöschtes Werkzeug nähme seine Kalibrierhistorie mit.

---

## 13. Erfahrungslogbuch (Part-66)

Modul „Erfahrungslogbuch" (braucht Arbeitskarten und Flotte). Navigation:
Gruppe **Personal**.

**Es führt nichts Eigenes — es liest.** Jede Zeile ist eine erfasste
Arbeitszeit auf einer Karte: Datum, Kennzeichen, Muster, ATA, Tätigkeit,
Dauer, Mitwirkung, ausgeführte Arbeit, Abzeichner, Freigabe. Eine zweite,
von Hand gepflegte Fassung wäre eine zweite Wahrheit.

- **Das eigene Logbuch sieht jeder** angemeldete Nutzer — es ist eine
  persönliche Aufzeichnung. Fremde Logbücher verlangt das Recht
  `part66.logs.view_all` (ab Werk beim Werkstattleiter).
- Zeilen aus nicht freigegebenen Vorgängen sind als **„vorläufig"**
  gekennzeichnet — erst die Freigabe friert ein, und genau deshalb ist ein
  berechnetes Logbuch verlässlich.
- Die Zusammenfassung zählt auch **abgezeichnete Karten**, **erteilte
  Freigaben** und **Lufttüchtigkeitsprüfungen** — es ist ein Erfahrungs-,
  kein Gültigkeitsnachweis; ersetzte Freigaben zählen weiter.
- **Aktualität (66.A.20(b)):** Tage mit Arbeit, berührte Monate, Stunden im
  24-Monats-Fenster — bewusst **Zahlen, kein Urteil**: Was „sechs Monate
  Erfahrung" bei drei Samstagen im Monat heißt, entscheidet die Behörde,
  nicht diese Software. Hinweise erscheinen bei langen Pausen und dünner
  Abdeckung.
- **„Logbuch drucken"** erzeugt das Dokument für die Lizenzbeantragung —
  mit allen Zeilen, Zusammenfassung, ARC-Liste und den Qualifikationen der
  Person.

---

## 14. Vereinsflieger-Anbindung

Modul „Vereinsflieger". Verwaltung: `core.settings.manage` (Anbindungen,
Kopplungen) bzw. `core.roles.manage` (Mitgliedsstatus, Rollenzuordnungen).

**Vereinsflieger liefert, wer jemand ist und was er im Verein tut — nie,
was er in der Werkstatt darf.** Die Freigabeberechtigung wird grundsätzlich
nur in Aeronance vergeben. Eine Anmeldung *über* Vereinsflieger gibt es
nicht — jedes Konto hat ein eigenes Aeronance-Passwort.

- **Anbindungen:** mehrere möglich (eine CAO betreut Flugzeuge mehrerer
  Vereine). Zugangsdaten liegen verschlüsselt und werden nie zurückgezeigt.
  Der Haken **„Mitglieder als Benutzer importieren"** ist der gefährlichste
  der Seite (ab Werk aus): Genau *eine* Anbindung darf ihn tragen; ohne ihn
  werden vom Verein nur Betriebszeiten geholt.
- **Nächtlicher Abgleich (02:00):** erst Mitglieder, dann Betriebszeiten,
  dann Arbeitsstunden. Ein Konto entsteht nur für Mitglieder, deren
  **VF-Mitgliedsstatus** als „aktiv" oder „passiv" eingeordnet ist — jeder
  unbekannte Status wartet auf eine Entscheidung (Seite
  „VF-Mitgliedsstatus"). **Wer fehlt, ist weg:** nicht mehr gemeldete
  Mitglieder werden deaktiviert, nie gelöscht. Eine *leere* Mitgliederliste
  deaktiviert niemanden — eine Störung als Massenaustritt zu lesen wäre der
  teuerste denkbare Irrtum.
- **„Jetzt abgleichen"** an jeder aktiven Anbindung startet denselben vollen
  Abgleich sofort — gedacht für die Ersteinrichtung, er löst zusätzliche
  Zugriffe auf Vereinsflieger aus. Der Lauf braucht bei einem vollen Verein
  etwa eine Minute und läuft im Hintergrund; das Ergebnis (oder der Fehler)
  erscheint in der Liste unter **„Letzter Lauf"** — Seite dafür neu laden.
- **Rollen** entstehen ausschließlich über die Rollenzuordnungen im Kern
  (Funktion, VF-Rolle oder Status → Aeronance-Rolle). Wer nichts zuordnet,
  hat Konten ohne Rechte — das ist der sichere Ausgangszustand.
- **Luftfahrzeug-Kopplungen:** holen nachts Motorzeit, Flugzeit und
  Landungen als Zählerstände in die Flotte — nur bei Änderung, gekennzeichnet
  als Schnittstellenwert ohne Ablese-Person. **„Jetzt lesen"** an der Zeile
  holt die Zeiten sofort (eine Anfrage); das Kennzeichen-Feld schlägt die
  eigene Flotte vor — Vereinsflieger bietet keinen Flugzeuglisten-Endpunkt.
- **Arbeitsstunden-Rückschreibung** (ab Werk aus): schreibt erfasste
  Arbeitszeiten als Arbeitsstunden nach Vereinsflieger — **endgültig**,
  Vereinsflieger kann eine gebuchte Stunde weder ändern noch löschen.
  Kategorie und Status („nicht bewertet" oder „akzeptiert") sind
  Einstellungen; die **Kategorie** wird nach dem ersten Abgleich als
  Auswahlliste angeboten, gelesen aus Vereinsflieger. „Akzeptiert" sperrt
  den Eintrag drüben für das *Mitglied* — die Abzeichner des Vereins können
  ihn weiterhin bearbeiten. Der auditierbare Nachweis bleibt immer
  Aeronance; die Buchung drüben ist eine Zweitschrift für die
  Vereinsbuchhaltung.

---

## 15. Betrieb: Sicherung, Updates, Notzugang

Dieses Kapitel richtet sich an die Person, die den Server betreibt.

### 15.1 Sicherung und Wiederherstellung

```
php artisan aeronance:backup                 # Datenbank + Dokumente
php artisan aeronance:backup --keep=30       # und alte Sicherungen aufräumen
```

Es entstehen zwei Dateien mit demselben Zeitstempel in
`storage/app/backups`: der Datenbank-Dump (`db-JJJJMMTT-HHMMSS.sql.gz`) und
das Dokumentenarchiv (`documents-….zip`) — der Dump allein ist keine
Sicherung eines Systems, dessen Form-1-Scans die Nachweise sind. Mit
eingeschalteter Verschlüsselung enden beide auf `.enc`.

Der tägliche Lauf (05:00) ist eingeplant; mit eingerichteter **Auslagerung**
wandert jede Sicherung zusätzlich auf das zweite Ziel — unverschlüsselt
verlässt dabei nichts das System.

**Wiederherstellen** (gehört einmal geübt, nicht am Ernstfall gelernt):

```
php artisan aeronance:restore storage/app/backups/db-JJJJMMTT-HHMMSS.sql.gz \
    --documents=storage/app/backups/documents-JJJJMMTT-HHMMSS.zip
# verschlüsselte Sicherungen: --passphrase=… bzw. --private-key=… [--key-passphrase=…]
# vorhandene Dokumente überschreiben: --force
```

Der Befehl erkennt selbst, ob und wie eine Datei verschlüsselt ist, und
sagt, was fehlt. Danach Caches neu aufbauen (`php artisan optimize:clear`).

### 15.2 Updates

```
sudo -u www-data deploy/update.sh            # neuestes Release
sudo -u www-data deploy/update.sh v1.2.0     # bestimmte Fassung
```

Das Skript erkennt die Installationsart selbst: **Tarball-Installationen**
(Webserver-Pack, LXC — der Normalfall) laden das Release aus den
GitHub-Releases und prüfen dessen Signatur gegen den mitgelieferten
Schlüsselbund; **Git-Installationen** (Entwicklung) holen das signierte Tag.
In beiden Fällen gilt dieselbe Reihenfolge: beschaffen und prüfen →
**Sicherung** (schlägt sie fehl, wird nicht aktualisiert) → Wartungsmodus →
Code → Voraussetzungen → Migrationen → Caches → Worker-Neustart →
Wartungsmodus aus (in jedem Fall, auch bei Abbruch). `.env` und `storage/`
bleiben unberührt.

**Docker:**

```
deploy/docker/update.sh v1.2.0
```

Gleiche Reihenfolge, ein Unterschied: Das Image wird *zuerst* geholt —
bricht das ab, läuft die alte Installation unverändert weiter. Kein
Watchtower, kein `latest`: Ein Dienst, der Images selbst zieht, aktualisiert
ohne Sicherung, ohne Wartungsmodus und ohne Migration.

**Selbsttätig:** `AERONANCE_AUTO_UPDATE=true` in der `.env` plus der
mitgelieferte systemd-Timer (im LXC bereits installiert):

```
install -m 644 deploy/aeronance-update.{service,timer} /etc/systemd/system/
systemctl daemon-reload && systemctl enable --now aeronance-update.timer
```

Der Timer läuft nachts (03:30, mit Zufallsverzögerung) und ruft das
reguläre Update-Skript — automatisiert wird der Ablauf, nicht der Weg daran
vorbei. Während 0.0.x verweigert er sich (Vorabstände dürfen brechen);
`AERONANCE_AUTO_UPDATE_PRERELEASE=1` hebt das bewusst auf. Für Docker liegen
die Units unter `deploy/docker/` und gehören auf den Wirt.

Ob es etwas Neues gibt, zeigt das Dashboard (für Inhaber von
`core.settings.manage`) — die Anwendung schaut nur nach und installiert
nichts. Abschalten: `AERONANCE_UPDATE_CHECK=false`.

### 15.3 Notzugang (Break-glass)

Wenn kein Administrator mehr hineinkommt — nur an der Konsole, absichtlich
nicht in der Oberfläche:

```
php artisan aeronance:break-glass admin@verein.de --reason="Adminkonto gesperrt, Ursache X" --hours=4
```

Der Protokolleintrag (wer, von wo, warum, bis wann) wird geschrieben,
*bevor* die Admin-Rolle vergeben wird; andere Administratoren werden
benachrichtigt. **Der Zugang erlischt nach Ablauf der Stunden von selbst** —
der Scheduler zieht abgelaufene Gewährungen alle fünf Minuten zurück. Früher
beenden — und der verlässliche Weg, falls ausgerechnet der Scheduler zu den
kaputten Dingen gehört:

```
php artisan aeronance:break-glass-revoke <nummer>
```

Die Nummer nennt der Befehl beim Gewähren; der Datensatz bleibt erhalten.

### 15.4 Was nachts läuft

| Zeit | Lauf | Zweck |
|---|---|---|
| alle 5 min | `aeronance:break-glass-expire` | abgelaufene Notzugänge zurückziehen |
| 02:00 | `aeronance:vereinsflieger-sync` | Mitglieder, Betriebszeiten, Arbeitsstunden |
| 03:30 | systemd-Timer `aeronance-update` | selbsttätiges Update (nur wenn eingeschaltet) |
| 03:40 | `aeronance:update-check` | Update-Hinweis fürs Dashboard vorbereiten |
| 04:00 | `aeronance:expire-stock` | abgelaufene Lose auf „unbrauchbar" |
| 04:30 | `aeronance:retention` | Aufbewahrungsregeln (nur die eingeschalteten) |
| 05:00 | `aeronance:backup` | Sicherung, ggf. mit Auslagerung |
| 07:30 | `aeronance:remind-orders` | Erinnerung an überfällige Lieferungen |
| So 03:00 | `aeronance:refresh-directives` | LTA/TM-Listen der Hersteller |

Voraussetzung ist der laufende Scheduler (`aeronance-scheduler.service`
bzw. der `scheduler`-Container).

---

## 16. Kommandoreferenz

Alle Artisan-Befehle laufen im Installationsverzeichnis, im Regelfall als
Webserver-Benutzer: `sudo -u www-data php artisan …`. In Docker:
`docker compose exec app php artisan …`.

### Kern

```
aeronance:requirements
```
Prüft PHP-Version und -Extensions, MariaDB (und dass es wirklich MariaDB
ist) und `pdftotext`. Exit ≠ 0, wenn etwas fehlt.

```
aeronance:backup [--path=VERZEICHNIS] [--database-only] [--keep=10]
```
Sichert Datenbank und Dokumente. `--path` (Vorgabe `storage/app/backups`),
`--database-only` lässt die Dokumente aus, `--keep` behält die letzten N
Sicherungen (0 = alle). Verschlüsselung und Auslagerung folgen den
Einstellungen.

```
aeronance:restore DUMP [--documents=ZIP] [--passphrase=…]
                  [--private-key=PFAD] [--key-passphrase=…] [--force]
```
Stellt aus einer Sicherung wieder her. Erkennt Verschlüsselung selbst;
`--force` überschreibt vorhandene Dokumente. Danach Caches neu aufbauen.

```
aeronance:break-glass E-MAIL [--reason="…"] [--hours=4]
aeronance:break-glass-expire
aeronance:break-glass-revoke ID
```
Notzugang gewähren, abgelaufene Gewährungen zurückziehen (läuft planmäßig
alle fünf Minuten), bzw. eine Gewährung von Hand beenden (Kapitel 15.3).
Ohne `--reason` wird interaktiv gefragt; ohne Grund bricht der Befehl ab.

```
aeronance:retention [--dry-run]
```
Wendet die eingeschalteten Aufbewahrungsregeln an (Aktivitätsprotokoll,
Break-glass-Protokoll, Pseudonymisierung Ehemaliger). `--dry-run` berichtet
nur.

```
aeronance:mail-test EMPFÄNGER
```
Verschickt eine Testmail und meldet, was der Mailserver geantwortet hat.

```
aeronance:sync-access
```
Legt fehlende Rollen und Rechte an (additiv, beliebig wiederholbar — nimmt
nichts weg).

```
aeronance:update-check [--fresh] [--tag]
```
Prüft, ob eine neuere Fassung veröffentlicht wurde. `--fresh` übergeht den
Zwischenspeicher; `--tag` ist die Skript-Schnittstelle: genau eine Zeile mit
der Zielfassung, wenn es eine gibt, sonst nichts. Ohne `--tag`: Exit 1,
wenn es Neueres gibt; „keine Auskunft" (kein Internet) ist kein Fehler.

### Module

```
aeronance:vereinsflieger-sync
```
Der nächtliche Abgleich von Hand (Mitglieder, Betriebszeiten,
Arbeitsstunden). Abgeschaltetes Modul oder fehlender Zugang ist ein Hinweis,
kein Fehler.

```
aeronance:refresh-directives [--source=QUELLE …] [--model=MUSTER …]
                             [--all-types] [--dry-run]
```
Holt die LTA/TM-Listen. Ohne Optionen: alle automatischen Quellen, begrenzt
auf die Muster der eigenen aktiven Flotte. `--all-types` holt alles, was
eine Quelle anbietet (viele Abrufe); `--dry-run` berichtet nur.

```
aeronance:expire-stock [--dry-run]
aeronance:remind-orders [--force]
```
Lager-Nachtläufe von Hand: abgelaufene Lose als unbrauchbar erfassen bzw.
an überfällige Bestellungen erinnern (`--force` übergeht den
Erinnerungsabstand).

### Deploy-Skripte

```
deploy/update.sh [vX.Y.Z]              # Update (Tarball- oder Git-Installation)
deploy/auto-update.sh                  # wie der Timer: prüfen, ggf. update.sh
deploy/docker/update.sh vX.Y.Z         # Docker-Update (Tag ist Pflicht)
deploy/docker/auto-update.sh           # Docker-Automatik, läuft auf dem Wirt
deploy/publish.sh vX.Y.Z [--dry-run]   # Veröffentlichen (nur für Maintainer)
```

Nützliche Umgebungsvariablen: `AERONANCE_BACKUP_DIR` (Sicherungsziel des
Updates), `AERONANCE_RELEASE_URL` (eigener Release-Spiegel),
`AERONANCE_SKIP_SIGNATURE=1` (Signaturprüfung bewusst überspringen —
nicht empfohlen), `AERONANCE_AUTO_UPDATE`, `AERONANCE_AUTO_UPDATE_PRERELEASE`.

### systemd-Units (Vorlagen in `deploy/`)

| Unit | Aufgabe |
|---|---|
| `aeronance-worker.service` | Queue-Worker (verarbeitet Hintergrundjobs) |
| `aeronance-scheduler.service` | startet die geplanten Läufe minütlich |
| `aeronance-update.timer` + `.service` | selbsttätiges Update, nachts 03:30 |

Installation jeweils:

```
install -m 644 deploy/<unit> /etc/systemd/system/
systemctl daemon-reload && systemctl enable --now <unit>
```

### Wiederherstellung von Hand (Notfallweg)

```
zcat storage/app/backups/db-JJJJMMTT-HHMMSS.sql.gz | mariadb -u BENUTZER -p DATENBANK
unzip storage/app/backups/documents-JJJJMMTT-HHMMSS.zip -d storage/app/
```
