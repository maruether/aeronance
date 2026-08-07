# Aeronance als Proxmox-LXC

Zwei Skripte, wie die [Proxmox VE Community
Scripts](https://github.com/community-scripts/ProxmoxVE) es vorsehen:

| Datei | Läuft wo | Tut was |
|---|---|---|
| `aeronance.sh` | auf dem **Proxmox-Host** | legt den Container an |
| `aeronance-install.sh` | **im Container** | installiert den Stack, holt das Release |

Die Trennung ist deren Konvention und hier absichtlich übernommen — eine
spätere Einreichung dort soll keine Umschreibung sein, sondern ein Pull
Request.

## Warum Debian 13

Aeronance verlangt **PHP 8.4**. Debian 12 liefert 8.2; dafür bräuchte es ein
Fremd-Repository (sury.org) — eine zusätzliche Vertrauensbeziehung und eine
Quelle mehr, die bei jedem Update mitgepflegt werden muss. Debian 13 bringt PHP
8.4 und MariaDB selbst mit.

**Nebenwirkung, die genannt gehört:** Debian 13 liefert MariaDB 11.8, die CI
testet gegen 10.11 (die Mindestversion aus den Leitplanken). Das ist die
Richtung, in der Abweichungen harmlos sind — geprüft ist es trotzdem nicht.

## Was das Skript *nicht* tut

Die Einrichtung selbst. Vereinsname, Administratorkonto und Modulauswahl macht
der **Assistent im Browser**: Er kennt die Abhängigkeiten zwischen den Modulen
und erklärt sie. Ein Skript, das das nachbaut, wäre ein zweiter Weg, der
irgendwann anders entscheidet.

Den **Datenbankzugang** schreibt das Skript dagegen selbst in die `.env` — der
Assistent erkennt vorkonfigurierte Werte und überspringt den Schritt. Ein Verein
soll kein Passwort erfinden müssen, das das Skript ohnehin schon kennt.

## Was noch fehlt

**Die Release-Adresse.** `AERONANCE_RELEASE_URL` muss auf ein Tarball zeigen, und
ein öffentliches gibt es noch nicht: `pack` legt es als CI-Artefakt ab, eine
Release-Seite wird nicht bedient. Ohne die Variable **bricht das Skript ab**,
statt eine halbe Installation zu hinterlassen.

Sobald das erste echte Release steht, ist das eine Zeile.

**Die Einreichung** bei den Community Scripts setzt laut CLAUDE.md ein
öffentliches, gepflegtes Projekt mit stabilen Releases voraus. Das ist der
letzte Schritt, nicht der erste — bis dahin liegen die Skripte hier und lassen
sich von Hand aufrufen:

```bash
# auf dem Proxmox-Host
bash -c "$(curl -fsSL https://…/aeronance.sh)"
```

## Ungetestet, und das ist ehrlich so gemeint

Beide Skripte sind gegen `shellcheck` geprüft (die CI tut das bei jedem Push)
und syntaktisch gültig. **Gelaufen sind sie nie** — dafür braucht es einen
Proxmox-Host, und der ist laut Projektnotiz keine Infrastruktur dieses Projekts.
Der erste echte Lauf gehört zum ersten Release.
