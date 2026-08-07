# CLAUDE.md — Aeronance

## Projektkontext

Webbasiertes, selbstgehostetes Werkstattverwaltungssystem (MRO), gedacht als
**Open-Source-Projekt für Luftsportvereine**. Referenzinstallation ist die
Akaflieg Freiburg e.V.; die Architektur muss aber vom kleinen Verein (nur Lager)
bis zum kleinen Part-145-Betrieb skalieren. Ein voller 145-Stack ist für die
meisten Vereine zu groß — deshalb ist Modularität kein Nice-to-have, sondern
Kernanforderung.

**Woher das kommt, und warum es die Prioritäten erklärt.** Die ursprüngliche
Anforderung war: *„Ich habe ein halbfertiges Lagertool und will einen besseren
Weg, mein Part-66-Log zu führen."* Das Erfahrungslogbuch ist also nicht der
letzte Punkt einer Liste, sondern **das eigentliche Ziel** — Lager, Flotte und
Arbeitskarten sind das Gerüst, das sich als nötig herausgestellt hat, um es
sauber ableiten zu können. Wo eine Entscheidung zwischen „bequem jetzt" und
„später auswertbar" steht, gewinnt die Auswertbarkeit.

Dieses Repo ist die Neuimplementierung. Der Vorgänger — ein handgeschriebenes
PHP/JS-Lagerverwaltungstool — liegt weiterhin unter `../clubwarehouse/` und
dient nur noch als Nachschlagewerk; sein Domänenwissen ist vollständig in
[`docs/ANALYSE.md`](docs/ANALYSE.md) überführt. **Nichts aus dem Bestandscode
wird übernommen, ohne dass es dort begründet steht.**

**Strikt modularer Aufbau — Kern + einzeln aktivierbare Module:**

