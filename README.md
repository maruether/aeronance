# Aeronance

**Werkstatt- und Lagerverwaltung für Luftsportvereine.**
Selbstgehostet, modular, Open Source.

> **Vorabstand 0.0.x.** Läuft und ist getestet, aber bis 0.1.0 sind Brüche
> jederzeit möglich.

---

## Worum es geht

Vereinswerkstätten stehen vor demselben Problem wie große Instandhaltungs­betriebe
— Rückverfolgbarkeit von Teilen, Nachweisführung, Lagerhaltung mit Verfallsdaten
—, nur ohne deren Budget und Personal. Fertige MRO-Software ist für einen Verein
zu groß und zu teuer; Tabellenkalkulationen sind es nicht.

Aeronance soll diese Lücke schließen: klein genug für einen Verein, der nur sein
Ersatzteillager verwalten will, und ausbaufähig bis in die Nähe eines kleinen
Part-145-Betriebs. **Modularität ist deshalb keine Zugabe, sondern die
Kernanforderung** — jedes Modul lässt sich einzeln aktivieren, und der Kern läuft
auch ganz ohne.

---

## Die Module

Der **Kern** trägt, was jede Installation braucht: Benutzer und Anmeldung, Rollen
und Rechte, Qualifikationen, Audit-Trail, Dokumentenablage, Einstellungen,
Modulverwaltung, Sicherung und Wiederherstellung. Er läuft ohne jedes Modul.

Darüber liegen die Module. Jedes bringt eigene Tabellen, Rechte und Bildschirme
mit und lässt sich einzeln an- und abschalten — **Abschalten löscht keine Daten**,
es blendet Funktionen aus und stoppt die Hintergrundarbeit des Moduls.

### 🔩 Lager

Ersatzteile, Lagerorte, Lose und Bestandsbewegungen — mit Rückverfolgbarkeit vom
Teil bis zum Nachweis.

Zwei Führungsarten, entschieden vom Bauteiltyp: **Sammelbestand** für Muttern und
Schrauben, **losgeführt** für alles mit Form 1, Lagerzeit oder Seriennummer. Ohne
Form 1 wird ein nachweispflichtiges Teil gar nicht erst eingebucht — es bleibt im
Wareneingang. Die Losnummer *ist* die Form-1-Nummer, wo es eine gibt.

Dazu Wareneingang, Entnahme mit FEFO-Vorschlag, Inventur mit Zählliste,
Umlagerung, Sperren mit gedrucktem Sperrzettel, Ausmusterung und der Weg zur
Instandsetzung und zurück.

*Steht allein — verlangt kein anderes Modul.*

### ✈️ Flotte

Luftfahrzeuge, Betriebszeiten, eingebaute Komponenten und Fristen. Führt die
Laufzeiten, die im Lager bewusst nicht geführt werden.

Musterdaten mit Kennblatt- und TCDS-Nummern, Halter, Lebenslaufakte, Wägungen,
Nachprüfungen, Fälligkeitsübersicht. Was ein Luftfahrzeug an offenen Punkten hat,
sammeln die Module gemeinsam ein: Die Flotte kennt Papiere und Grenzen, mit
Arbeitskarten kommen offene Befunde dazu, mit Freigaben eine fehlende CRS.

*Steht allein. Zusammen mit dem Lager schließt sich die Kette Nachweis → Los →
Teil → Luftfahrzeug in beide Richtungen.*

### 📋 Arbeitskarten

Vorgänge, Arbeitskarten, Befunde und Arbeitszeiten. Liefert die Datenbasis für das
Erfahrungslogbuch nach Part-66.

Ein Vorgang bündelt einen Werkstattbesuch; die Karten darin tragen die
Part-66-Felder von Anfang an: Datum, Kennzeichen, Muster, ATA-Kapitel,
Tätigkeitsart, Dauer, ausgeführt oder unterstützt, freigebende Person. Befunde
sind eigene Datensätze — man dreht eine Schraube heraus und sieht einen Riss; das
gehört nicht zu der Karte, die man gerade bearbeitet, und verschwindet nicht mit
ihr. Freigaben frieren ein, was jemand unterschrieben hat.

