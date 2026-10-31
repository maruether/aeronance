# CLAUDE.md — Aeronance

**Stand dieses Dokuments: v0.2.2 (2026-10-22).** Die Abschnitte „Stand" und
„Arbeitsweise" werden bei jedem Release nachgezogen; die Leitplanken ändern
sich nur durch eine festgehaltene Entscheidung.

## Projektkontext

Webbasiertes, selbstgehostetes Werkstattverwaltungssystem (MRO), gedacht als
**Open-Source-Projekt für Luftsportvereine**. Referenzinstallation ist die
Akaflieg Freiburg e.V.; die Architektur skaliert vom kleinen Verein (nur
Lager) bis zum kleinen Part-145-Betrieb. Ein voller 145-Stack ist für die
meisten Vereine zu groß — deshalb ist Modularität keine Nice-to-have, sondern
Kernanforderung.

**Woher das kommt, und warum es die Prioritäten erklärt.** Die ursprüngliche
Anforderung war: *„Ich habe ein halbfertiges Lagertool und will einen besseren
Weg, mein Part-66-Log zu führen."* Das Erfahrungslogbuch ist also nicht der
letzte Punkt einer Liste, sondern **das eigentliche Ziel** — Lager, Flotte und
Arbeitskarten sind das Gerüst, das nötig ist, um es sauber abzuleiten. Wo eine
Entscheidung zwischen „bequem jetzt" und „später auswertbar" steht, gewinnt
die Auswertbarkeit.

Dieses Repo ist die Neuimplementierung. Der Vorgänger — ein handgeschriebenes
PHP/JS-Lagerverwaltungstool — liegt weiterhin unter `../clubwarehouse/` und
dient nur noch als Nachschlagewerk; sein Domänenwissen ist vollständig in
[`docs/ANALYSE.md`](docs/ANALYSE.md) überführt. **Nichts aus dem Bestandscode
wird übernommen, ohne dass es dort begründet steht.**

**Der Betreiber heißt im Code und in der Oberfläche *Organisation*, nicht
Verein** — ein Part-145-Betrieb ist kein Verein. „Verein" bleibt nur als
Halterart eines Luftfahrzeugs und in Fließtexten, wo ein Verein das konkrete
Beispiel ist. Begründung in [`docs/GLOSSAR.md`](docs/GLOSSAR.md).

**Wichtig:** Der Betreiber ist Laravel-Neuling (PHP-Erfahrung vorhanden).
Framework-Konzepte (Migrations, Eloquent, Service Container, Filament
Resources, Queues) beim ersten Auftreten in 1–2 Sätzen erklären, nicht als
bekannt voraussetzen.

---

## Stand — was gebaut ist

Phase 1 (Analyse des Bestandscodes) und Phase 2 (Grundgerüst) sind
abgeschlossen; seit v0.1.0 läuft das System im Feldtest bei der Akaflieg.
Technik: **Laravel 13, Filament 5, PHP ≥ 8.4, MariaDB ≥ 10.11.** Laufzeit-
abhängigkeiten außerdem `poppler-utils` (Kennblätter, LTA-Listen) und
`mariadb-client` (Sicherung); PDF-Erzeugung über `dompdf` (reines PHP, braucht
`ext-iconv`).

**Kern** (`app/Core/`): lokales Login mit optionaler 2FA, Rollen und Rechte
(`spatie/laravel-permission`), Qualifikationen als eigenes Konzept (E8),
append-only Audit-Trail (`spatie/laravel-activitylog`), Dokumentenablage mit
Virenprüfung (`spatie/laravel-medialibrary`, optional ClamAV), Einstellungen,
Modulverwaltung mit Abhängigkeitsauflösung, Erst-Setup-Assistent mit
dauerhafter Verriegelung, Break-glass über die Konsole, Backup/Restore mit
Offsite-Kopie (SFTP/S3), Update-Prüfung gegen die GitHub-Tags,
Retention-Jobs, Demomodus, Security-Header-Middleware, Identity-Provider-
Schnittstelle mit providerunabhängiger Rollenzuordnung (E4).

