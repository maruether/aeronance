#!/usr/bin/env bash
#
# Aeronance aktualisieren -- ein Befehl, wie in CLAUDE.md vorgesehen.
#
#   sudo -u www-data deploy/update.sh v1.2.0
#   sudo -u www-data deploy/update.sh            # neuestes Release
#
# ─────────────────────────────────────────────────────────────────────────────
# DIE REIHENFOLGE IST DER GANZE PUNKT.
#
# Sicherung zuerst, und zwar bevor irgendetwas angefasst wird. Ein Update, das
# bei der Migration abbricht, hinterlässt eine Datenbank zwischen zwei
# Schemaständen -- ohne Sicherung ist das der Abend, an dem ein Verein seine
# Wartungsnachweise verliert. Die Aufbewahrungspflicht kennt keine Ausrede.
#
# Wartungsmodus, damit niemand mitten in einer Migration eine Freigabe erteilt.
#
# Und `set -e`: bricht ein Schritt ab, bricht das Update ab. Ein Skript, das
# weiterläuft, weil composer nur "irgendwie" fehlgeschlagen ist, liefert eine
# Installation aus halbem Alt- und halbem Neustand aus -- die schlimmste der
# möglichen Zustände, weil sie startet.
#
# ─────────────────────────────────────────────────────────────────────────────
# ZWEI ARTEN VON INSTALLATION, ZWEI WEGE ZUM NEUEN STAND.
#
# Eine GIT-INSTALLATION (Entwicklung, eigener Checkout) holt das Tag und baut
# selbst -- sie hat git, composer und eine Werkzeugkette.
#
# Eine TARBALL-INSTALLATION (Webserver-Pack, LXC) hat nichts davon, und das ist
# Absicht: Das Release bringt vendor/ und die gebauten Assets fertig mit.
# Ein `git checkout` hülfe hier doppelt nicht -- es gibt kein Repository, und
# selbst mit einem fehlten die Assets, denn public/build/ liegt bewusst nicht
# im Repo, sondern nur im Artefakt aus der CI. Der einzige Stand, der eine
# solche Installation aktualisieren kann, ist DASSELBE Artefakt in neu:
# der Release-Tarball von GitHub, geprüft und dann eingespielt.
#
# Woran das Skript die Art erkennt: am .git-Verzeichnis. Mehr Unterscheidung
# gibt es nicht, und beide Wege laufen durch dieselbe Reihenfolge --
# beschaffen und prüfen, sichern, Wartungsmodus, einspielen, migrieren.
# ─────────────────────────────────────────────────────────────────────────────

set -euo pipefail

cd "$(dirname "$0")/.."
ROOT="$(pwd)"
TARGET="${1:-}"
# Kein Zeitstempel hier: aeronance:backup benennt seine Dateien selbst
# (Ymd-His), und eine zweite Quelle dafuer waere eine, die irgendwann abweicht.
BACKUP_DIR="${AERONANCE_BACKUP_DIR:-$ROOT/storage/app/backups}"

say() { printf '\n\033[1m==> %s\033[0m\n' "$*"; }
die() { printf '\n\033[31mAbbruch: %s\033[0m\n' "$*" >&2; exit 1; }

[ -f artisan ] || die "hier steht kein Aeronance ($ROOT)."
[ -f .env ] || die ".env fehlt. Vor dem ersten Update muss das Setup gelaufen sein."

if [ -d .git ]; then
    MODUS=git
else
    MODUS=tarball
fi

