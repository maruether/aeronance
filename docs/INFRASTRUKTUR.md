# Modul-Infrastruktur

**Stand:** 2026-07-28 · **Status: entschieden und gebaut**

> **Entscheidung (vom 2026-07-28): Option B**, unter der Bedingung, dass
> Module aufeinander aufbauen dürfen — siehe die Präzisierung von **D4** unten.
> **I1:** Mehrere Identity-Provider dürfen gleichzeitig aktiv sein, **mit
> Warnung wegen doppelter Daten**. **I2:** PHP 8.2 und MariaDB 10.11 LTS als
> Mindestversionen.
>
> Umgesetzt in `app/Core/Modules/`, 24 Tests grün (`php artisan test`).
> Installiert: Laravel 13.23, Filament 5.7, PHP 8.5.5, MariaDB 12.3.2.

CLAUDE.md legt für Phase 2 fest, dass die Modul-Infrastruktur **zuerst**
entschieden wird, mit zwei Kandidaten: `nwidart/laravel-modules` gegen eine
saubere Domain-Ordnerstruktur mit einem Filament-Plugin je Modul. Dieses
Dokument stellt beide gegenüber, empfiehlt eine Variante und benennt die sechs
Detailentscheidungen, die mit daran hängen.

Vorab zwei Begriffe, weil sie hier ständig vorkommen:

- **Service Provider** — die Laravel-Klasse, in der ein Stück Software beim
  Start des Frameworks anmeldet, was es mitbringt (Migrationen, Übersetzungen,
  Ereignis-Zuhörer, geplante Aufgaben). Jedes Modul bekommt genau einen.
- **Filament-Plugin** — Filament (die Verwaltungsoberfläche) hat eine
  eingebaute Schnittstelle, über die ein Stück Software seine Bildschirmmasken
  in ein Panel einhängt. Wird ein Plugin nicht eingehängt, existiert seine
  Oberfläche für die Anwendung schlicht nicht.

---

## 1. Woran sich die Entscheidung messen lassen muss

Aus CLAUDE.md, in der Reihenfolge ihrer Härte:

| # | Anforderung |
|---|---|
| **A1** | **Auslieferung als Komplettpaket.** Alle Module sind im Release enthalten; nur die Aktivierung entscheidet, was läuft. **Kein Nachladen von Code** — in keiner Ausbaustufe. |
| **A2** | **Modul-Manifest** mit Name, Version, `requires`, `conflicts`; Aktivierung und Deaktivierung prüfen den **Abhängigkeitsgraphen** und erklären dem Nutzer, warum etwas mit rein muss. |
| **A3** | **Deaktivieren ≠ Deinstallieren.** Deaktivieren blendet Funktionen aus und stoppt Jobs, **löscht keine Daten**. |
| **A4** | Jedes Modul bringt **eigene Migrations, Models, Filament-Resources und Policies** mit. |
| **A5** | Kommunikation zwischen Modulen nur über **definierte Schnittstellen/Events** — nie direkt auf fremde Tabellen. |
| **A6** | Der **Kern läuft ohne jedes Modul**, jedes Modul ist einzeln deaktivierbar, ohne dass der Rest bricht. |
| **A7** | Der **Erst-Setup-Assistent** führt durch die Modulauswahl und verriegelt sich danach. |
| **A8** | **AuthZ deny-by-default**; was nur im UI versteckt ist, gilt als nicht vorhanden. |

**A1 ist der Dreh- und Angelpunkt** und wird unten den Ausschlag geben.

---

## 2. Option A — `nwidart/laravel-modules`

Ein etabliertes Paket (seit ~2015, breit eingesetzt). Jedes Modul wird zu einer
Miniatur-Laravel-Anwendung in einem eigenen Verzeichnis:

```
Modules/Warehouse/
├── app/{Http,Models,Providers}/
├── config/
├── database/{migrations,seeders,factories}/
├── resources/{views,assets,lang}/
├── routes/{web.php,api.php}/
├── tests/
├── composer.json          ← jedes Modul ist ein eigenes Composer-Paket
└── module.json            ← Name, Version, Provider, aktiv ja/nein
```

**Was es liefert**

- Generator-Befehle (`module:make`, `module:make-model`, `module:migrate` …) —
  spart Tipparbeit
- Automatische Registrierung der Service Provider
- `module:enable` / `module:disable`, Zustand in `modules_statuses.json`
- Erprobt, viele Projekte nutzen es, mögliche Mitwirkende kennen es vielleicht

**Was es nicht liefert — und für dieses Projekt gebraucht wird**

- **Keine Abhängigkeitsauflösung.** `requires` und `conflicts` (A2) gibt es
  nicht; der Graph, die Zyklenprüfung und die Erklärtexte wären ohnehin
  Eigenbau.
- **Zustand in einer JSON-Datei auf der Platte**, nicht in der Datenbank. Das
  kollidiert mit dem Setup-Assistenten (A7), der die Auswahl irgendwo
  persistieren muss, und es bedeutet eine vom Webserver beschreibbare Datei im
  Anwendungsverzeichnis — genau die Art Angriffsfläche, die die
  Security-Leitplanken vermeiden wollen.
- **Keine Filament-Anbindung.** Filament findet Bildschirmmasken nur in Pfaden,
  die man ihm nennt. Bei nwidart muss man jedes Modul **trotzdem** von Hand ins
  Panel einhängen — also genau das tun, was ein Filament-Plugin ohnehin ist.

**Der eigentliche Einwand** liegt tiefer: Der Kern des Pakets ist die Idee,
dass ein Modul eine **eigenständig installierbare Einheit** ist — eigenes
`composer.json`, eigener Lebenszyklus, notfalls von einem anderen Anbieter.
**A1 schließt genau das aus.** Man bezahlt die Abstraktion, ohne den Teil zu
nutzen, der sie rechtfertigt: eine zweite Verzeichniskonvention neben Laravel,
eine zusätzliche Paketabhängigkeit, die bei jedem Laravel- und Filament-Update
mitziehen muss, und für Mitwirkende eine dritte Konvention über Laravel und
Filament hinaus.

---