**Module** (`app/Modules/`), jedes ein Filament-Plugin mit eigenem Manifest:

| Modul | Schlüssel | `requires` | `provides` | Doku |
|---|---|---|---|---|
| Lager | `warehouse` | — | — | `LAGERMODUL.md`, `INVENTURBERICHT.md`, `AUSGEBAUTE-TEILE.md` |
| Flotte | `fleet` | — | `aircraft-register` | `FLOTTE.md`, `MUSTER-KENNBLATT.md`, `KOMPONENTENMUSTER.md` |
| LTA / TM | `directives` | `fleet` | — | `LTA-TM.md` |
| Arbeitskarten + Freigabe | `taskcards` | `fleet` | `maintenance-record` | `ARBEITSKARTEN.md` |
| Eingangsprüfung | `inspection` | `warehouse` | — | — |
| Werkzeuge | `tooling` | — | — | — |
| Part-66-Logbuch | `part66` | `taskcards`, `fleet` | — | `PART66.md` |
| Vereinsflieger | `vereinsflieger` | — | `identity-provider` | `IDENTITAETEN.md`, `ANALYSE.md` §6 |
| Abrechnung | `billing` | `taskcards`, `fleet` | — | `ABRECHNUNG.md` |

`provides` ist eine Fähigkeit, keine Voraussetzung: Arbeitskarten entnehmen
Teile nur, wenn das Lager aktiv ist, und laufen ohne. LDAP/AD und OIDC sind
als Identity-Provider vorgesehen, aber nicht gebaut und nicht dringlich.

**Rollen** (`App\Core\Access\CoreRoles`): `admin`, `workshop_manager`,
`certifying_staff`, `mechanic`, `member`. Keine davon hat alle Rechte; Rechte
sind tätigkeitsbezogen und werden vom jeweiligen Modul deklariert. Wer
freigeben darf, entscheidet nicht die Rolle, sondern die Qualifikation
(Part-66-Lizenz oder Pilot-Owner-Berechtigung je Luftfahrzeug).

**Dokumentation**, und wo was steht:

- [`HANDBUCH.md`](HANDBUCH.md) — Benutzerhandbuch, alle Module und Abläufe,
  Kommandoreferenz. Wird mit dem Release ausgeliefert.
- [`CHANGELOG.md`](CHANGELOG.md) — Keep-a-Changelog; jede Fassung nennt
  unter „Beim Update beachten", was eine Installation tun muss. Der Abschnitt
  „Vorgemerkt" ist die einzige Roadmap.
- [`docs/`](docs/) — Entscheidungsdokumente (nur im Repo, nicht im Release).
  `ANALYSE.md` ist die verbindliche Grundlage für das Datenmodell,
  `INFRASTRUKTUR.md` für Modulschnitt, CI und Deploy (D1–D8, §8–§11),
  `GLOSSAR.md` die Brücke zwischen deutscher UI und englischem Code.
- [`deploy/README.md`](deploy/README.md) — vhost-Vorlagen, systemd, Docker,
  LXC, Veröffentlichen, Release schneiden.
- [`CONTRIBUTING.md`](CONTRIBUTING.md), [`SECURITY.md`](SECURITY.md).

**Offen** (Stand v0.2.2):

- **Ultraleichtflugzeuge** in der Flotte — eigener Zuschnitt statt
  aufgebogener EASA-Felder; wird vor dem Bau entschieden und im CHANGELOG
  begründet.
- `docs/ISSUES.md` (lokal, nicht im Repo): weitere Hersteller-Logins für
  LTA-Quellen sind vertagt.
- **LDAP/AD** als Identity-Provider hat niedrige Priorität (2026-10-30);
  Vereinsflieger reicht für die absehbaren Installationen.
- Die LXC-Skripte sind `shellcheck`-geprüft, aber nie auf einem Proxmox-Host
  gelaufen; die Einreichung bei den Community-Scripts steht aus.
