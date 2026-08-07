# Betrieb

Beispieldateien für den **Webserver-Kanal**: eigener Server oder Container mit
Root-Zugriff, kein Shared Webspace. Docker und das Proxmox-LXC-Skript kommen
später und benutzen dieselben Releases.

## Voraussetzungen

| | |
|---|---|
| PHP | 8.4+ mit `pdo_mysql`, `intl`, `gd`, `zip`, `bcmath`, `fileinfo` |
| MariaDB | 10.11+ (**kein MySQL** — siehe CLAUDE.md, Leitplanken) |
| `poppler-utils` | `pdftotext`, für die Kennblatt-Listen des LBA |
| `mariadb-client` | `mariadb-dump`, für Sicherungen |

Ob das System alles mitbringt:

```
php artisan aeronance:requirements
```

## Erste Installation

1. Release-Tarball auspacken nach `/var/www/aeronance` (enthält `vendor/` und
   die gebauten Assets — Composer und Node sind auf dem Zielsystem nicht nötig)
2. `cp .env.example .env`, dann `php artisan key:generate`
3. Rechte: `chown -R www-data:www-data storage bootstrap/cache`
4. Webserver einrichten — `nginx.conf` oder `apache.conf` als Vorlage.
   **Der Document-Root zeigt auf `public/`.** Eine Ebene höher wären `.env`,
   `storage/` und der gesamte Code über HTTP lesbar
5. Dienste: `aeronance-worker.service` und `aeronance-scheduler.service` nach
   `/etc/systemd/system/`, dann `systemctl enable --now`
6. Im Browser aufrufen — der Setup-Assistent übernimmt: Datenbankverbindung,
   Migrationen, Administratorkonto, Vereinsname, Modulauswahl. Danach
   verriegelt er sich dauerhaft

## Aktualisieren

```
sudo -u www-data deploy/update.sh v1.2.0
sudo -u www-data deploy/update.sh            # neuestes Tag
```

Der Ablauf: Signatur des Tags prüfen → **Sicherung** (Datenbank + Dokumente) →
Wartungsmodus → Code → Abhängigkeiten → Voraussetzungen → Migrationen → Caches →
Worker neu starten → Wartungsmodus aus.

Bricht ein Schritt ab, bricht das Update ab — und der Wartungsmodus wird in
jedem Fall wieder aufgehoben. Eine halb aktualisierte Installation ist der
schlechteste mögliche Zustand, weil sie startet.

`.env` und `storage/` bleiben unberührt.

### Im Docker-Betrieb

```
deploy/docker/update.sh v1.2.0
```

Dieselbe Reihenfolge, ein Unterschied: **Das Image wird zuerst geholt**, noch
vor der Sicherung. Es ist der einzige Schritt, der von außen abhängt, und er ist
folgenlos — bricht er ab (Tag vertippt, Registry nicht erreichbar), läuft die
alte Installation unverändert weiter, und niemand musste dafür eine Sicherung
anfertigen.

Danach: Sicherung im alten Container → Wartungsmodus → Tag in die `.env` →
`docker compose up -d` (App, Worker und Scheduler teilen sich das Image, alle
drei kommen mit) → warten, bis die Anwendung antwortet → Voraussetzungen →
**Migrationen** → Caches → Wartungsmodus aus.

Der Schritt in Fettdruck ist der, den der Handbetrieb ausläßt: Der Entrypoint
spiegelt nur `public/` ins geteilte Volume und migriert nicht.

Kein Watchtower und kein `latest`. Ein Dienst, der Images von selbst zieht, tut
genau das, was hier nicht passieren darf — aktualisieren ohne Sicherung, ohne
Wartungsmodus, ohne Migration. Automatisch ist die *Benachrichtigung*
(`aeronance:update-check`), nicht die Ausführung.

### Selbsttätig

Geht in allen drei Wegen, ab Werk aus. `AERONANCE_AUTO_UPDATE=true` in der
`.env`, dazu der Timer:

```
install -m 644 deploy/aeronance-update.{service,timer} /etc/systemd/system/
systemctl daemon-reload && systemctl enable --now aeronance-update.timer
```

Für Docker liegen die Units unter `deploy/docker/` und gehören auf den **Wirt**
— ein Updater-Container bräuchte den Docker-Socket, und wer den hat, ist auf dem
Wirt root.

`auto-update.sh` entscheidet nur, ob etwas zu tun ist, und ruft dann
`update.sh`. Damit gilt alles von oben unverändert: Signaturprüfung, Sicherung,
Wartungsmodus, Migration. **Automatisiert wird der Ablauf, nicht der Weg daran
vorbei** — das ist der Unterschied zu Watchtower und Ähnlichem, das Images zieht
und genau diese Schritte überspringt.

Während 0.0.x weigert es sich, weil dort Brüche erlaubt sind
(`AERONANCE_AUTO_UPDATE_PRERELEASE=1` hebt das auf).

## Veröffentlichen

```
deploy/publish.sh v1.2.0            # --dry-run zeigt nur, was entstünde
```

Intern taggen und bauen ist das eine, veröffentlichen das andere. Die
Aktualisierungsprüfung der Anwendung liest die Tags des öffentlichen
Repositorys — bei automatischer Spiegelung wäre jeder interne Tag im selben
Augenblick ein Update für jede laufende Installation.

Das Skript nimmt den Baum des internen Tags, prüft dessen Signatur, baut einen
signierten Commit mit der Changelog-Passage dieser Fassung als Nachricht und
schiebt ihn samt Tag. Der Arbeitsbaum wird dabei nicht angefasst.

## Sichern

```
php artisan aeronance:backup                 # Datenbank + Dokumente
php artisan aeronance:backup --keep=30
```

Landet in `storage/app/backups`. Der Zeitstempel ist für beide Teile derselbe,
damit sie als Paar zurückgespielt werden können.

**Zurückspielen**, und das gehört einmal geübt und nicht am Ernstfall gelernt:

```
zcat storage/app/backups/db-JJJJMMTT-HHMMSS.sql.gz | mariadb -u <user> -p <datenbank>
unzip storage/app/backups/documents-JJJJMMTT-HHMMSS.zip -d storage/app/
```

## Ein Release schneiden

```
# 1. CHANGELOG.md: den Abschnitt [Unveröffentlicht] auf die Version umbenennen,
#    "Beim Update beachten" ausfüllen, committen
# 2. Signiertes, annotiertes Tag -- ein leichtgewichtiges Tag trägt keine
#    Signatur, und deploy/update.sh verweigert dann den Dienst
git tag -s v1.2.0 -m "Aeronance 1.2.0"
git push origin v1.2.0
```

Die Pipeline baut daraufhin `aeronance-v1.2.0.tar.gz` samt SHA-256. Das Archiv
enthält `vendor/` und die gebauten Assets und wird vor dem Hochladen geprüft:
keine `.env`, keine `tests/`, keine `node_modules/`, dafür `.env.example`,
`vendor/autoload.php`, das Asset-Manifest und `VERSION`.

Gebaut wird gegen die **Mindestversion** von PHP, nicht gegen die neueste.
Genau daran ist einmal aufgefallen, dass `composer.json` 8.3 versprach, während
der Lock 8.4.1 verlangte.

## Was NICHT in den Webserver gehört

Die Dokumente — Form 1, CRS, Wägeberichte, Fotos — liegen auf einer privaten
Disk ausserhalb des Document-Roots und werden ausschliesslich über
auth-geprüfte Controller ausgeliefert. Es gibt nichts freizugeben, und ein
Alias darauf würde die Zugriffsprüfung aushebeln.