## 3. Option B — Domain-Ordner mit einem Filament-Plugin je Modul

Kein zusätzliches Paket. Ein Modul ist ein Namespace unter `app/Modules/`, der
zwei Dinge mitbringt: einen Service Provider und ein Filament-Plugin.

```
app/
├── Core/                              ← immer aktiv, kennt kein einziges Modul
│   ├── Models/                        User, Role, Qualification, Setting
│   ├── Policies/
│   ├── Filament/                      Benutzer, Rollen, Einstellungen, Modulverwaltung
│   ├── Modules/                       ← die Modul-Infrastruktur selbst
│   │   ├── Contracts/AeronanceModule.php
│   │   ├── Manifest.php               name, version, requires, conflicts
│   │   ├── ModuleRegistry.php         kennt alle ausgelieferten Module
│   │   ├── ModuleManager.php          aktivieren/deaktivieren + Persistenz
│   │   └── DependencyResolver.php     Graph, Zyklen, requires/conflicts
│   ├── Setup/                         Erst-Setup-Assistent
│   └── Support/
└── Modules/
    ├── Warehouse/
    │   ├── WarehouseModule.php        Manifest + Filament-Plugin in einer Klasse
    │   ├── WarehouseServiceProvider.php
    │   ├── Models/                    PartType, StockLot, StockMovement
    │   ├── Filament/Resources/
    │   ├── Policies/
    │   ├── Events/                    z. B. StockLotFullyIssued
    │   ├── Contracts/                 was andere Module nutzen dürfen
    │   ├── Database/Migrations/
    │   ├── Lang/de/
    │   └── Tests/
    ├── Fleet/
    ├── TaskCards/
    ├── Releases/
    ├── Part66/
    ├── VereinsfliegerIdentity/
    └── LdapIdentity/
```

Ein Modul deklariert sich selbst:

```php
final class WarehouseModule implements AeronanceModule, Plugin
{
    public function getId(): string { return 'warehouse'; }

    public function manifest(): Manifest
    {
        return new Manifest(
            name:      'warehouse',
            version:   '1.0.0',
            requires:  [],
            conflicts: [],
        );
    }

    // Filament: Bildschirmmasken ins Panel einhängen —
    // wird bei deaktiviertem Modul gar nicht erst aufgerufen
    public function register(Panel $panel): void
    {
        $panel->discoverResources(
            in:  __DIR__ . '/Filament/Resources',
            for: 'App\\Modules\\Warehouse\\Filament\\Resources',
        );
    }

    public function boot(Panel $panel): void {}
}
```

**Was das kostet:** Die Modul-Infrastruktur ist Eigenbau — geschätzt 300–400
Zeilen im Kern (Registry, Manager, Resolver, Manifest, eine Migration, eine
Filament-Seite für die Modulverwaltung). Der Abhängigkeitsgraph aus A2 wäre bei
Option A ohnehin Eigenbau gewesen; der Rest ist überschaubar und
vollständig testbar.

**Was das bringt:** Nur Laravel und Filament, keine dritte Konvention. Volle
Kontrolle über Manifest-Format, Persistenz und Erklärtexte. Der Modulschnitt
liegt genau dort, wo Filament ihn ohnehin vorsieht.

---

## 4. Gegenüberstellung

| Anforderung | Option A (nwidart) | Option B (Filament-Plugins) |
|---|---|---|
| **A1** Komplettpaket, kein Nachladen | erfüllbar, aber gegen die Kernidee des Pakets | ✅ natürlich |
| **A2** Manifest + Abhängigkeitsgraph | ❌ Eigenbau | ❌ Eigenbau (gleicher Aufwand) |
| **A3** Deaktivieren ohne Datenverlust | ✅ | ✅ |
| **A4** Eigene Migrations/Models/Resources/Policies | ✅ | ✅ |
| **A5** Nur Events/Schnittstellen zwischen Modulen | neutral (Disziplin) | neutral (Disziplin) |
| **A6** Kern ohne Module lauffähig | ✅ | ✅ |
| **A7** Setup-Assistent schreibt die Auswahl | ⚠️ JSON-Datei statt DB | ✅ DB |
| **A8** deny-by-default | neutral | neutral |
| Filament-Anbindung | Handarbeit je Modul | ✅ der Modulschnitt *ist* die Plugin-Grenze |
| Zusätzliche Abhängigkeit | ja, mit Update-Kopplung | nein |
| Konventionen, die Mitwirkende lernen | Laravel + Filament + nwidart | Laravel + Filament |
| Eigener Code | wenig | ~300–400 Zeilen im Kern |

Eine dritte Variante — **Module als getrennte Composer-Pakete** — scheidet
durch A1 direkt aus und wird hier nur der Vollständigkeit halber erwähnt.

---

## 5. Empfehlung: Option B

Der Ausschlag kommt aus A1. Aeronance liefert **eine** Codebasis aus, in der
alles enthalten ist und nur die Aktivierung entscheidet, was läuft. Damit ist
ein Modul kein Installationsartefakt, sondern ein **Aktivierungszustand** — und
genau dafür hat Filament bereits eine Schnittstelle, die man sonst nachbaut.
Die einzige Fähigkeit, die nwidart hier wirklich abnähme (Module als eigene
Pakete verwalten), ist die, die das Projekt ausdrücklich nicht will; die
Fähigkeit, die es dringend braucht (Abhängigkeitsgraph), fehlt dort.

Bleibt der Eigenbau-Anteil. Der ist überschaubar, liegt vollständig im Kern,
ist ohne Rücksicht auf fremde Update-Zyklen wartbar und lässt sich sauber
testen — und die Alternative hätte den größten Teil davon ebenfalls verlangt.

---

## 6. Sechs Detailentscheidungen, die daran hängen

Alle sechs stehen zur Diskussion; empfohlen ist jeweils die erste Variante.

### D1 — Migrationen laufen immer, auch für inaktive Module

**Empfehlung: ja.** Alle Tabellen entstehen bei der Installation, unabhängig
von der Modulauswahl.