- Was je Modul noch fehlt, steht am Ende des jeweiligen Dokuments in `docs/`.

---

## Modularer Aufbau — Kern + einzeln aktivierbare Module

Der Kern muss ohne jedes Modul lauffähig sein, jedes Modul einzeln
deaktivierbar, ohne dass der Rest bricht. `ModuleBoundaryTest` prüft das,
auch für Übersetzungen. Die Detailentscheidungen D1–D8 stehen in
`docs/INFRASTRUKTUR.md` §6; die wichtigsten:

- Migrationen laufen immer, auch für inaktive Module (D1).
- Modulzustand liegt in der Datenbank, die Modulliste explizit in
  `config/aeronance.php` — kein Verzeichnis-Scan (D2, D5).
- Deaktivieren blendet aus und stoppt Jobs, **löscht aber keine Daten**.
  Deinstallieren gibt es nicht (D3).
- Optionale Zusammenarbeit über Events und `provides`, nicht über das
  Manifest-`requires` (D6, D7).

### Warum die Freigabe kein eigenes Modul ist

Ursprünglich war sie eines. Nach dem Bau der Nachbarmodule sprachen drei
Dinge dagegen, zugestimmt am 2026-07-30:

1. **Sie ist nicht abwählbar.** Nach ML.A.801 braucht jede Instandhaltung eine
   Freigabebescheinigung. Wer Arbeitskarten führt, erteilt zwangsläufig
   Freigaben. Ein Modul, das niemand abschalten kann, ist keins.
2. **Die Unveränderlichkeitsregel koppelt hart.** Sie spricht von *Vorgängen*,
   und die liegen in den Arbeitskarten. In einem anderen Modul müsste jenes in
   fremde Tabellen greifen — genau die Grenze, die die Trennung begründen
   sollte.
3. **Es wäre die vierte Fassung derselben Regel.** „Jemand Qualifiziertes
   steht für etwas ein, Credential eingefroren" steht bereits an drei Stellen.
   Als die Pilot-Owner-Grenze nachgezogen wurde, war sie an zwei von drei
   Stellen falsch — das passiert bei verteilten Regeln.

Die Freigabe ist deshalb die **dritte Stufe an der Karte bzw. am Vorgang**:
nach *fertig gemeldet* und *abgezeichnet*. Was ein eigenes Modul rechtfertigt,
sind die 145-Bausteine — Eingangsprüfung und Werkzeuge sind gebaut, die
unabhängige Zweitprüfung liegt als „unabhängige Kontrolle" in den
Arbeitskarten.

---

## Die gesetzten Entscheidungen E1–E8

Festgehalten in `docs/ANALYSE.md`; wer abweichen will, legt es zur
Entscheidung vor, statt es still anders zu bauen. Neue Erkenntnisse wandern
als weitere Entscheidung ins Dokument.

| | Entscheidung | Abschnitt |
|---|---|---|
| **E1** | Bestand als **append-only Bewegungsjournal**, kein überschreibbares Mengenfeld | 4.4 |
| **E2** | Break-glass-Admin **nur über die Konsole**, mit Protokoll und Benachrichtigung | 5.2 |
| **E3** | Audit-Trail wird **nicht gelöscht**; 3 Jahre Retention, DSGVO über Pseudonymisierung | 5.3 |
| **E3a** | Name und Lizenznummer **in Freigaben bleiben unverändert** — Aufbewahrungspflicht sticht DSGVO | 5.3 |
| **E4** | Zuordnung externer Identitäten auf interne Rollen gehört **in den Kern**, nicht in ein Provider-Modul | 5.4 |
| **E5** | Klassifizierung am **Bauteiltyp**; Zustände serviceable → unserviceable → unsalvageable → disposed; Sperrlager als Lagerorttyp | 4.7 |
| **E6** | **Lagerhaltung, keine Warenwirtschaft** — Bestellungen nur als Erinnerung, keine Lieferscheine, Rechnungen, Preishistorie | 4.8 |
| **E7** | **Bescheinigungsinhalt** wird als unveränderlicher Snapshot gespeichert, nie als Fremdschlüssel | 5.5 |
| **E8** | **Qualifikationen sind keine Rollen** — Autorisierung ist zweistufig; die PO-Berechtigung gilt je Luftfahrzeug | 5.6 |