*Verlangt: Flotte. Mit dem Lager kommt die Teileentnahme direkt auf die Karte.*

### 📖 Erfahrungslogbuch (Part-66)

Erfahrungsnachweis nach Part-66, abgeleitet aus den Arbeitskarten. **Führt nichts
eigenes — es liest.**

Recency nach 66.A.20(b), Lizenzkategorien mit ihren Abstufungen (Zellenbauweise,
Antriebsart, Avionik) und Einschränkungen wie „non-complex". Das Logbuch ist eine
Auswertung, keine zweite Pflege.

*Verlangt: Arbeitskarten und Flotte.*

### 📄 LTA / TM

Lufttüchtigkeitsanweisungen und Technische Mitteilungen — die Übersicht, die
zeilenweise bestätigt wird. **Die Liste wird länger, nie kürzer.**

48 Herstellerquellen werden wöchentlich abgerufen und in eine gemeinsame Liste
gebracht. Was hereinkommt, ist zunächst *unbewertet* — und eine unbewertete
Anweisung blockiert die Freigabe, bis eine qualifizierte Person sie angesehen hat.
Der schlimmste Fall eines übereifrigen Abrufs ist damit eine Aufgabe, nie eine
falsche Antwort.

*Verlangt: Flotte.*

### 🔗 Vereinsflieger

Anmeldung und Mitgliederabgleich über die Vereinsflieger-API, Betriebszeiten der
Luftfahrzeuge, Rückweg für Arbeitsstunden.

Mehrere Vereine lassen sich gleichzeitig anbinden — gedacht fürs CAO-Umfeld, wo
ein Betrieb Luftfahrzeuge mehrerer Vereine betreut und ihre Stunden laufend sehen
soll, statt nachzufragen. **Nur eine Anbindung darf Benutzerkonten anlegen**; die
übrigen liefern ausschließlich Betriebszeiten.

Was der Connector ausdrücklich *nicht* überträgt, ist die Freigabeberechtigung:
Vereinsfunktion und Werkstattqualifikation sind zweierlei, und der Kern verhindert
diese Zuordnung.

*Steht allein.*

### 📦 Eingangsprüfung

Angelieferte Ware ist nicht sofort verwendbar: Das Los wird beim Wareneingang
gesperrt und erst durch die unterschriebene Prüfung freigegeben.

Die Checkliste steht fest im Code — Teilenummer, Menge, Bescheinigung, Aussteller,
Kennzeichnung am Teil, Zustand, Restlaufzeit —, und gefragt wird nur, was zur
Lieferung passt. Die Annahme läuft über die Lagerfreigabe und erbt deren
Qualifikationspflicht; Zurückweisen bewegt nichts, die Ware bleibt gesperrt.
Sammelbestand ohne Los lässt sich nicht sperren: Dort ist die Prüfung ein
Nachweis.

*Verlangt: Lager.*

### 🔧 Werkzeuge

Werkzeugbestand mit Kalibrierfristen — und dem Zeitraum, dessen Arbeit eine
Kalibrierung in Frage stellt.

Jeder Schein trägt seinen Befund. Kam das Werkzeug **außer Toleranz** an, reicht
der Nachprüfzeitraum zurück bis zur letzten Messung mit gutem Befund; war es nur
**zu spät** kalibriert, ab dem Fälligkeitsdatum. Beides verlangt eine
dokumentierte Bewertung und wäre mit dem nächsten Schein sonst verschwunden.
Intervalle laufen über die Zeit; wo eine Norm zusätzlich eine Nutzungsgrenze
kennt, steht sie im Feld „Grundlage".

*Steht allein.*

---

## Installation

### Voraussetzungen

