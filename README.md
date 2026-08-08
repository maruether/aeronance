# Aeronance

**Werkstatt- und Lagerverwaltung für Luftsportvereine.**
Selbstgehostet, modular, Open Source (AGPL-3.0).

Vereinswerkstätten stehen vor denselben Aufgaben wie große
Instandhaltungsbetriebe — Rückverfolgbarkeit von Teilen, Nachweisführung,
Fristen —, nur ohne deren Budget und Personal. Aeronance schließt die Lücke:
klein genug für einen Verein, der nur sein Ersatzteillager führen will, und
ausbaufähig bis in die Nähe eines kleinen Part-145-Betriebs. Jedes Modul
lässt sich einzeln aktivieren; Abschalten löscht keine Daten.

| Modul | Zweck |
|---|---|
| **Lager** | Bauteiltypen, Lose, Bestände, Nachweise (Form 1), Bestellungen |
| **Flotte** | Luftfahrzeuge, Zähler, Komponenten, Fristen, Wägung, Fremdvergabe |
| **LTA / TM** | Anweisungen der Hersteller und Behörden, je Luftfahrzeug beurteilt |
| **Arbeitskarten** | Vorgänge, Karten, Befunde, Arbeitszeiten und die Freigabe (CRS) |
| **Eingangsprüfung** | Ware bleibt gesperrt, bis die Prüfung unterschrieben ist |
| **Werkzeuge** | Kalibrierfristen, Nachprüfzeiträume, Ausgabe |
| **Erfahrungslogbuch** | Part-66-Nachweis, vollständig aus den Karten abgeleitet |
| **Vereinsflieger** | Mitgliederabgleich, Betriebszeiten, Arbeitsstunden |

## Installation

Drei Wege, ein Release: **eigener Server** (Tarball mit fertigem `vendor/`
und Assets — kein Composer, kein Node auf dem Zielsystem), **Docker**
(`deploy/docker/`) oder **Proxmox LXC** (`deploy/lxc/`). Voraussetzungen:
PHP 8.4+, **MariaDB 10.11+** (MySQL wird nicht unterstützt),
`poppler-utils`, `mariadb-client`.

Die Schritt-für-Schritt-Anleitung steht im Handbuch; nach der Installation
führt der Setup-Assistent im Browser durch den Rest und verriegelt sich
danach dauerhaft. Aktualisiert wird mit einem Befehl (`deploy/update.sh`) —
Sicherung zuerst, signierte Releases, Wartungsmodus inklusive.

## Dokumentation

- **[Benutzerhandbuch](HANDBUCH.md)** — alle Module und Abläufe, vom
  Setup-Assistenten bis zur Freigabe, samt vollständiger Kommandoreferenz
  für den Betrieb. Die erste Adresse.
- [deploy/README.md](deploy/README.md) — vhost-Vorlagen, systemd, Docker,
  LXC, Veröffentlichen.
- [docs/](docs/) — Entscheidungsdokumente und fachliche Analysen, für alle,
  die verstehen wollen, *warum* etwas so gebaut ist (nur im Repository,
  nicht im Release-Paket).

## Regulatorischer Hinweis

Aeronance unterstützt die Nachweisführung nach VO (EU) 1321/2014 (Part-ML,
Part-CAO). **Es ist kein zugelassenes System und ersetzt keine Prüfung durch
die zuständige Behörde.** Die Verantwortung für die Einhaltung der
Regularien bleibt beim Betreiber.

## Mitmachen

Beiträge sind willkommen — besonders von Leuten, die selbst in einer
Vereinswerkstatt stehen. Wie, steht in [CONTRIBUTING.md](CONTRIBUTING.md);
zwei Regeln vorweg: **alles wird signiert**, und die Datenbank ist
**MariaDB**.

Sicherheitslücken bitte **nicht** als Issue, sondern an
[security@aeronance.de](mailto:security@aeronance.de) — Einzelheiten und die
Regeln fürs Ausprobieren stehen in [SECURITY.md](SECURITY.md).

## Lizenz

[AGPL-3.0](LICENSE). Kein CLA — jeder Beitragende behält sein Copyright.