Zentrale fachliche Erkenntnis: **Das Los ist die rückverfolgbare Einheit.**
Ein Form 1 deckt eine Menge ab (Seriennummer *oder* Charge) und kann in
mehreren Luftfahrzeugen enden. Seriennummerngeführte Teile sind der Sonderfall
„Los mit Menge 1".

---

## Distribution — drei Kanäle, ein Artefakt

Ein signiertes Tag ist die einzige Quelle; alle Kanäle konsumieren dasselbe
Release:

1. **Webserver-Pack:** Tarball mit fertigem `vendor/` und gebauten Assets —
   auf dem Zielsystem weder Composer noch Node. Zielgruppe ist ein eigener
   Server mit Root-Zugriff, **kein Shared Webspace**. Vorlagen in `deploy/`.
2. **Docker:** Image aus demselben Tarball (Kaniko, Basis-Image auf die
   PHP-Patch-Version gepinnt), Compose-Verbund in `deploy/docker/`.
3. **Proxmox LXC:** `deploy/lxc/` nach den Konventionen der Community-Scripts
   (Debian 13, Stack aus Debian-Quellen, Übergabe an den Setup-Assistenten).

Updates laufen über `deploy/update.sh` bzw. `deploy/docker/update.sh`:
Beschaffen und Signatur prüfen → Sicherung → Wartungsmodus → Code →
Migrationen → Caches → Worker → Wartungsmodus aus. `.env` und `storage/`
bleiben unberührt. Details in `HANDBUCH.md` Kap. 15.

**Entwicklung auf der eigenen GitLab-CE-Instanz, Veröffentlichung auf
GitHub** (`maruether/aeronance`, Image unter `ghcr.io/maruether/aeronance`).
Die CI baut auf jedem Tag Tarball und Image; **veröffentlicht wird getrennt
über `deploy/publish.sh`**, weil die Update-Prüfung der Installationen die
öffentlichen Tags liest — automatische Spiegelung machte jeden internen Tag
sofort zum Update für alle. Die öffentliche Historie ist eine
Release-Historie, ein Commit je Fassung.

Konsequenzen für den Code:

- **Keine kanalspezifischen Codepfade.** Alles über `.env`/Env-Variablen.
- **Node bleibt reine Build-Zeit-Abhängigkeit** — Assets kommen aus der CI.
- **Dienste wie Redis dürfen Pflicht werden — aber erst mit echtem Grund.**
  Defaults bleiben `database` für Queue/Cache/Session. Wird ein Dienst
  nötig, wird er in allen drei Kanälen Voraussetzung (Doku, Compose, LXC).
- **Kein Code-Nachladen zur Laufzeit** — in keiner Ausbaustufe.
  Herstellerquellen für LTA/TM sind deshalb YAML-Daten, kein Code.

---

## Architektur-Leitplanken (gelten dauerhaft)

- **Datenbank: ausschließlich MariaDB — Hard Limit.** Kein PostgreSQL, kein
  SQLite, auch nicht für Tests oder lokale Entwicklung. Laravel-`mariadb`-
  Treiber, `utf8mb4`, Mindestversion 10.11 (CI-Service). MySQL ist
  **explizit unsupported**. Schwere JSON-Pfad-Queries vermeiden, lieber
  sauber relationale Spalten.
- **Modulgrenzen respektieren:** Jedes Modul bringt eigene Migrations,
  Models, Filament-Resources und Policies mit. Kommunikation zwischen
  Modulen nur über definierte Schnittstellen/Events — nie direkt auf fremde
  Tabellen. Abhängigkeiten explizit im Manifest.