| | Mindestens | Anmerkung |
|---|---|---|
| PHP | **8.4** | mit `pdo_mysql`, `intl`, `gd`, `zip`, `bcmath`, `fileinfo` |
| MariaDB | **10.11 LTS** | **ausschließlich** — kein PostgreSQL, kein SQLite, kein MySQL |
| Webserver | nginx oder Apache | Document-Root auf `public/` |
| `pdftotext` | poppler-utils | nur mit dem LTA-Modul: liest die Herstellerlisten |

MySQL ist **nicht** unterstützt: 8.0 ist seit April 2026 am Ende, und 8.4+ driftet
zunehmend von MariaDB weg. Wer keinen MariaDB-Zugang hat, nimmt den Docker- oder
LXC-Weg.

Ein **Shared Webspace reicht nicht.** Aeronance setzt langlaufende Prozesse voraus
(Queue-Worker, Scheduler) und damit einen eigenen Server oder Container.

Ob eine Maschine bereit ist, sagt die Anwendung selbst:

```bash
php artisan aeronance:requirements
```

### Weg 1 — Eigener Server

*Der Weg, der heute funktioniert.*

```bash
git clone https://github.com/maruether/aeronance.git /var/www/aeronance
cd /var/www/aeronance

composer install --no-dev --optimize-autoloader
npm ci && npm run build

cp .env.example .env
php artisan key:generate
# .env: DB_* eintragen, APP_URL setzen

php artisan migrate --force
php artisan storage:link
chown -R www-data:www-data storage bootstrap/cache
```

Beispielkonfigurationen liegen in [`deploy/`](deploy/): `nginx.conf`,
`apache.conf` und zwei systemd-Units für Worker und Scheduler. Der Document-Root
zeigt auf `public/`, **nie** auf das Projektverzeichnis.

### Weg 2 — Docker

> **Braucht ein veröffentlichtes Image.** Das Compose-Setup zieht
> `AERONANCE_IMAGE`, und das Dockerfile baut bewusst **aus dem
> Release-Tarball**, nicht aus dem Repo — damit alle Kanäle dasselbe Artefakt
> bekommen.
>
> Die Tag-Pipeline baut und prüft das Image bereits, **veröffentlicht es aber
> noch nicht**: Solange kein Ziel hinterlegt ist, wird es nach der Prüfung
> verworfen. Bis dahin lässt es sich aus einem Tarball von Hand bauen, siehe
> unten.

```bash
cd deploy/docker
cp .env.docker.example .env         # Passwörter, Port, Bind-Adresse setzen
docker compose up -d
```

Enthalten sind App, nginx, MariaDB, Queue-Worker und Scheduler; persistente
Volumes für Datenbank und `storage/`. **Nur ein Port wird veröffentlicht** —
MariaDB ist ausschließlich im Container-Netz erreichbar.

| Variable | Vorgabe | Zweck |
|---|---|---|
| `AERONANCE_PORT` | `8080` | der Port auf dem Wirt |
| `AERONANCE_BIND` | `0.0.0.0` | `127.0.0.1` hinter einem Reverse Proxy |
| `AERONANCE_IMAGE` | — | auf ein **getaggtes** Release zeigen, nicht auf `latest` |
| `AERONANCE_AUTO_UPDATE` | `false` | nächtlich selbsttätig aktualisieren, siehe unten |

**Image von Hand bauen**, solange nichts veröffentlicht ist — der Tarball kommt
aus dem `pack`-Job einer Tag-Pipeline:

```bash
cp aeronance-vX.Y.Z.tar.gz deploy/docker/release.tar.gz
docker build -t aeronance:local --build-arg RELEASE=release.tar.gz deploy/docker
# in .env: AERONANCE_IMAGE=aeronance:local
```

### Weg 3 — Proxmox LXC

```bash
bash -c "$(curl -fsSL https://raw.githubusercontent.com/maruether/aeronance/master/deploy/lxc/aeronance.sh)"
```