# ─────────────────────────────────────────────────────────────────────────────
# Egal wie das Update ausgeht: die Instanz darf nicht im Wartungsmodus
# steckenbleiben. Ein Verein, der vor einer 503 steht und nicht weiss warum,
# ist schlechter dran als einer mit der alten Version.
#
# Die Trap räumt NUR auf, was dieses Skript selbst angelegt hat. Hier standen
# einmal Reste einer älteren Fassung, die noch selbst dumpte ($MYCNF, $DB_DUMP)
# -- mit `set -u` liess genau das jeden Lauf am Ende mit "unbound variable"
# und Exitcode 1 sterben, auch den erfolgreichen. systemd meldete daraufhin
# jede gelungene automatische Aktualisierung als Fehlschlag.
# ─────────────────────────────────────────────────────────────────────────────
STAGING=""
WARTUNG=0
finish() {
    if [ "$WARTUNG" = "1" ]; then
        php artisan up >/dev/null 2>&1 || true
    fi
    if [ -n "$STAGING" ]; then
        rm -rf "$STAGING"
    fi
}
trap finish EXIT

# ══ Schritt 1: Beschaffen und prüfen -- bis hierher wird nichts verändert ═════

if [ "$MODUS" = "git" ]; then
    command -v git >/dev/null || die "git fehlt."
    command -v composer >/dev/null || die "composer fehlt."

    say "Stand ermitteln"
    git fetch --tags --quiet origin

    if [ -z "$TARGET" ]; then
        TARGET="$(git tag -l 'v*' --sort=-v:refname | head -n1)"
        [ -n "$TARGET" ] || die "kein Release-Tag gefunden."
    fi

    git rev-parse -q --verify "refs/tags/$TARGET" >/dev/null \
        || die "Tag \"$TARGET\" gibt es nicht."

    CURRENT="$(git describe --tags --always)"
    echo "    von $CURRENT nach $TARGET"

    if [ "$CURRENT" = "$TARGET" ]; then
        say "Bereits auf $TARGET. Nichts zu tun."
        exit 0
    fi

    # Signaturprüfung: dieses Projekt signiert jeden Commit und jedes Tag. Ein
    # Tag ohne gültige Signatur ist kein Release dieses Projekts, und ein Update
    # ist der denkbar schlechteste Moment, das zu übersehen.
    if git tag -v "$TARGET" >/dev/null 2>&1; then
        echo "    Signatur des Tags geprüft"
    elif [ "${AERONANCE_SKIP_SIGNATURE:-}" = "1" ]; then
        echo "    WARNUNG: Signatur NICHT geprüft (AERONANCE_SKIP_SIGNATURE=1)"
    else
        die "Tag \"$TARGET\" trägt keine prüfbare Signatur.
    Öffentlichen Schlüssel importieren:  gpg --import .gitlab/signing-keys.asc
    Bewusst überspringen:                AERONANCE_SKIP_SIGNATURE=1"
    fi