- **Identity-Provider als Module:** Der Kern kann immer lokales Login inkl.
  Break-glass. Provider-Module übernehmen Authentifizierung und/oder
  Mitglieder-Sync; externe Gruppen werden über die Zuordnung im Kern auf
  interne Rollen gemappt. `spatie/laravel-permission` ist die einzige
  Rechte-Wahrheit — kein Fachmodul prüft jemals direkt gegen VF oder LDAP.
- **Nichts Organisationsspezifisches hardcoden:** Name, Logo,
  Kennzeichen-Formate, Auth-Provider sind Instanz-Konfiguration. Die Akaflieg
  ist Referenzinstallation, kein Sonderfall im Code.
- **Eine Instanz = eine Organisation:** Kein Multi-Tenant-Datenmodell, keine
  `tenant_id`, auch nicht „vorsorglich". Managed Hosting liefe als verwaltete
  Einzelinstanzen.
- **Audit-Trail von Tag eins:** alle fachlich relevanten Änderungen über
  activitylog, append-only. Ein späteres 145-Audit muss nachvollziehen
  können, wer wann was geändert hat.
- **Unveränderlichkeit nach Freigabe:** Vorgänge mit erteilter CRS sind
  eingefroren. Korrekturen nur als neue, referenzierende Einträge — nie durch
  Editieren des Originals.
- **Traceability:** Form 1 / Herkunftsnachweis hängt am Los. Die Kette
  Teil → Vorgang → Flugzeug muss immer abfragbar sein.
- **Part-66-Felder an jeder Arbeitskarte:** Datum, Kennzeichen, Muster,
  ATA-Kapitel, Tätigkeitsart, Dauer, ausgeführt/unterstützt, freigebende
  Person. Das Erfahrungslogbuch ist eine Auswertung, keine Extra-Pflege.
- **Dokumente** (Form 1, CRS, Wägeberichte, Fotos) über medialibrary am
  jeweiligen Datensatz; Originaldateien unverändert aufbewahren.
- **Nichts hart löschen:** Soft Deletes überall; Aufbewahrung mindestens
  3 Jahre, Retention je Datenklasse einzeln schaltbar.
- **Sprache:** Code, DB und Commits englisch; UI deutsch, übersetzbar über
  Laravel-Sprachdateien. Kommentare dürfen deutsch sein — Hauptsache, sie
  erklären den Grund. `docs/GLOSSAR.md` mitpflegen, sobald ein neuer
  Fachbegriff auftaucht: deutsche UI-Bezeichnung und englischer Bezeichner
  werden zusammen entschieden.

---

## Security-Leitplanken (Defense in Depth)

Das Projekt ist Open Source — Angreifer lesen den Code mit. Sicherheit beruht
nie auf Verschleierung, sondern auf korrekten Mechanismen. Ziel ist, ganze
Fehlerklassen strukturell auszuschließen:

- **Framework-Mechanismen nutzen, nie umgehen:** Eloquent/Query-Builder statt
  roher SQL-Strings, Blade-Escaping, CSRF aktiv, Validierung über Form
  Requests.
- **AuthZ deny-by-default:** Jede Filament-Resource, Route und Action hat
  eine Policy. Objektzugriff immer über Scope/Ownership prüfen (IDOR).
  Rechte, die nur im UI versteckt sind, gelten als nicht vorhanden — eine
  Sperre im Formular braucht immer die 403 am Endpunkt dahinter.
- **Datei-Uploads härten:** Typ-Whitelist, private Disk außerhalb des
  Webroots, Auslieferung nur über auth-geprüfte Controller, generierte
  Dateinamen, Größenlimits, kein ungefiltertes SVG, optional ClamAV.
- **Secrets & Tokens:** nur in `.env` bzw. `encrypted`-Casts — insbesondere
  VF-Zugangsdaten und TOTP-Geheimnisse. Secrets nie loggen.
- **Auth härten:** Rate Limiting auf Login, Hashing über Framework-Standard,
  2FA als Option, fehlgeschlagene Logins ins Audit-Log.
- **HTTP-Härtung:** `SecurityHeaders`-Middleware (CSP ohne `unsafe-inline`
  in `script-src`, HSTS, X-Frame-Options, nosniff), sichere Cookie-Flags.