Erstellt den Container, installiert nginx, PHP-FPM und MariaDB, holt das Release
und übergibt an den Setup-Assistenten — nach den Konventionen der
Proxmox-VE-Community-Scripts. Braucht eine veröffentlichte Release-URL.

### Erste Schritte nach der Installation

Der **Setup-Assistent** führt durch Datenbankverbindung, Migrationen,
Administratorkonto, Organisationsname und Modulauswahl. Danach verriegelt er sich
dauerhaft: Im installierten Zustand sind die Setup-Routen nicht mehr erreichbar —
offen liegende Install-Routen sind ein klassisches Einfallstor.

Module lassen sich jederzeit unter *Verwaltung → Module* nachträglich einschalten;
die Tabellen sind ohnehin alle angelegt.

### Aktualisieren

Für den eigenen Server und den LXC:

```bash
./deploy/update.sh v1.2.0
```

Für Docker:

```bash
deploy/docker/update.sh v1.2.0
```

Beide in derselben Reihenfolge: Sicherung (Datenbank und Dokumente) →
Wartungsmodus → neuer Stand → Migrationen → Caches → Wartungsmodus aus. `.env`
und `storage/` bleiben unberührt.

**Beim Docker-Weg genügt `docker compose pull` nicht.** Der Entrypoint spiegelt
nur `public/`, er migriert nicht — nach einem reinen Image-Wechsel liefe die
neue Anwendung gegen das alte Schema. Genau diese Lücke schließt das Skript.

#### Selbsttätig aktualisieren

Geht in **allen drei Wegen** und ist ab Werk **aus**. Eine Zeile in der `.env`
schaltet es ein:

```
AERONANCE_AUTO_UPDATE=true
```

Dazu der mitgelieferte systemd-Timer — im LXC ist er bereits installiert:

```bash
install -m 644 deploy/aeronance-update.{service,timer} /etc/systemd/system/
systemctl daemon-reload && systemctl enable --now aeronance-update.timer
```

Für Docker liegen die Units unter `deploy/docker/` und gehören auf den **Wirt**,
nicht in einen Container: Ein Updater-Container bräuchte den Docker-Socket, und
wer den hat, ist auf dem Wirt root.

**Automatisiert wird der Ablauf, nicht der Weg daran vorbei.** Der Timer ruft
nicht `docker pull`, sondern das reguläre Update-Skript — mit Signaturprüfung,
Sicherung, Wartungsmodus und Migration. Genau das unterscheidet ihn von
Watchtower und Ähnlichem, das neue Images zieht und alle drei Schritte
überspringt.

Nachts um halb vier, mit Zufallsverzögerung, damit nicht jede Installation zur
selben Minute dieselbe API fragt. Läuft nichts an, endet der Lauf still.

> **Während 0.0.x weigert es sich.** Vor 0.1.0 sind Brüche zwischen zwei
> Fassungen ausdrücklich erlaubt — die will niemand nachts um halb vier
> bekommen. Wer es trotzdem will: `AERONANCE_AUTO_UPDATE_PRERELEASE=1`.

---

## Betrieb

### Was von selbst läuft

| Zeit | Aufgabe |
|---|---|
| 02:00 | Vereinsflieger: Mitglieder, Betriebszeiten, Arbeitsstunden |
| 04:00 | Lager: abgelaufene Lose sperren |
| 04:30 | Aufbewahrungsfristen |
| 05:00 | Sicherung, mit Auslagerung |
| So 03:00 | LTA/TM der Hersteller abrufen |

Dafür muss der Scheduler laufen — als systemd-Unit aus `deploy/` oder im
Docker-Compose enthalten.

### Sicherungen

```bash
php artisan aeronance:backup                    # Datenbank + Dokumente
php artisan aeronance:restore <datei>           # zurückspielen
```

**Verschlüsselt, wenn es das Haus verlässt.** Zwei Verfahren: ein öffentlicher
Schlüssel (empfohlen — der private liegt dann nicht auf dem Server) oder ein
Passwort. Die Auslagerung per SFTP oder S3 verweigert den Dienst, wenn keine
Verschlüsselung eingestellt ist.