*Warum:* Es gibt genau einen Migrationsstand statt eines pro Modulkombination.
Ein Modul später zu aktivieren ist dann ein Schalter und kein
Wartungsfenster mit `migrate`-Lauf. Deaktivieren löscht ohnehin keine Daten
(A3), also stünden die Tabellen sowieso herum. Und es vermeidet die
unangenehmste Fehlerklasse: „Modul wurde in Version 1.2 aktiviert, die
Migrationen aus 1.0 und 1.1 fehlen".

*Preis:* Ein Verein, der nur das Lager nutzt, hat leere Tabellen für Flotte und
Arbeitskarten in der Datenbank. Bei Vereinsgröße belanglos.

### D2 — Modulzustand in der Datenbank, nicht in einer Datei

**Empfehlung: Tabelle `modules`** (`name`, `enabled_at`, `version`).

*Warum:* Der Setup-Assistent schreibt die Auswahl (A7), sie gehört zur
Instanzkonfiguration, wird mit dem normalen Backup gesichert, und es entsteht
keine vom Webserver beschreibbare Datei im Anwendungsverzeichnis.

*Fallstrick, der eingeplant werden muss:* Vor der ersten Migration existiert die
Tabelle nicht. Die Registry muss diesen Fall abfangen und dann „alles inaktiv"
melden, statt mit einem Datenbankfehler zu sterben — sonst ist der
Setup-Assistent nicht erreichbar.

### D3 — Deaktivierung wirkt auf drei Ebenen, nicht auf einer

**Empfehlung:** Sichtbarkeit, Zugriff und Hintergrundarbeit getrennt abschalten.

| Ebene | Mechanik | Ohne diese Ebene |
|---|---|---|
| Oberfläche | Filament-Plugin wird nicht eingehängt | — |
| Zugriff | Policies verweigern | Direktaufruf einer URL käme durch |
| Hintergrund | Jobs und geplante Aufgaben nicht registriert | Aufräum-Jobs eines „abgeschalteten" Moduls liefen weiter |

Das ist dieselbe Logik wie die Leitplanke *„Rechte, die nur im UI versteckt
sind, gelten als nicht vorhanden"* (A8), angewandt auf Module. Ein
Negativtest je Modul gehört in die Testsuite.

### D4 — Modulgrenzen: was erlaubt ist und was nicht

*Präzisiert am 2026-07-28 auf die Bedingung „solange Module aufeinander
aufbauen dürfen".*

**Sie dürfen — genau dafür ist `requires` da.** Die ursprüngliche Fassung dieses
Abschnitts las sich strenger, als sie gemeint war. Die Regel unterscheidet
zwischen der **Art** der Kopplung, nicht danach, ob es sie geben darf:

| | erlaubt? | warum |
|---|---|---|
| `requires` im Manifest deklarieren | **ja** | Arbeitskarten ⇒ Flotte ist genau dieser Fall |
| Contracts und Events des Zielmoduls nutzen | **ja** | die vorgesehene Schnittstelle |
| **Fremdschlüssel** entlang einer `requires`-Kante | **ja** | die Tabelle existiert garantiert: der Resolver lässt das Modul ohne seine Abhängigkeit nicht aktivieren, und D1 legt alle Tabellen ohnehin an |
| Fremdschlüssel **ohne** `requires`-Kante | **nein** | bricht, sobald das andere Modul fehlt |
| `JOIN` in Tabellen eines fremden Moduls | **nein** | auch bei harter Abhängigkeit — sonst ist jede Schemaänderung dort ein Bruch hier |

Kurz: **Struktur darf sich stützen, Abfragen nicht.** Wer Daten aus einem
anderen Modul braucht, geht über dessen Contract, nicht über dessen Tabellen.

Für die **optionale** Zusammenarbeit — Lager ↔ Arbeitskarten, ohne `requires` —
bleibt es beim Bezug über die Kennung **plus einer Kopie der benötigten
Angaben**. Für die Form-1-Übergabe aus der Analyse (4.7 f) heißt das: Die Flotte
merkt sich Los-Kennung, Form-1-Nummer und Ausstellerangaben, statt in die
Lagertabellen zu greifen. **Das ist exakt das Snapshot-Prinzip aus E7**, hier aus
einem ganz anderen Grund — dort, weil ein Nachweis nicht nachträglich verändert
werden darf; hier, weil ein Modul fehlen kann. Beide Male dieselbe Antwort, was
für den Entwurf spricht.

### D5 — Modulliste explizit in der Konfiguration, kein Verzeichnis-Scan

**Empfehlung:** Die ausgelieferten Module stehen als Klassenliste in
`config/aeronance.php`.

*Warum:* Was ausgeliefert wird, ist eine feste, überschaubare Menge. Eine
explizite Liste ist auditierbar, im Diff sichtbar und der direkteste Ausdruck
von *„kein Nachladen von Code zur Laufzeit"* (A1). Ein Verzeichnis-Scan wäre
bequemer beim Entwickeln, würde aber genau die Eigenschaft aufweichen, die die
Security-Leitplanke fordert.

### D6 — Optionale Zusammenarbeit über Events, nicht über das Manifest

**Empfehlung:** `requires` bleibt harten Abhängigkeiten vorbehalten.

Bekannte harte Abhängigkeiten aus CLAUDE.md: Arbeitskarten ⇒ Flotte;
Freigaben ⇒ Arbeitskarten; Part-66 ⇒ Arbeitskarten.

Der Satz *„Teileentnahme nur, wenn das Lagermodul aktiv ist"* ist dagegen
**keine** harte Abhängigkeit: Arbeitskarten funktionieren ohne Lager, sie können
dann nur nichts entnehmen. Solche Fälle löst ein Ereignis, auf das niemand
hört, wenn das Gegenstück fehlt — ohne einen dritten Manifest-Typ
(`optional`/`enhances`) einzuführen, der die Modulauswahl im Assistenten nur
schwerer erklärbar macht.

---

### D7 — Mehrfach angebotene Fähigkeiten: erlaubt, aber mit Warnung

*Aus die Antwort auf I1: mehrere Identity-Provider dürfen gleichzeitig
aktiv sein, „aber mit Warnung wegen doppelter Daten".*