else
    command -v curl >/dev/null || die "curl fehlt."
    command -v rsync >/dev/null || die "rsync fehlt (apt-get install rsync)."

    say "Stand ermitteln"
    CURRENT="$(cat VERSION 2>/dev/null || echo 'unbekannt')"

    if [ -z "$TARGET" ]; then
        # Die Anwendung weiss, was draussen ist -- dieselbe Prüfung, die auch
        # den Hinweis in der Oberfläche speist. --tag liefert genau eine Zeile,
        # wenn es etwas Neueres gibt, und sonst nichts.
        TARGET="$(php artisan aeronance:update-check --tag --fresh 2>/dev/null | tr -d '[:space:]' || true)"
        if [ -z "$TARGET" ]; then
            say "Kein Update verfügbar (aktuell: $CURRENT)."
            exit 0
        fi
    fi

    echo "    von $CURRENT nach $TARGET"

    if [ "$CURRENT" = "$TARGET" ]; then
        say "Bereits auf $TARGET. Nichts zu tun."
        exit 0
    fi

    # Woher der Tarball kommt: aus den GitHub-Releases des öffentlichen
    # Repositorys -- desselben, dessen Tags die Aktualisierungsprüfung liest.
    # AERONANCE_RELEASE_URL übersteuert das für Vereine mit eigenem Spiegel;
    # es ist dieselbe Variable, die schon die LXC-Installation kennt.
    #
    # Das Repository steht in der .env (kein Geheimnis); gelesen wie in
    # auto-update.sh, ohne dafür die halbe Anwendung hochzufahren.
    REPO="$(grep -E '^AERONANCE_UPDATE_REPOSITORY=' .env | head -n1 | cut -d= -f2- | tr -d '"'"'"' ' || true)"
    REPO="${REPO:-maruether/aeronance}"
    URL="${AERONANCE_RELEASE_URL:-https://github.com/${REPO}/releases/download/${TARGET}/aeronance-${TARGET}.tar.gz}"

    STAGING="$(mktemp -d)"

    say "Release holen"
    echo "    $URL"
    curl -fsSL "$URL" -o "$STAGING/release.tar.gz" \
        || die "das Release liess sich nicht holen. Liegt der Tarball als
    Release-Asset unter dem Tag ${TARGET}? (deploy/publish.sh lädt ihn hoch.)"

    # ─────────────────────────────────────────────────────────────────────────
    # GEPRÜFT WIRD DIE SIGNATUR, NICHT NUR DIE PRÜFSUMME.
    #
    # Eine .sha256 neben dem Tarball beweist, dass der Download heil ankam --
    # aber beide Dateien kommen vom selben Server. Wer den ändern kann, ändert
    # beide. Die abgetrennte Signatur (.asc) hängt dagegen am Schlüssel des
    # Projekts, und der liegt bereits HIER, in der laufenden Installation
    # (.gitlab/signing-keys.asc aus dem vorigen Release). Ein untergeschobenes
    # "Update" müsste also eine Signatur tragen, die es nicht bekommen kann.
    #
    # Das ist dieselbe Aussage, die im Git-Modus `git tag -v` trifft.
    # ─────────────────────────────────────────────────────────────────────────
    if curl -fsSL "${URL}.asc" -o "$STAGING/release.tar.gz.asc" 2>/dev/null; then
        command -v gpg >/dev/null || die "gpg fehlt, ohne geht die Signaturprüfung nicht (apt-get install gnupg)."
        export GNUPGHOME="$STAGING/gnupg"
        mkdir -m 700 "$GNUPGHOME"
        gpg --quiet --import .gitlab/signing-keys.asc 2>/dev/null \
            || die "der Schlüsselbund .gitlab/signing-keys.asc liess sich nicht laden."
        gpg --verify "$STAGING/release.tar.gz.asc" "$STAGING/release.tar.gz" >/dev/null 2>&1 \
            || die "die Signatur des Tarballs stimmt NICHT. Das ist kein Release dieses Projekts."
        unset GNUPGHOME
        echo "    Signatur des Tarballs geprüft"
    elif [ "${AERONANCE_SKIP_SIGNATURE:-}" = "1" ]; then
        echo "    WARNUNG: Signatur NICHT geprüft (AERONANCE_SKIP_SIGNATURE=1)"
        # Wenigstens die Unversehrtheit, falls eine Prüfsumme daliegt.
        if curl -fsSL "${URL}.sha256" -o "$STAGING/release.tar.gz.sha256" 2>/dev/null; then
            HASH="$(awk '{print $1}' "$STAGING/release.tar.gz.sha256")"
            (cd "$STAGING" && echo "$HASH  release.tar.gz" | sha256sum -c --status) \
                || die "die Prüfsumme des Downloads stimmt nicht."
            echo "    Prüfsumme stimmt"
        fi
    else
        die "neben dem Tarball liegt keine Signatur (.asc).
    Ein unsigniertes Release spielt dieses Skript nicht ein.
    Bewusst überspringen:  AERONANCE_SKIP_SIGNATURE=1"
    fi

    say "Auspacken"
    tar -xzf "$STAGING/release.tar.gz" -C "$STAGING"
    NEU="$(find "$STAGING" -maxdepth 1 -type d -name 'aeronance-*' | head -n1)"
    [ -n "$NEU" ] && [ -f "$NEU/artisan" ] \
        || die "das Archiv sieht nicht wie ein Aeronance-Release aus."
fi

# ══ Schritt 2: Sicherung, vor allem anderen ═══════════════════════════════════

say "Sicherung"

