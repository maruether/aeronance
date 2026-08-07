#!/usr/bin/env bash
#
# Aeronance selbsttätig aktualisieren -- Docker-Betrieb.
#
# Gedacht für den systemd-Timer auf dem WIRT (aeronance-update.timer), läuft
# aber auch von Hand:  deploy/docker/auto-update.sh
#
# ──────────────────────────────────────────────────────────────────────────────
# AUF DEM WIRT UND NICHT IM CONTAINER, und das ist eine Sicherheitsentscheidung.
#
# Der naheliegende Weg wäre ein kleiner Updater-Container mit dem Docker-Socket
# darin. Wer den Socket hat, ist auf dem Wirt root -- ein Ausbruch aus diesem
# Container wäre kein Ausbruch mehr, sondern ein Login. Für eine Anwendung, die
# ins offene Internet zeigt, ist das der falsche Handel.
#
# Ausserdem kann sich der App-Container nicht selbst neu erstellen: Er würde den
# Prozess abschiessen, der gerade das Update ausführt.
#
# ──────────────────────────────────────────────────────────────────────────────
# ES ENTSCHEIDET NUR UND RUFT AUF. Sicherung, Wartungsmodus, Migration und
# Caches stehen in update.sh nebenan und gelten unverändert -- siehe dort.
# ──────────────────────────────────────────────────────────────────────────────

set -euo pipefail

cd "$(dirname "$0")"
HERE="$(pwd)"

say() { printf '\n\033[1m==> %s\033[0m\n' "$*"; }
die() { printf '\n\033[31mAbbruch: %s\033[0m\n' "$*" >&2; exit 1; }

[ -f docker-compose.yml ] || die "hier steht kein Compose-Setup ($HERE)."
[ -f .env ] || die ".env fehlt."

command -v docker >/dev/null || die "docker fehlt."

FLAG="$(grep -E '^AERONANCE_AUTO_UPDATE=' .env | head -n1 | cut -d= -f2- | tr -d '"'"'"' ' || true)"

case "${FLAG:-}" in
    true|1|on) : ;;
    *)
        echo "Automatische Aktualisierung ist nicht eingeschaltet (AERONANCE_AUTO_UPDATE)."
        exit 0
        ;;
esac

# Die Frage stellt die Anwendung selbst -- im laufenden Container, weil nur sie
# weiss, welche Fassung sie ist und welches Repository sie beobachtet.
TARGET="$(docker compose exec -T app php artisan aeronance:update-check --tag --fresh 2>/dev/null | tr -d '[:space:]' || true)"

if [ -z "$TARGET" ]; then
    echo "Kein Update verfügbar."
    exit 0
fi

case "$TARGET" in
    v0.0.*)
        if [ "${AERONANCE_AUTO_UPDATE_PRERELEASE:-}" != "1" ]; then
            die "\"$TARGET\" ist ein Vorabstand (0.0.x) -- dort sind Brüche zwischen zwei
    Fassungen ausdrücklich erlaubt.

    Von Hand:                     deploy/docker/update.sh $TARGET
    Bewusst trotzdem automatisch: AERONANCE_AUTO_UPDATE_PRERELEASE=1"
        fi
        echo "    Vorabstand $TARGET, ausdrücklich zugelassen."
        ;;
esac

say "Automatische Aktualisierung auf $TARGET"

exec "$HERE/update.sh" "$TARGET"