Das ist eine **dritte Beziehungsart** neben `requires` und `conflicts`, und sie
lohnt sich, weil sie über die Identity-Provider hinaus trägt. Ein Modul
deklariert im Manifest, welche **Fähigkeit** es anbietet:

```php
provides: ['identity-provider']
```

Bieten zwei aktive Module dieselbe Fähigkeit an, ist das **kein Fehler** —
sonst wäre die Umstellung von Vereinsflieger auf Active Directory ein harter
Schnitt statt eines Übergangs. Die Modulverwaltung sagt aber, was das bedeutet:
Mitgliederdaten kommen dann aus zwei Quellen, und dieselbe Person kann doppelt
angelegt werden.

Daraus folgt eine Aufgabe für den Kern, sobald der zweite Provider gebaut wird:
**Identitätsverknüpfung** — ein Benutzerkonto, mehrere externe Identitäten, plus
eine Zusammenführung von Hand für die Fälle, die die automatische Zuordnung
(vermutlich über die E-Mail-Adresse) nicht trifft.

### D8 — Mindestversionen

*Aus die Antwort auf I2, ohne Änderung übernommen.*

**PHP 8.2** und **MariaDB 10.11 LTS** — beides Debian-12-Standard und damit der
kleinste gemeinsame Nenner der drei Auslieferungskanäle. Die
Entwicklungsumgebung liegt darüber (PHP 8.5.5, MariaDB 12.3.2), das ist
unkritisch; ausschlaggebend ist, was der Zielserver mindestens können muss.

Gehört vor dem ersten Release an drei Stellen festgeschrieben: `composer.json`
(`"php": "^8.2"`), die Installationsdokumentation und das LXC-Skript.

---

---

## 7a. Systemvoraussetzungen (gelten für alle drei Kanäle)

Neben PHP-FPM und MariaDB braucht Aeronance **ein** Systempaket:

| Paket | wofür | ohne das passiert |
|---|---|---|
| `poppler-utils` (`pdftotext`) | Kennblatt-Listen des LBA lesen | jede Kennblatt-Suche meldet lautlos „kein Treffer" |

**Warum ein Binary, wo bewusst ein reines PHP-Paket gewählt worden war.** Das
Blaue Buch richtet seine Spalten durch Leerzeichen-Auffüllung aus — im PDF
stehen keine Tabellen und keine Tabs, nur Glyphen an x-Positionen. Ein
PHP-Parser liest die Textobjekte in Inhaltsreihenfolge und hängt sie aneinander;
aus

```
4505/EN     Walter Mikron III     Walter Motorlet
```

wird `4505/ENW alter Mikron IIIW alter Motorlet`. Die Spalten sind nicht
beschädigt, sie sind weg — nachgemessen: 0 Zeilen aus dem Motorenband, 0 aus
den Propellern, gegen 157 und 130 mit `pdftotext -layout`. Ein pure-PHP-Pendant
zu `-layout` gibt es nicht. Die Wahl stand also nicht zwischen zwei Bibliotheken,
sondern zwischen einem Systempaket und Handeingabe.

Vorgabe: „wenn du binaries brauchst bau sie ein. der Docker und lxc sollen ein
paket sein, bei direkt installation muss halt alles da sein."

Umsetzung pro Kanal:

- **Webserver-Pack** — steht in den Voraussetzungen; `php artisan
  aeronance:requirements` sagt vor der Installation, was fehlt
- **Docker** — im Image installiert, der Betreiber merkt nichts davon
- **Proxmox LXC** — das Installationsskript zieht es mit
- **CI** — im `.php_setup` installiert, sonst scheitern die Blaues-Buch-Tests

`aeronance:requirements` prüft ausserdem, dass die Datenbank wirklich MariaDB
meldet und nicht MySQL. Beides ist Absicht: Voraussetzungen, die man erst im
Betrieb bemerkt, bemerkt man am falschen Tag.

## 8. Was gebaut ist

| Datei | Aufgabe |
|---|---|
| `app/Core/Modules/Manifest.php` | Name, Version, `requires`, `conflicts`, `provides` |
| `app/Core/Modules/Contracts/AeronanceModule.php` | erweitert Filaments `Plugin` — ein Modul *ist* die Einheit, die Filament einhängt |
| `app/Core/Modules/ModuleRegistry.php` | kennt alle ausgelieferten Module; prüft Namenskollisionen und tote Verweise beim Start |
| `app/Core/Modules/DependencyResolver.php` | Graph, Zyklen, `conflicts` über Abhängigkeiten hinweg, Fähigkeitswarnungen — **ohne Framework, damit die Grenzfälle prüfbar sind** |
| `app/Core/Modules/Decision.php` | Verdikt mit Begründung, Mitschaltliste und Warnungen |
| `app/Core/Modules/CapabilityWarning.php` | Warnung als Daten, nicht als fertiger Satz |
| `app/Core/Modules/ModuleManager.php` | Persistenz, Aktivieren/Deaktivieren, Ereignisse |
| `app/Core/Modules/Events/` | `ModuleEnabled`, `ModuleDisabled` |
| `database/migrations/…_create_modules_table.php` | Zustandstabelle |
| `config/aeronance.php` | explizite Modulliste (D5), Vereinsname, Aufbewahrungsfristen |
| `lang/de/modules.php` | deutsche Texte |
| `tests/Unit/Modules/`, `tests/Feature/Modules/` | 24 Tests, davon 11 gegen echtes MariaDB |

**Zwei Entwurfsfehler haben die Tests gefunden**, beide inzwischen behoben: Die
Registry nahm nur Klassennamen und war dadurch schwer testbar (nimmt jetzt auch
Instanzen), und der Resolver hing an der Übersetzungsschicht (gibt jetzt
strukturierte Warnungen zurück, die die Oberfläche übersetzt).

## 9. Kern — Stand

Alles Folgende ist gebaut und getestet (Stand: **817 Tests**, alle gegen MariaDB):