# Über artisan und nicht über ein paar Zeilen Shell, und zwar aus zwei Gründen:
# die .env ist keine INI-Datei (parse_ini_file liefert bei einer unquotierten
# Klammer stillschweigend einen LEEREN Datenbanknamen), und ein Passwort auf
# einer Kommandozeile steht in der Prozessliste. Beides hat dieses Projekt schon
# einmal gekostet.
php artisan aeronance:backup --path="$BACKUP_DIR" \
    || die "die Sicherung ist fehlgeschlagen -- ohne sie wird nicht aktualisiert."

# ══ Schritt 3: Ab hier wird verändert ═════════════════════════════════════════

say "Wartungsmodus"
php artisan down --render="errors::503" --retry=60 || true
WARTUNG=1

say "Code auf $TARGET"
if [ "$MODUS" = "git" ]; then
    git checkout --quiet "$TARGET"

    # Die Fassung hinterlegen, damit die Anwendung sie kennt. Im Tarball legt
    # der pack-Job diese Datei an; bei einer Git-Installation gibt es sie sonst
    # NIE -- und dann kann die Aktualisierungsprüfung nicht sagen, was hier
    # läuft, und meldet ewig "Entwicklungsstand".
    printf '%s\n' "$TARGET" > VERSION

    say "Abhängigkeiten"
    composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader
else
    # ─────────────────────────────────────────────────────────────────────────
    # EINSPIELEN PER RSYNC, MIT DREI AUSNAHMEN.
    #
    # --delete, damit nichts Altes liegen bleibt: eine entfernte Datei, die
    # weiter herumliegt, ist gebaut, um Monate später jemanden zu verwirren.
    # Ausgenommen bleiben die Dinge, die einer INSTALLATION gehören und nicht
    # dem Release: die .env, storage/ (Dokumente, Sicherungen, Logs) und der
    # storage-Link unter public/.
    #
    # vendor/ und public/build/ kommen dagegen vollständig aus dem Release --
    # genau deshalb braucht diese Installation weder composer noch node.
    #
    # Dass dieses Skript sich dabei selbst ersetzt, ist unbedenklich: rsync
    # schreibt neue Dateien daneben und benennt sie dann um; die laufende Shell
    # liest weiter aus der alten, bereits geöffneten Datei.
    # ─────────────────────────────────────────────────────────────────────────
    rsync -a --delete \
        --exclude='/.env' \
        --exclude='/storage/' \
        --exclude='/public/storage' \
        "$NEU"/ "$ROOT"/
fi

say "Voraussetzungen"
php artisan aeronance:requirements || die "das System bringt nicht alles mit -- siehe oben."

say "Migrationen"
php artisan migrate --force

# Rechte, die diese Fassung neu einfuehrt, entstehen in AccessSetup, nicht in
# einer Migration -- ohne diesen Aufruf existierten sie auf einer
# aktualisierten Installation nicht. Additiv und wiederholbar.
php artisan aeronance:sync-access

say "Caches"
php artisan optimize:clear
php artisan config:cache
php artisan route:cache
php artisan view:cache

say "Dienste neu starten"
php artisan queue:restart
# Der Scheduler liest bei jedem Durchlauf neu und braucht nichts.

say "Fertig: $CURRENT -> $TARGET"
echo
echo "    Sicherungen liegen in $BACKUP_DIR."
if [ "$MODUS" = "git" ]; then
    echo "    Zurück geht es mit:  git checkout $CURRENT && composer install --no-dev"
else
    echo "    Zurück geht es über das Einspielen des alten Release-Tarballs (${CURRENT})"
fi
# Die echte Syntax, nicht eine erfundene: restore nimmt die Dump-Datei als
# Argument -- ein Hinweis mit falscher Option waere am Abend des Ernstfalls
# eine zweite Baustelle.
echo "    und der Sicherung von eben:  php artisan aeronance:restore $BACKUP_DIR/db-<zeitstempel>.sql.gz \\"
echo "                                     --documents=$BACKUP_DIR/documents-<zeitstempel>.zip"