- **Supply Chain:** `composer.lock` committet, `composer audit` bricht die
  Pipeline ab, Renovate mit OSV-Alerts und eigenem Signierschlüssel.
  **Zwei Quellen sind kein Luxus** — OSV und Packagist kannten
  CVE-2026-45070 unterschiedlich. PHP selbst wird über das gepinnte
  Basis-Image beobachtet; auf eigenem Server und LXC kommt PHP vom System.
- **Resilienz:** `aeronance:backup`/`restore` mit Offsite-Kopie; Migrationen
  so schreiben, dass ein Rollback möglich bleibt.
- **Angreifer-Review als Arbeitsschritt:** Bei jedem neuen Endpoint/Feature
  kurz durchspielen: Wie umgehe ich die Policy? Was passiert mit
  manipulierten IDs, Uploads, Parametern? Erkenntnisse direkt als Tests
  festhalten. Die Grundabdeckung liefert `PanelDenyByDefaultTest`: Jede
  Resource und jede Seite des Panels braucht dort eine Regel, wer sie
  sehen und wer sie verwalten darf — ein neuer Bildschirm ohne Regel lässt
  die Suite scheitern.

---

## Arbeitsweise

- Ein Thema nach dem anderen; ein Modul fertigstellen, bevor das nächste
  beginnt. Minimal-invasive, nachvollziehbare Änderungen. Vor größeren
  Umbauten kurzen Plan vorlegen und auf Freigabe warten.
- **Begründung an Ort und Stelle.** Jede unübliche Entscheidung trägt ihren
  Grund als Kommentar im Code; was beim Bauen gefunden wurde, wandert in das
  Dokument des Moduls unter `docs/`. Commit-Nachrichten erklären das WARUM.
- **Conventional Commits** (`feat:`, `fix:`, `docs:`, `refactor:`, `chore:`,
  `test:`, `ci:`), Scope ist der Modulschlüssel (`feat(warehouse): …`).
- **Alles wird signiert.** Jeder Commit und jedes Tag trägt eine
  GPG-Signatur; die CI prüft gegen `.gitlab/signing-keys.asc`. Claude
  bereitet Commits vor, Marvin signiert.
- **CHANGELOG mitführen.** Jede Änderung, die eine Installation betrifft,
  landet sofort unter `[Unveröffentlicht]` — mit dem Warum, und unter „Beim
  Update beachten", falls der Betreiber etwas tun muss.
- **Tests gehören dazu**, gegen echtes MariaDB. Vor jedem Abschluss:
  `./vendor/bin/pint --test` **über den ganzen Baum** (nicht nur über die
  angefassten Dateien — 0.3.0 scheiterte an einer Import-Zeile in
  `routes/web.php`, die niemand mitgeprüft hatte), `./vendor/bin/phpunit`,
  bei UI-Änderungen zusätzlich `--group rendering`. Bringt eine Änderung eine Migration mit:
  `php artisan schema:dump` auf leerer DB und `aeronance:schema-verify`, sonst
  wird die Pipeline langsam oder der Job `schema` rot.
- **Release schneiden:** `[Unveröffentlicht]` umbenennen, „Beim Update
  beachten" ausfüllen, Commit `chore(release): vX.Y.Z`, signiertes
  annotiertes Tag, dann `deploy/publish.sh` — nach Abschluss des pack-Jobs
  ein zweites Mal für das Artefakt. Ablauf in `deploy/README.md`.
- Keine Zugriffe auf produktive Systeme oder Datenbanken ohne explizite
  Anweisung. Das ist auch die Feldtest-Installation der Akaflieg.
- Bei Unklarheiten zur Fachlichkeit (Luftfahrt-Regularien, Vereinsabläufe):
  fragen, nicht raten.
- **Lizenz: AGPL-3.0-or-later, kein CLA**, inbound = outbound. Begründung in
  `CONTRIBUTING.md`. Der Spendenlink im Repo fehlt noch.