| Bereich | Umsetzung | Entscheidung |
|---|---|---|
| Filament-Panel | nur der Kern registriert direkt; Module kommen als Plugins und nur solange aktiv | D3 |
| Rechte | tätigkeitsbezogen, vom jeweiligen Eigentümer deklariert, additiv synchronisiert | 5.1 |
| Rollen | fünf Startrollen, keine mit allen Rechten | CLAUDE.md |
| Qualifikationen | eigenes Konzept, zweistufige Autorisierung, PO je Luftfahrzeug | **E8** |
| Audit-Trail | `Activity` verweigert `update` und `delete`; kein Löschrecht | **E3** |
| Break-glass | nur Konsole, mit Protokoll, Ablauf und Benachrichtigung | **E2** |
| Modulverwaltung | erklärt vorher, was mitkommt und was blockiert | A2 |
| Setup-Assistent | Verriegelung über Marker, doppelt abgesichert | A7 |

### Was die Tests beim Bauen gefunden haben

Drei Entwurfsfehler, alle behoben statt umgangen — der dritte hätte in der
Praxis wehgetan:

1. `ModuleRegistry` nahm nur Klassennamen und war dadurch schwer testbar.
2. `DependencyResolver` griff auf die Übersetzungsschicht zu; reine
   Domänenlogik hat dort nichts verloren.
3. **Der Setup-Assistent verriegelte sich einen Schritt zu früh.** Das
   „Sicherheitsnetz" prüfte, ob die Installation *benutzt aussieht* — was genau
   in dem Moment wahr wird, in dem das Administratorkonto entsteht. Der
   Assistent hätte sich vor dem letzten Schritt selbst ausgesperrt. Die
   Absicherung sitzt jetzt an den einzelnen Schritten (`RequireSetupAuthority`):
   Sobald ein Administrator existiert, darf nur dieser weitermachen. Wer einen
   verlorenen Marker auf einem laufenden System findet, kann schauen und
   migrieren — mehr nicht.

## 10. CI — Stand und Runner

Die Pipeline (`.gitlab-ci.yml`) ist gelintet und valide: `test` gegen einen
echten MariaDB-10.11-Service (Leitplanke, kein SQLite), `audit` bricht bei
bekannten Schwachstellen ab, `style` prüft Pint — bewusst ohne DB-Setup, denn
Stilprüfung braucht keine Datenbank. Testberichte laufen als JUnit-Artefakt
nach GitLab; die erste Fassung hatte die Artefakte deklariert, aber nichts
schrieb die Datei.

### Wohin das Container-Image gehen soll

Der `container`-Job baut es bei jedem Tag und **verwirft es**, solange kein Ziel
gesetzt ist (`AERONANCE_IMAGE_REPO`, dazu Benutzer und Token). Das ist Absicht:
Ein Job, der an einer Anmeldung scheitert, die niemand beheben kann, sieht aus
wie ein kaputter Bau.

**Vorschlag: `ghcr.io`.** Die Veröffentlichungen laufen ohnehin über den
GitHub-Spiegel; öffentliche Images sind dort kostenlos, ein Verein braucht zum
Ziehen keinen Zugang, und es gibt keine Abrufgrenzen wie bei Docker Hub. Die
Registry der GitLab-Instanz wäre technisch einfacher, ist aber nicht öffentlich
erreichbar — ein Verein käme nicht heran, und damit wäre der Docker-Kanal für
seine Zielgruppe wertlos.

*Beim Einrichten beachten:* Anmeldung und Bildpfad sind zweierlei. Der Job
schneidet den Host vom Pfad ab (`ghcr.io` aus `ghcr.io/maruether/aeronance`) —
stünde beides in einer Variablen, scheiterte die Anmeldung bei jeder Registry
mit Pfadanteil.

### Veröffentlichen ist ein eigener Schritt, keine Spiegelung

Die automatische Push-Spiegelung nach GitHub ist **abgeschaltet**, und das ist
eine Entscheidung, keine offene Baustelle.

Der Grund liegt in der Aktualisierungsprüfung: Sie liest die **Tags des
öffentlichen Repositorys**. Bei automatischer Spiegelung wäre jeder interne Tag
im selben Augenblick ein Update für jede laufende Installation — auch der, mit
dem nur schnell etwas ausprobiert werden sollte. Zwischen „es ist fertig" und
„alle bekommen es" gehört eine Entscheidung.

Damit gilt: **GitLab ist die Entwicklung, GitHub der Veröffentlichungskanal.**
Intern taggen, bauen, prüfen — und danach

```bash
deploy/publish.sh v1.2.0            # oder --dry-run zum Ansehen
```

Das Skript nimmt den Baum des internen Tags, prüft dessen Signatur, baut daraus
einen signierten Commit und schiebt ihn samt Tag. Die öffentliche Historie ist
dadurch eine **Release-Historie**: je Veröffentlichung ein Commit, mit der
Changelog-Passage dieser Fassung als Nachricht.

*Warum nicht die interne Historie:* Sie besteht aus Notizen, die im
Arbeitsablauf entstanden sind, und erklärt Außenstehenden nichts. Was erklärt,
steht ohnehin im Baum — die Begründung jeder Entscheidung am Ort ihrer Wirkung,
das Protokoll in `docs/`, der CHANGELOG.

**Wer die Spiegelung wieder einschaltet, hebt das auf.** Dann schiebt jeder
interne Tag sofort ein Update an alle Installationen.

**Was fehlt, ist ein Runner** — die Instanz hat keinen einzigen, daran sind
die ersten beiden Pipelines gestorben (`stuck_pending_no_matching_runners`).

Ein Projekt-Runner ist GitLab-seitig angelegt (`aeronance-ci`, untagged). Der
Registrierungstoken liegt außerhalb des Repositorys — **niemals darin**.

Zur Infrastruktur: Es gibt zwei Maschinen — einen Entwicklungsrechner und einen
Server, auf dem die GitLab-Instanz läuft. (Die Proxmox-Erwähnung meint die
künftige *Referenzinstallation von Aeronance*, nicht die
Projekt-Infrastruktur.) Der Runner gehört auf den **Server**: die einzige
Maschine, die durchgehend läuft.
CI-Jobs teilen sich dort die Ressourcen mit GitLab und führen Repo-Code auf dem
Server aus, der die Repos hält — bei einer Ein-Personen-Instanz vertretbar,
**neu zu bewerten, sobald das Projekt öffentlich ist und fremde MRs Pipelines
auslösen** (dann: eigener Host oder zumindest eigene VM). Docker-Executor wegen
der Service-Container. Auf dem Server:

