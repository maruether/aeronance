#!/usr/bin/env bash
#
# Aeronance aktualisieren -- ein Befehl, wie in CLAUDE.md vorgesehen.
#
#   sudo -u www-data deploy/update.sh v1.2.0
#   sudo -u www-data deploy/update.sh            # neuestes Tag
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

command -v git >/dev/null || die "git fehlt."
command -v composer >/dev/null || die "composer fehlt."

# ── Was ist der Zielstand? ────────────────────────────────────────────────────
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

# Signaturprüfung: dieses Projekt signiert jeden Commit und jedes Tag. Ein Tag
# ohne gültige Signatur ist kein Release dieses Projekts, und ein Update ist der
# denkbar schlechteste Moment, das zu übersehen.
if git tag -v "$TARGET" >/dev/null 2>&1; then
    echo "    Signatur des Tags geprüft"
elif [ "${AERONANCE_SKIP_SIGNATURE:-}" = "1" ]; then
    echo "    WARNUNG: Signatur NICHT geprüft (AERONANCE_SKIP_SIGNATURE=1)"
else
    die "Tag \"$TARGET\" trägt keine prüfbare Signatur.
    Öffentlichen Schlüssel importieren:  gpg --import .gitlab/signing-keys.asc
    Bewusst überspringen:                AERONANCE_SKIP_SIGNATURE=1"
fi

# ── Sicherung, vor allem anderen ──────────────────────────────────────────────
say "Sicherung"

# Über artisan und nicht über ein paar Zeilen Shell, und zwar aus zwei Gründen:
# die .env ist keine INI-Datei (parse_ini_file liefert bei einer unquotierten
# Klammer stillschweigend einen LEEREN Datenbanknamen), und ein Passwort auf
# einer Kommandozeile steht in der Prozessliste. Beides hat dieses Projekt schon
# einmal gekostet.
php artisan aeronance:backup --path="$BACKUP_DIR" \
    || die "die Sicherung ist fehlgeschlagen -- ohne sie wird nicht aktualisiert."

# ── Ab hier wird verändert ────────────────────────────────────────────────────
say "Wartungsmodus"
php artisan down --render="errors::503" --retry=60 || true

# Egal wie das Update ausgeht: die Instanz darf nicht im Wartungsmodus
# steckenbleiben. Ein Verein, der vor einer 503 steht und nicht weiss warum,
# ist schlechter dran als einer mit der alten Version.
finish() {
    php artisan up >/dev/null 2>&1 || true
    rm -f "$MYCNF" "$DBNAME_FILE"
}
trap finish EXIT

say "Code auf $TARGET"
git checkout --quiet "$TARGET"

# ─────────────────────────────────────────────────────────────────────────────
# Die Fassung hinterlegen, damit die Anwendung sie kennt.
#
# Im Tarball und im Docker-Image legt der pack-Job diese Datei an; bei einer
# Git-Installation gibt es sie sonst NIE -- und dann kann die
# Aktualisierungspruefung nicht sagen, was hier laeuft, und meldet ewig
# "Entwicklungsstand". Eine Zeile, die genau diese Luecke schliesst.
# ─────────────────────────────────────────────────────────────────────────────
printf '%s\n' "$TARGET" > VERSION

say "Abhängigkeiten"
composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader

say "Voraussetzungen"
php artisan aeronance:requirements || die "das System bringt nicht alles mit -- siehe oben."

say "Migrationen"
php artisan migrate --force

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
echo "    Zurück geht es mit:  git checkout $CURRENT && composer install --no-dev"
echo "    und dem Einspielen von $(basename "$DB_DUMP")."