- **Kern (Grundsystem):** Benutzer/Auth (lokales Login), Rollen & Rechte,
  Audit-Trail, Dokumentenablage, Einstellungen, Modulverwaltung,
  Erst-Setup-Assistent (siehe „Setup, Module & Updates")
- **Identity-Provider (als Module):** Vereinsflieger (Login +
  Mitglieder-/Rechte-Sync über die VF-API), LDAP/Samba AD, später ggf. OIDC
- **Lager** — Ersatzteile, Lagerorte, Bestände, Ein-/Ausbuchung
- **Flotte** — Flugzeuge, Halter, Komponenten (S/N, TSN/TSO), Form-1-Ablage
- **Arbeitskarten** — Vorgänge, Karten, Befunde, Arbeitszeiten **und die
  Freigabe (CRS)**; Teileentnahme nur, wenn das Lagermodul aktiv ist
- **Personal & Part-66** — Erfahrungslogbuch, Recency (66.A.20(b)),
  Lizenz-/ARS-Zähler, abgeleitet aus den Arbeitskarten
- später: 145-Bausteine (Eingangsprüfung, Werkzeug-/Kalibrierungsverwaltung,
  unabhängige Zweitprüfung, …)

Ausbaureihenfolge: Kern → Lager → Flotte → Arbeitskarten → Part-66.
Identity-Provider-Module kommen, sobald der Kern steht und der Bedarf da ist.

### Warum die Freigabe kein eigenes Modul ist

Ursprünglich war sie eines. Nach dem Bau der Nachbarmodule sprechen drei Dinge
dagegen, und zugestimmt wurde dem am 2026-07-30:

1. **Sie ist nicht abwählbar.** Nach ML.A.801 braucht jede Instandhaltung eine
   Freigabebescheinigung. Das Lager ist optional, die Flotte ist optional — wer
   aber Arbeitskarten führt, erteilt zwangsläufig Freigaben. Ein Modul, das
   niemand abschalten kann, ist keins.
2. **Die Unveränderlichkeitsregel koppelt hart.** Sie spricht von *Vorgängen*,
   und die liegen in den Arbeitskarten. In einem anderen Modul müsste jenes in
   fremde Tabellen greifen oder die Karten müssten ein Modul befragen, das sie
   nicht fordern — beides bricht genau die Grenze, die die Trennung begründen
   sollte.
3. **Es wäre die vierte Fassung derselben Regel.** „Jemand Qualifiziertes steht
   für etwas ein, Credential eingefroren" steht bereits an drei Stellen
   (Loszustand, externe Arbeit, Kartenabzeichnung). Als die Pilot-Owner-Grenze
   nachgezogen wurde, war sie an zwei von drei Stellen falsch — genau das
   passiert bei verteilten Regeln.

Die Freigabe ist deshalb die **dritte Stufe an der Karte bzw. am Vorgang**: nach
*fertig gemeldet* und *abgezeichnet*. Was ein eigenes Modul rechtfertigt, sind
die **145-Bausteine** — unabhängige Zweitprüfung, Eingangsprüfung,
Werkzeugkalibrierung. Die sind echte Zusatztiefe und tatsächlich abschaltbar.

Technik: Laravel + Filament, **MariaDB** (Hard Limit, siehe Leitplanken).
Auslieferung über drei Kanäle (siehe „Distribution"). Referenzinstallation:
LXC auf Proxmox, CI über eine eigene GitLab-CE-Instanz; als Identity-Provider
dort perspektivisch LDAP/Samba AD und/oder Vereinsflieger.

**Wichtig:** Der Betreiber ist Laravel-Neuling (PHP-Erfahrung vorhanden). Framework-Konzepte
(Migrations, Eloquent, Service Container, Filament Resources, Queues) beim ersten
Auftreten in 1–2 Sätzen erklären, nicht als bekannt voraussetzen.

---

## Phase 1 — Analyse des Bestandscodes (ABGESCHLOSSEN, 2026-07-28)

Ergebnis: **[`docs/ANALYSE.md`](docs/ANALYSE.md)** — Ist-Schema mit ERD,
Fachlogik, regulatorischer Rahmen nach VO (EU) 1321/2014, Glossar und acht
festgehaltene Entscheidungen. **Das Dokument ist ab hier verbindliche
Grundlage**, kein Archivmaterial: Wer am Datenmodell arbeitet, liest die
Abschnitte 4.4 bis 4.8 und 5.1 bis 5.6.

Die Entscheidungen in Kurzform — die Begründungen stehen jeweils im Dokument:

| | Entscheidung | Abschnitt |
|---|---|---|
| **E1** | Bestand als **append-only Bewegungsjournal**, kein überschreibbares Mengenfeld | 4.4 |
| **E2** | Break-glass-Admin **nur über die Konsole**, mit Protokoll und Benachrichtigung | 5.2 |
| **E3** | Audit-Trail wird **nicht gelöscht**; 3 Jahre Retention, DSGVO über Pseudonymisierung | 5.3 |
| **E3a** | Name und Lizenznummer **in Freigaben bleiben unverändert** — Aufbewahrungspflicht sticht DSGVO | 5.3 |
| **E4** | Zuordnung externer Identitäten auf interne Rollen gehört **in den Kern**, nicht in ein Provider-Modul | 5.4 |
| **E5** | Klassifizierung am **Bauteiltyp**; Zustände serviceable → unserviceable → unsalvageable → disposed; Sperrlager als Lagerorttyp | 4.7 |
| **E6** | **Lagerhaltung, keine Warenwirtschaft** — keine Lieferscheine, Bestellungen, Rechnungen, Preishistorie | 4.8 |
| **E7** | **Bescheinigungsinhalt** wird als unveränderlicher Snapshot gespeichert, nie als Fremdschlüssel | 5.5 |
| **E8** | **Qualifikationen sind keine Rollen** — Autorisierung ist zweistufig; die PO-Berechtigung gilt je Luftfahrzeug | 5.6 |

Zentrale fachliche Erkenntnis: **Das Los ist die rückverfolgbare Einheit.** Ein
Form 1 deckt eine Menge ab (Seriennummer *oder* Charge) und kann in mehreren
Luftfahrzeugen enden. Seriennummerngeführte Teile sind der Sonderfall „Los mit
Menge 1".

---

## Phase 2 — Laravel-Grundgerüst (AKTUELL)

- **Modul-Infrastruktur zuerst festlegen** — Vorschlag liegt vor:
  **[`docs/INFRASTRUKTUR.md`](docs/INFRASTRUKTUR.md)**. Empfohlen ist die
  Domain-Ordnerstruktur mit einem Filament-Plugin je Modul; darin hängen sechs
  Detailentscheidungen (D1–D6) und zwei offene Fragen (I1, I2).
  **Wartet auf die Entscheidung** — bis dahin kein Laravel-Scaffold.
- Neues Laravel-Projekt (aktuelles Stable-Release) in diesem Repo. Der
  Bestandscode bleibt unter `../clubwarehouse/` und wird **nicht** übernommen.
- Pakete (Kern): `filament/filament`, `spatie/laravel-permission`,
  `spatie/laravel-activitylog`, `spatie/laravel-medialibrary`.
  Provider-Pakete (z. B. `directorytree/ldaprecord-laravel`) kommen erst mit
  dem jeweiligen Identity-Provider-Modul.
- **MariaDB** (Laravel-`mariadb`-Treiber, `utf8mb4`); Konfiguration über
  `.env`, **keine Secrets ins Repo**
- Erste vertikale Scheibe: **minimaler Kern + Lagermodul vollständig** (Teile,
  Lagerorte, Bestand, Ein-/Ausbuchung, Mindestbestände) — damit ist das Tool
  sofort für die Akaflieg nutzbar und beweist gleichzeitig den Modulschnitt.
- **Lizenz: AGPL-3.0, kein CLA, Spendenlink im Repo.** Der offizielle
  Lizenztext liegt als `LICENSE` im Repo; der Spendenlink fehlt noch, und die
  Lizenzwahl ist vor der Veröffentlichung final zu bestätigen.
  Begründung: Copyleft der GPL
  greift nur bei *Verbreitung* — der Einbau in proprietäre kommerzielle
  Produkte ist damit ausgeschlossen (das Gesamtwerk würde GPL-pflichtig
  inkl. Quellcode-Herausgabe), aber der Betrieb als Webdienst wäre keine
  Verbreitung (SaaS-Lücke). AGPL §13 schließt genau das: Wer eine veränderte
  Version als Netzwerkdienst betreibt, muss deren Quellcode anbieten. Ohne
  CLA gilt inbound = outbound: Jeder Beitragende behält sein Copyright,
  derselbe Copyleft-Schutz gilt automatisch für alle Beiträge.
  Copyright-Hinweise (Namensnennung) müssen unter AGPL erhalten bleiben.
  Bewusste Grenze: interne, unveränderte Nutzung durch Firmen bleibt frei —
  das verhindert keine Open-Source-Lizenz.

---

## Setup, Module & Updates

- **Erst-Setup-Assistent** (Stil: Vaultwarden/Bookstack-Erstinstallation):
  Wird nur im uninstallierten Zustand ausgeliefert und führt durch
  DB-Verbindung (mit Verbindungstest), Migrationen, Admin-Konto,
  Basiskonfiguration (Vereinsname, Logo) und Modulauswahl.
  **Nach Abschluss verriegelt sich der Assistent dauerhaft** (Install-Marker);
  im installierten Zustand sind die Setup-Routen nicht erreichbar — offen
  liegende Install-Routen sind ein klassisches Einfallstor.
  Der Assistent erkennt per Env vorkonfigurierte Werte (z. B. DB-Zugang im
  Docker-Setup) und überspringt die betreffenden Schritte.
- **Modulauswahl mit Logikprüfung:** Jedes Modul deklariert im Manifest
  `requires` und `conflicts`. Aktivierung und Deaktivierung prüfen den
  Abhängigkeitsgraphen (z. B. Arbeitskarten ⇒ Flotte; Part-66 ⇒
  Arbeitskarten). Der Assistent und die
  Modulverwaltung erklären dem Nutzer, *warum* etwas mit rein muss oder
  nicht kombinierbar ist.
- **Deaktivieren ≠ Deinstallieren:** Deaktivieren blendet Funktionen aus und
  stoppt Jobs des Moduls, **löscht aber keine Daten**. Echtes Deinstallieren
  (Tabellen entfernen) ist ein separater, explizit bestätigter Schritt und
  kommt frühestens später.
- **Auslieferung als Komplettpaket:** Alle Module sind im Release enthalten;
  nur die Aktivierung entscheidet, was läuft. Kein Nachladen von Code.
- **Updates git-basiert:** Releases als SemVer-Tags. Update-Ablauf als ein
  Befehl (Artisan-Command oder Skript): Backup (DB + Storage) → Maintenance
  Mode → `git fetch && git checkout <tag>` → `composer install --no-dev` →
  `php artisan migrate --force` → Caches neu aufbauen → Maintenance Mode aus.
  `.env` und `storage/` bleiben unberührt. Pro Release CHANGELOG mit
  UPGRADE-Hinweisen.

---

## Distribution (drei Kanäle, eine Codebasis)

Ein getaggtes Release ist die einzige Quelle; alle Kanäle konsumieren dasselbe
Artefakt:

1. **Webserver-Pack (vhost auf eigenem Server, Apache/nginx):** Release-Tarball
   mit fertigem `vendor/` und in der CI gebauten Frontend-Assets — auf dem
   Zielsystem sind weder Composer noch Node nötig, nur PHP-FPM (Mindestversion
   + Extensions einmal zentral definieren) und die vorausgesetzten Dienste.
   Zielgruppe ist ein voll ausgestatteter Server mit Root-Zugriff, **kein
   Shared Webspace**. Beiliegend: `deploy/`-Beispiele (nginx-vhost- und
   Apache-Konfiguration mit Document-Root → `public/`, systemd-Units für
   Queue-Worker und Scheduler).
2. **Docker:** offizielles Image + `docker-compose.yml` (App/PHP-FPM, nginx,
   MariaDB, Worker/Scheduler); Konfiguration vollständig über
   Env-Variablen, persistente Volumes für DB und `storage/`.
3. **Proxmox LXC:** Installations- und Update-Skript nach den Konventionen
   der Proxmox VE Community-Scripts (Einreichung per Pull Request in deren
   GitHub-Repo). Das Skript erstellt den Container, installiert den Stack
   (nginx, PHP-FPM, MariaDB), zieht das Release und übergibt an den
   Setup-Assistenten.

Konsequenzen für den Code (gelten ab Phase 2):

- **Keine kanalspezifischen Codepfade.** Alles Verhalten wird über
  `.env`/Env-Variablen gesteuert; die drei Kanäle unterscheiden sich nur im
  Drumherum.
- **Zielbild „eigener Server oder Container", kein Shared Webspace:**
  Langlaufende Prozesse (Queue-Worker über systemd/Supervisor) dürfen
  vorausgesetzt werden. Node bleibt trotzdem reine Build-Zeit-Abhängigkeit —
  Assets kommen fertig aus der CI.
- **Dienste wie Redis dürfen Pflicht werden — aber erst mit echtem Grund:**
  Start-Defaults bleiben `database` für Queue/Cache/Session (der Wechsel ist
  eine `.env`-Zeile). Sobald ein Feature Redis o. Ä. wirklich braucht, wird
  es regulär zur Voraussetzung in allen drei Kanälen (Doku, Compose,
  LXC-Skript) — vorher wäre es nur ein zusätzlicher Dienst, den jede
  Installation pflegen muss, ohne Nutzen bei Vereinsgröße.
- **CI (GitLab) baut pro Tag:** Release-Tarball und Docker-Image aus derselben
  Pipeline. **Veröffentlicht wird davon getrennt**, über `deploy/publish.sh` —
  die Aktualisierungsprüfung liest die Tags des öffentlichen Repositorys, und
  automatische Spiegelung machte aus jedem internen Tag sofort ein Update für
  alle Installationen. GitLab ist die Entwicklung, GitHub der
  Veröffentlichungskanal für Community-Sichtbarkeit, Issues und die
  Community-Scripts-Einreichung.
- **Reihenfolge:** erst Release-Pipeline (Fundament für alles), dann Docker,
  zuletzt Community-Scripts — die Einreichung dort setzt ein öffentliches,
  gepflegtes Projekt mit stabilen Releases voraus.

---

## Architektur-Leitplanken (gelten ab Phase 2 dauerhaft)

- **Datenbank: ausschließlich MariaDB — Hard Limit.** Kein PostgreSQL, kein
  SQLite, auch nicht für Tests oder lokale Entwicklung. Laravel-`mariadb`-Treiber
  verwenden, `utf8mb4`. Tests und CI laufen gegen einen MariaDB-Service
  (GitLab-CI-Service-Container), **nicht** gegen das Laravel-übliche SQLite
  in-memory. Mindestversion einmal festlegen (Vorschlag: 10.11 LTS =
  Debian-12-Standard). Schwere JSON-Pfad-Queries vermeiden, lieber sauber
  relationale Spalten. MySQL ist **explizit unsupported** — 8.0 ist seit
  April 2026 EOL, und 8.4+ driftet zunehmend von MariaDB weg; wer Webspace
  ohne MariaDB hat, nutzt den Docker- oder LXC-Kanal.
- **Modulgrenzen respektieren:** Jedes Modul bringt eigene Migrations, Models,
  Filament-Resources und Policies mit. Kommunikation zwischen Modulen nur über
  definierte Schnittstellen/Events — nie direkt auf fremde Tabellen zugreifen.
  Abhängigkeiten explizit im Manifest deklarieren.
  Der Kern muss ohne jedes Modul lauffähig sein, jedes Modul einzeln
  deaktivierbar, ohne dass der Rest bricht.
- **Identity-Provider als Module:** Der Kern kann immer lokales Login inkl.
  Break-glass-Admin. Provider-Module (Vereinsflieger, LDAP/AD, …) übernehmen
  Authentifizierung und/oder Mitglieder-Sync; externe Funktionen/Gruppen werden
  über eine **konfigurierbare Zuordnung** auf die internen Rollen gemappt.
  Intern ist `spatie/laravel-permission` die einzige Rechte-Wahrheit — kein
  Fachmodul prüft jemals direkt gegen VF oder LDAP.
- **Nichts Vereinsspezifisches hardcoden:** Vereinsname, Logo, Kennzeichen-Formate,
  Auth-Provider usw. sind Instanz-Konfiguration. Die Akaflieg ist
  Referenzinstallation, kein Sonderfall im Code.
- **Eine Instanz = ein Verein:** Kein Multi-Tenant-Datenmodell — keine
  `tenant_id`-Spalten, kein Mandanten-Scoping, auch nicht „vorsorglich".
  Ein späteres Managed-Hosting-Angebot liefe als verwaltete Einzelinstanzen
  (ein Container pro Verein); die Datenisolation kommt aus der Infrastruktur,
  nicht aus dem Schema.
- **Audit-Trail von Tag eins:** alle fachlich relevanten Änderungen über
  activitylog, append-only. Ein späteres 145-Audit muss nachvollziehen können,
  wer wann was geändert hat.
- **Unveränderlichkeit nach Freigabe:** Vorgänge mit erteilter CRS sind
  eingefroren. Korrekturen nur als neue, referenzierende Einträge — nie durch
  Editieren des Originals. (Diese Regel spricht von *Vorgängen* und war damit
  immer eine Aussage über Arbeitskarten-Daten — der Grund, warum die Freigabe
  dort hingehört.)
- **Traceability:** Form 1 / Herkunftsnachweis hängt an der Komponente. Die Kette
  Teil → Vorgang → Flugzeug muss immer abfragbar sein.
- **Part-66-Felder ab der ersten Arbeitskarte:** Datum, Kennzeichen, Muster,
  ATA-Kapitel, Tätigkeitsart, Dauer, ausgeführt/unterstützt, freigebende Person.
  Das Erfahrungslogbuch ist eine Auswertung, keine Extra-Pflege.
- **Dokumente** (Form 1, CRS, Wägeberichte, Fotos) über medialibrary am
  jeweiligen Datensatz; Originaldateien unverändert aufbewahren.
- **Nichts hart löschen:** Soft Deletes überall; Aufbewahrung von Records
  mindestens 3 Jahre, Löschkonzept kommt später.
- **Sprache:** Code, DB und Commits englisch; UI deutsch, übersetzbar über
  Laravel-Sprachdateien (Open Source!). Glossar in `docs/GLOSSAR.md` pflegen
  (z. B. Vorgang = work_order, Arbeitskarte = task_card,
  Freigabe = release_to_service, Lagerort = storage_location, Befund = finding).
- **Rollen von Anfang an mitdenken:** `admin`, `werkstattleiter`,
  `certifying_staff`, `mechaniker`, `mitglied` (read-only) — auch wenn anfangs
  nur zwei davon genutzt werden.

---

## Security-Leitplanken (Defense in Depth, ab Phase 2)

Das Projekt wird Open Source — Angreifer lesen den Code mit. Sicherheit darf
deshalb nie auf Verschleierung beruhen, sondern nur auf korrekten Mechanismen.
Ziel ist, ganze Fehlerklassen strukturell auszuschließen:

- **Framework-Mechanismen nutzen, nie umgehen:** Eloquent/Query-Builder statt
  roher SQL-Strings, Blade-Escaping, CSRF aktiv, Validierung über Form Requests.
- **AuthZ deny-by-default:** Jede Filament-Resource, Route und Action hat eine
  Policy. Objektzugriff immer über Scope/Ownership prüfen (IDOR). Rechte, die
  nur im UI versteckt sind, gelten als nicht vorhanden.
- **Datei-Uploads härten:** Typ-Whitelist (PDF, JPG/PNG), private Storage-Disk
  außerhalb des Webroots, Auslieferung nur über auth-geprüfte Controller bzw.
  signierte URLs, generierte Dateinamen, Größenlimits, kein ungefiltertes SVG.
- **Secrets & Tokens:** nur in `.env` bzw. verschlüsselten DB-Feldern
  (encrypted casts) — insbesondere VF-/LDAP-Zugangsdaten. Secrets nie loggen.
- **Auth härten:** Rate Limiting auf Login und API, Passwort-Hashing über
  Framework-Standard (bcrypt/argon2), 2FA als Option im Kern, fehlgeschlagene
  Logins ins Audit-Log.
- **HTTP-Härtung:** HTTPS only, Security-Header (CSP, HSTS, X-Frame-Options),
  sichere Session-/Cookie-Flags.
- **Supply Chain:** `composer.lock` committen, `composer audit` in der CI,
  Renovate/Dependabot, keine verwaisten Pakete einbauen.
- **Kein Code-Nachladen zur Laufzeit** — in keiner Ausbaustufe. Auslieferung
  und Updates laufen ausschließlich über Releases (siehe „Setup, Module &
  Updates").
- **Resilienz:** automatisierte Backups (DB + Dokumente) mit getestetem
  Restore; Migrationen so schreiben, dass ein Rollback möglich bleibt.
- **Angreifer-Review als Arbeitsschritt:** Bei jedem neuen Endpoint/Feature
  kurz durchspielen: Wie umgehe ich die Policy? Was passiert mit manipulierten
  IDs, Uploads, Parametern? Erkenntnisse direkt als Tests festhalten —
  mindestens AuthZ-Negativtests pro Resource.

---

## Arbeitsweise

- Ein Thema nach dem anderen; ein Modul fertigstellen, bevor das nächste beginnt.
- Minimal-invasive, nachvollziehbare Änderungen. Vor größeren Umbauten kurzen
  Plan vorlegen und auf Freigabe warten.
- Conventional Commits (`feat:`, `fix:`, `docs:`, `refactor:`, `chore:`).
- Keine Zugriffe auf produktive Systeme oder Datenbanken ohne explizite Anweisung.
- Bei Unklarheiten zur Fachlichkeit (Luftfahrt-Regularien, Vereinsabläufe):
  fragen, nicht raten.
- **Die Entscheidungen E1–E8 aus `docs/ANALYSE.md` sind gesetzt.** Wer davon
  abweichen will, legt es zur Entscheidung vor, statt es still anders zu bauen. Ergeben
  sich beim Bauen neue Erkenntnisse, wandern sie als weitere Entscheidung ins
  Dokument — es ist ein lebendes Dokument, kein Protokoll.
- **`docs/GLOSSAR.md` mitpflegen**, sobald ein neuer Fachbegriff auftaucht.
  Deutsche UI-Bezeichnung und englischer Bezeichner werden zusammen entschieden,
  nicht nachträglich zusammengesucht.