```bash
apt-get install gitlab-runner docker.io   # bzw. Distributions-Äquivalent
gitlab-runner register \
  --non-interactive \
  --url https://<gitlab-instanz> \
  --token "$(cat <pfad-zum-token>)" \
  --executor docker \
  --docker-image php:8.5-cli
```

Danach nimmt der Runner die nächste Pipeline automatisch an. Der Token ist an
das Projekt gebunden und läuft nicht ab; kompromittiert → Runner in GitLab
löschen, neu anlegen.

## 11. Stand der Module

| Modul | Stand | Doku |
|---|---|---|
| Kern | Rechte, Qualifikationen, Audit, Break-glass, Setup, Modulverwaltung | oben |
| Lager | Bauteiltypen, Lose, Bewegungen, Sperrlager, Form-1-Ablage, Inventur | `LAGERMODUL.md`, `INVENTURBERICHT.md` |
| Flotte | Luftfahrzeuge, Komponenten, Muster + Kennblatt, Lufttüchtigkeitsprüfung | `FLOTTE.md`, `MUSTER-KENNBLATT.md`, `KOMPONENTENMUSTER.md` |
| Arbeitskarten | Vorgänge, Befunde, Zeiten, Fremdarbeit, Freigabe (CRS) | `ARBEITSKARTEN.md`, `AUSGEBAUTE-TEILE.md` |
| Part-66 | Erfahrungslogbuch, Recency 66.A.20(b), ARS-Zähler | `PART66.md` |
| LTA/TM | fünf Abrufmodi, sieben Hersteller, Wochenlauf, Druckübersicht | `LTA-TM.md` |

### Was noch aussteht