### Nützliche Befehle

| Befehl | Zweck |
|---|---|
| `aeronance:requirements` | prüft, ob die Maschine alles mitbringt |
| `aeronance:sync-access` | fehlende Rollen und Rechte anlegen (additiv) |
| `aeronance:break-glass <mail>` | Notfallzugang gewähren, wird protokolliert |
| `aeronance:break-glass-revoke <id>` | Notfallzugang zurücknehmen |
| `aeronance:refresh-directives` | LTA/TM sofort abrufen |
| `aeronance:vereinsflieger-sync` | Vereinsflieger sofort abgleichen |

---

## Dokumentation

| Dokument | Inhalt |
|---|---|
| [`docs/ANALYSE.md`](docs/ANALYSE.md) | Fachliche Analyse des Vorgängersystems, regulatorischer Rahmen nach VO (EU) 1321/2014, Architekturentscheidungen, offene Fragen |
| [`docs/INFRASTRUKTUR.md`](docs/INFRASTRUKTUR.md) | Modul-Infrastruktur: Abwägung, Entscheidung, Umsetzung |
| [`docs/LAGERMODUL.md`](docs/LAGERMODUL.md) | Datenmodell und Fachregeln des Lagermoduls |
| [`docs/FLOTTE.md`](docs/FLOTTE.md) | Luftfahrzeuge, Komponenten, Fristen |
| [`docs/ARBEITSKARTEN.md`](docs/ARBEITSKARTEN.md) | Vorgänge, Karten, Befunde, Freigaben |
| [`docs/PART66.md`](docs/PART66.md) | Erfahrungslogbuch und Recency |
| [`docs/LTA-TM.md`](docs/LTA-TM.md) | Herstellerquellen und Bewertungsablauf |
| [`docs/IDENTITAETEN.md`](docs/IDENTITAETEN.md) | Externe Identitäten, Rollenzuordnung, Vereinsflieger |
| [`docs/KONFIGURATION.md`](docs/KONFIGURATION.md) | Einstellungen, Rangfolge, Sicherungen |
| [`docs/AUSGEBAUTE-TEILE.md`](docs/AUSGEBAUTE-TEILE.md) | Recherche zur Wiederverwendung ausgebauter Teile |
| [`docs/INVENTURBERICHT.md`](docs/INVENTURBERICHT.md) | Inventurbericht und Zählliste |
| [`docs/GLOSSAR.md`](docs/GLOSSAR.md) | Deutsche Fachbegriffe ↔ englische Bezeichner |

Wer verstehen will, *warum* etwas so gebaut ist, findet die Begründung in der
Analyse — sie ist bewusst als Entscheidungsdokument geführt und nicht als
Protokoll.

---

## Regulatorischer Hinweis

Aeronance orientiert sich an den Anforderungen der Verordnung (EU) 1321/2014
(Part-ML, Part-CAO). **Es ist damit kein zugelassenes System und ersetzt keine
Prüfung durch die zuständige Behörde.** Die Verantwortung für die Einhaltung der
Regularien bleibt beim Betreiber; die Software soll die Nachweisführung
unterstützen, nicht sie garantieren.

## Mitmachen

Beiträge sind willkommen — besonders von Leuten, die selbst in einer
Vereinswerkstatt stehen. Wie, steht in [CONTRIBUTING.md](CONTRIBUTING.md);
zwei Regeln vorweg: **alles wird signiert**, und die Datenbank ist **MariaDB**.

Sicherheitslücken bitte **nicht** als Issue, sondern an
[security@aeronance.de](mailto:security@aeronance.de) — die Einzelheiten stehen
in [SECURITY.md](SECURITY.md), auch die Regeln fürs Ausprobieren.

## Lizenz

[AGPL-3.0](LICENSE). Kein CLA — jeder Beitragende behält sein Copyright.