1. **AuthZ-Negativtests je Filament-Resource.** Der Panel baut seine Resources
   beim Boot aus der Modultabelle; unter `RefreshDatabase` ist die leer, deshalb
   fehlen Routen und die üblichen Resource-Tests laufen nicht. Der Weg dahin ist
   ein Test-Setup, das die Module vor dem Panel-Boot aktiviert — noch nicht
   gebaut. *(Vorgabe: „AuthZ macht später fable.")*
2. **Identity-Provider-Module** (Vereinsflieger, LDAP/AD) — vorbereitet, aber
   nicht begonnen. Der Kern kann lokales Login inkl. Break-glass.
3. **Docker-Kanal steht — es fehlt nur das Ziel.** Der `container`-Job baut auf
   SemVer-Tags aus **demselben Tarball**, das `pack` erzeugt, prüft das Ergebnis
   und ist **einmal echt durchgelaufen** (Wegwerf-Tag `v0.0.6`, danach gelöscht):
   Archiv 216,9 MB, alle tragenden Pfade vorhanden. Dazu `deploy/docker/` mit
   Compose-Verbund (PHP-FPM, nginx, MariaDB, Worker, Scheduler), nginx-Konfig,
   Entrypoint und `.env`-Beispiel.

   **Gebaut wird mit Kaniko, und das ist gemessen.** Zuerst stand dort Buildah —
   der übliche Weg ohne `docker:dind`. Es scheiterte reproduzierbar an
   `unshare(CLONE_NEWUSER): Operation not permitted`. Ein Diagnose-Job zeigte:
   der Job läuft als **uid 0**, `max_user_namespaces` steht auf 2147483647 — es
   fehlt weder root noch erlaubt ein Limit zu wenig. Selbst `buildah info` fällt
   darüber. Es ist das Standard-seccomp-Profil von Docker, das unprivilegierten
   Containern das Anlegen von User-Namespaces verbietet; Buildah legt für seinen
   Speicher grundsätzlich einen an. Weder `--isolation chroot` noch Root-Speicher
   ändern daran etwas. Kaniko baut rein im Userspace — kein Daemon, kein
   Namespace, kein privilegierter Modus. Die Alternative wäre gewesen, seccomp
   für diesen Runner abzuschalten; ein hoher Preis für einen Build-Schritt.

   **Ohne Registry wird geprüft und verworfen, nicht hochgeladen.** Das
   OCI-Archiv sprengt das Upload-Limit der Instanz (`413 Request Entity Too
   Large`) — ein Bau, der durchläuft und am Artefakt scheitert, sieht aus wie ein
   kaputter Bau und ist keiner. Sobald eine Registry steht, genügen drei
   CI-Variablen: `AERONANCE_REGISTRY`, `_USER`, `_TOKEN`.

   **Die Registry ist bewusst vertagt, keine offene Lücke.** Das Projekt geht
   erst öffentlich, wenn es fertig ist — bis dahin hat das Image keinen
   Abnehmer, und ein Ablageort ohne Nutzer ist Speicher und ein bewegliches Teil
   mehr. Der Job liefert seinen Wert schon heute: er beweist auf jedem Tag, dass
   das Image baut und vollständig ist.

   Entschieden wird in zwei Schritten, und die Reihenfolge ergibt sich aus dem,
   was jeweils zählt:

   - **Sobald der Verbund selbst betrieben werden soll** (Testinstallation):
     die **GitLab-Registry der eigenen Instanz**. Privat, im eigenen Haus, und
     bei einem einzelnen Nutzer spielen Bandbreite und Uptime keine Rolle.
     Nötig: Subdomain, Zertifikat, Storage, Aktivierung in der `gitlab.rb`.
   - **Zur Veröffentlichung** zusätzlich **GHCR**. Dann zählt etwas anderes:
     Vereine ziehen das Image **anonym**, und Docker Hub drosselt genau das.
     GHCR kennt für öffentliche Pakete keine solche Grenze, und der öffentliche
     GitHub-Spiegel ist ohnehin geplant (CLAUDE.md, Distribution). Ausserdem
     hinge sonst der Installationsweg jeder Vereinsinstallation an der Uptime
     eines privaten Servers.

   Beides schliesst sich nicht aus — der Job bedient jedes Ziel, das in
   `AERONANCE_IMAGE_REPO` steht, und braucht dafür keine Änderung.

4. **LXC: beim Skript geblieben, und das ist eine Entscheidung.** Ein vorgebautes
   Template klingt sauberer, ist es hier aber nicht: Proxmox bezieht Templates
   über `pveam` aus den Repos von Proxmox und Turnkey — ein eigenes wäre ein
   manueller Datei-Upload, den niemand findet. Vor allem würden wir zum Betreuer
   des Basis-Systems: jedes Debian-Sicherheitsupdate wäre unser Problem, und ein
   Verein, der das Template nie neu zieht, fährt ein alterndes System. Ein Skript
   installiert aus Debians eigenen Quellen und aktualisiert per `apt` mit.

   Die dritte Variante — LXC mit Docker darin — verlangt Nesting und gilt in der
   Proxmox-Welt als Reibungspunkt; der Kanal wäre dann nur „der Docker-Kanal mit
   Zusatzschritten".

   **Die Skripte liegen jetzt in `deploy/lxc/`**, in der Zweiteilung der
   Community Scripts: `aeronance.sh` legt auf dem Proxmox-Host den Container an,
   `aeronance-install.sh` richtet darin den Stack ein und holt das Release.
   Diese Trennung ist deren Konvention und absichtlich übernommen — eine spätere
   Einreichung soll ein Pull Request sein und keine Umschreibung.

   **Debian 13**, weil Aeronance PHP 8.4 verlangt. Debian 12 liefert 8.2; dafür
   bräuchte es sury.org — eine Vertrauensbeziehung und eine Paketquelle mehr,
   die bei jedem Update mitzupflegen wäre. Nebenwirkung, die genannt gehört:
   Debian 13 bringt MariaDB 11.8, die CI testet gegen 10.11. Das ist die
   Richtung, in der Abweichungen harmlos sind — geprüft ist es trotzdem nicht.

   Das Skript **richtet nicht ein**: Vereinsname, Administratorkonto und
   Modulauswahl macht der Assistent im Browser, der die Modulabhängigkeiten
   kennt und erklärt. Den Datenbankzugang schreibt es dagegen selbst in die
   `.env` — der Assistent überspringt vorkonfigurierte Werte, und ein Verein
   soll kein Passwort erfinden müssen, das das Skript schon kennt.

   Aktualisiert wird über das mitgelieferte `deploy/update.sh` statt über eine
   zweite Umsetzung im Skript: zwei Wege bedeuten, dass einer irgendwann falsch
   ist, und der falsche wäre der, der beim Verein läuft.

   **Zwei Dinge fehlen, beide dieselbe Ursache wie bei der Registry:**
   `AERONANCE_RELEASE_URL` braucht ein öffentliches Tarball, das es noch nicht
   gibt (ohne die Variable **bricht das Skript ab**, statt eine halbe
   Installation zu hinterlassen) — und **gelaufen sind die Skripte nie**. Dafür
   braucht es einen Proxmox-Host, der laut Projektnotiz keine Infrastruktur
   dieses Projekts ist. Geprüft sind sie gegen `shellcheck`, der jetzt auch
   `deploy/lxc/` abdeckt.

5. ~~**Retention-Jobs**, je Datenklasse einzeln freischaltbar (F29).~~
   **Erledigt — und war weiter als gedacht.** `aeronance:retention` gab es
   samt Konfiguration und zehn Tests bereits: Aktivitätsprotokoll (3 Jahre),
   Break-glass-Protokoll (5 Jahre, überlebt das Aktivitätsprotokoll bewusst) und
   Pseudonymisierung ausgetretener Mitglieder (28 Tage). Jede Klasse einzeln
   schaltbar statt einer Frist mit Ausnahmeliste — so kann eine
   Fehlkonfiguration die Bestandsbewegungen gar nicht erreichen.

   **Was fehlte, war der Zeitplan.** Der Befehl stand in keinem: ein Verein
   konnte eine Regel einschalten, und es passierte nichts — kein Fehler, kein
   Hinweis. Eine Einstellung, die nichts bewirkt, ist schlimmer als keine, weil
   sie aussieht wie eine Zusage. Läuft jetzt täglich um 04:30 (täglich wegen der
   Pseudonymisierung: bei einem Wochenlauf würden aus 28 Tagen bis zu 35, und
   das ist die eine der drei Aufgaben, bei der Verspätung jemanden betrifft und
   nicht nur Speicher kostet). Ein Test prüft den Zeitplan, nicht die Datei.
6. ~~**2FA im Panel einschalten.**~~ **Erledigt.** Angeboten, nicht erzwungen:
   Authenticator-App mit Wiederherstellungscodes, einschaltbar im eigenen
   Profil. Geheimnis und Codes liegen verschlüsselt (`encrypted`-Cast) — ein
   TOTP-Geheimnis ist kein Hash, wer es liest, erzeugt jeden Code des Benutzers.
   Ein Betrieb, der 2FA vorschreiben muss, setzt `isRequired: true` im
   Panel-Provider.
7. ~~**Security-Header global setzen.**~~ **Erledigt.** `App\Core\Http\SecurityHeaders`
   als Middleware über allem: CSP, HSTS (nur über HTTPS), X-Frame-Options,
   nosniff, Referrer- und Permissions-Policy. Downloads bleiben unangetastet,
   damit der Dokumentenausgang seine engeren Header behält. Ein Test hält fest,
   dass `script-src` **nie** `unsafe-inline` bekommt — das ist die eine Zeile,
   die die ganze Richtlinie wertlos machte.

8. ~~**Die Spaltenzuordnung der Übersichtsblätter hängt an der Poppler-Version.**~~
   **Erledigt, und ohne die Version festzunageln.** Ursache war nicht poppler,
   sondern der Leser: eine *mittig* gesetzte Überschrift rastete auf die
   nächstgelegene gemessene Spalte ein — und unter den gemessenen Spalten war
   die, die die Überschrift selbst erzeugt hatte. Auf Grobs G-109-Blatt schnappte
   „Title" damit auf 56 statt auf die Titelspalte bei 24, und jeder Titel kam
   eine Spalte zu weit rechts.

   Eine mittige Überschrift rastet jetzt nur noch auf Spalten ein, die **Daten**
   belegen (`measuredFromData`). Die gewöhnliche Messung bleibt unangetastet —
   dort sind Überschriften Beleg und nicht Rauschen: Streifeneders
   Fristen-Spalte wird ausschliesslich durch ihre „Intervall"-Überschrift
   begründet, und sie mit auszuschliessen kostete neun von 51 Zeilen.

   Vorgesehen war angeboten, die Version einfach festzulegen — Docker und PVE
   geben das her. Das wäre die kleinere Lösung gewesen und hätte den dritten
   Kanal (eigener Server, fremdes poppler) ungeschützt gelassen. Beide
   Textfassungen liegen als Fixture im Repo, und ein Test prüft, dass sie
   dieselben Spalten und Titel ergeben.

9. **Sicherungen: verschlüsselt, ausgelagert, wiederherstellbar.** War als
   Frage aufgekommen („macht ein Backup-Modul Sinn?") und ist **im Kern**
   gelandet, nicht als Modul: ein Modul lässt sich deaktivieren, und eine
   Sicherung, die sich versehentlich abschalten lässt, ist genau die, die im
   Ernstfall fehlt.

   - **Zwei Verschlüsselungswege**, weil einer nicht reicht. Öffentlicher
     Schlüssel (Empfehlung — der Server kann seine eigenen Sicherungen nicht
     lesen) und Passwort. Vorgabe: „das bekommen viele nicht hin. wir sollten das
     einbauen und empfehlen, aber auch ein passwort anbieten." Umgesetzt mit
     OpenSSL, das Laravel ohnehin verlangt — kein zusätzliches Programm in
     irgendeinem der drei Kanäle.
   - **Kein Export ohne Verschlüsselung.** Ist ein Auslagerungsziel eingerichtet
     und `BACKUP_ENCRYPTION=none`, **scheitert** die Sicherung. Eine Warnung im
     Protokoll eines nächtlichen Laufs liest niemand, und der Klartext wäre
     trotzdem beim Anbieter. Lokal ohne Verschlüsselung bleibt erlaubt, damit
     eine frische Installation sichern kann, bevor jemand Schlüssel verwaltet —
     der Lauf sagt dann, dass sie im Klartext liegt.
   - **Die Kopie wird geprüft, nicht nur gesendet.** Ein abgebrochener Upload
     hinterlässt eine Datei, die es *gibt* — nur zu kurz. Stimmt die Grösse am
     Ziel nicht, wird sie wieder entfernt.
   - **Restore**, den CLAUDE.md seit jeher verlangt und den es nicht gab. Der
     Rundlauftest hat zwei echte Fehler gefunden: den rohen gzip-Strom nach dem
     Entschlüsseln und einen Pipe-Deadlock, der den Restore *hängen* liess statt
     scheitern.
   - **Beide Läufe stehen jetzt im Zeitplan** (Aufbewahrung 04:30, Sicherung
     05:00 — in dieser Reihenfolge, damit Gelöschtes nicht kurz vorher noch in
     eine Sicherung wandert und dort drei Jahre überlebt).

   **Die Ziele sind entschieden und eingebaut.** Vorgabe: „sftp, das wird die
   storage box. s3 wäre ein nice to have muss aber echt nicht sein wenn es
   overhead erzeugt."

   | Ziel | Paket | Gewicht |
   |---|---|---|
   | `offsite_local` (gemountetes Verzeichnis) | — | 0 |
   | `offsite_sftp` (Hetzner Storage Box) | `league/flysystem-sftp-v3` | **3,3 MB** (phpseclib) |
   | `offsite_s3` (Backblaze, Wasabi, MinIO …) | `league/flysystem-async-aws-s3` | **2,3 MB** |

   S3 kam trotz des Vorbehalts mit, weil der Overhead gemessen und dann
   vermieden wurde: Laravels eingebauter `s3`-Treiber verlangt
   `league/flysystem-aws-s3-v3`, das `aws/aws-sdk-php` nachzieht — **63 MB und
   3483 Dateien**, `vendor/` wächst von 152 auf 215 MB. Für ein Tarball, das
   `vendor/` fertig mitbringt, wäre das der Löwenanteil des Downloads, für einen
   Ablageort, den viele Vereine nie benutzen.

   `async-aws` leistet dasselbe für 2,3 MB — Faktor 27. Laravel kennt es nicht
   von selbst, deshalb meldet der `ModuleServiceProvider` einen Treiber
   `async-s3` an. Bewusst *nicht* `s3`: wer das AWS-SDK später doch braucht,
   findet den eingebauten Treiber unverändert vor.

   Ein Test baut beide Treiber wirklich auf. Dass ein Adapter fehlt, merkt man
   sonst nachts um fünf — im geplanten Lauf, dessen Fehlermeldung niemand liest.

   **Einmal wirklich über SFTP gelaufen.** Gegen einen Wegwerf-sshd auf
   127.0.0.1:2222 (eigener Hostkey, eigener Nutzerschlüssel, danach entfernt) —
   nicht gegen Hetzner, denn geprüft werden sollte der Transport und nicht ein
   bestimmter Anbieter. Durchgespielt wurden: Sichern → Verschlüsseln →
   Hochladen → Größenprüfung → Aufräumen am Ziel (`KEEP=2`, nach vier Läufen
   lagen zwei da) → Datei zurückholen → **Restore daraus**.

   Der Lauf hat prompt einen Fehler gefunden, den kein Unit-Test gezeigt hätte:
   ohne `visibility` legt der SFTP-Adapter die Dateien mit **0644** ab. Auf
   einem NAS oder einem geteilten Speicher wäre die Sicherung damit für jeden
   anderen Benutzer lesbar. Verschlüsselt ist sie zwar — aber wer sie heute
   mitnimmt, wartet auf den Tag, an dem das Passwort auftaucht. Jetzt 0600/0700,
   bei S3 ebenso.

**Nachgeprüft und ausdrücklich nicht offen:** Die Anmeldung ist gedrosselt —
Filaments Login-Seite ruft `rateLimit(5)` selbst auf. Das war beim Durchsehen
zunächst als Lücke notiert und ist keine.
