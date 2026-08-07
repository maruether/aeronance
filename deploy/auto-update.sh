#!/usr/bin/env bash
#
# Aeronance selbsttätig aktualisieren -- eigener Server und LXC.
#
# Gedacht für den systemd-Timer (aeronance-update.timer), läuft aber auch von
# Hand:  deploy/auto-update.sh
#
# ──────────────────────────────────────────────────────────────────────────────
# AUTOMATISCH HEISST HIER: DAS SKRIPT LÄUFT VON SELBST, NICHT AM SKRIPT VORBEI.
#
# Der Unterschied ist der ganze Punkt. Ein Dienst, der einfach den neuesten
# Stand hereinzieht, überspringt Sicherung, Wartungsmodus und Migration -- bei
# einer Anwendung mit Aufbewahrungspflicht ist das der Abend, an dem ein Verein
# seine Wartungsnachweise verliert.
#
# Dieses Skript entscheidet nur, OB etwas zu tun ist, und ruft dann
# deploy/update.sh auf. Damit gilt jede Vorsichtsmaßnahme von dort unverändert
# weiter: Signaturprüfung des Tags, Sicherung zuerst, Wartungsmodus, `set -e`,
# und am Ende wird der Wartungsmodus in jedem Fall wieder aufgehoben.
#
# ──────────────────────────────────────────────────────────────────────────────
# AUSGESCHALTET, BIS JEMAND ES EINSCHALTET.
#
# `AERONANCE_AUTO_UPDATE=true` in der .env. Ohne diese Zeile passiert nichts,
# auch wenn der Timer läuft -- eine Installation, die sich nach dem Auspacken
# selbsttätig verändert, ist eine Überraschung, und Überraschungen gehören nicht
# in eine Werkstatt.
#
# ──────────────────────────────────────────────────────────────────────────────
# WÄHREND 0.0.x IST DAS KEINE GUTE IDEE, und das sagt das Skript auch.
#
# Solange das Projekt vor 0.1.0 steht, sind Brüche zwischen zwei Fassungen
# ausdrücklich erlaubt. Wer in dieser Phase automatisch aktualisiert, bekommt
# sie nachts um halb vier. Deshalb weigert sich das Skript, wenn die Zielfassung
# unterhalb von 0.1.0 liegt -- es sei denn, jemand setzt
# AERONANCE_AUTO_UPDATE_PRERELEASE=1 und weiß, was er tut.
# ──────────────────────────────────────────────────────────────────────────────

set -euo pipefail

cd "$(dirname "$0")/.."
ROOT="$(pwd)"

say() { printf '\n\033[1m==> %s\033[0m\n' "$*"; }
die() { printf '\n\033[31mAbbruch: %s\033[0m\n' "$*" >&2; exit 1; }

[ -f artisan ] || die "hier steht kein Aeronance ($ROOT)."
[ -f .env ] || die ".env fehlt."

# ── Eingeschaltet? ────────────────────────────────────────────────────────────
# Aus der .env gelesen und nicht ueber artisan: Wer die Automatik abschaltet,
# will nicht, dass zum Nachsehen die halbe Anwendung hochgefahren wird.
FLAG="$(grep -E '^AERONANCE_AUTO_UPDATE=' .env | head -n1 | cut -d= -f2- | tr -d '"'"'"' ' || true)"

case "${FLAG:-}" in
    true|1|on) : ;;
    *)
        echo "Automatische Aktualisierung ist nicht eingeschaltet (AERONANCE_AUTO_UPDATE)."
        exit 0
        ;;
esac

# ── Gibt es etwas? ────────────────────────────────────────────────────────────
# --tag gibt genau eine Zeile aus, wenn etwas zu tun ist, und sonst gar nichts.
TARGET="$(php artisan aeronance:update-check --tag --fresh 2>/dev/null | tr -d '[:space:]' || true)"

if [ -z "$TARGET" ]; then
    echo "Kein Update verfügbar."
    exit 0
fi

# ── Vorabstand? ───────────────────────────────────────────────────────────────
case "$TARGET" in
    v0.0.*)
        if [ "${AERONANCE_AUTO_UPDATE_PRERELEASE:-}" != "1" ]; then
            die "\"$TARGET\" ist ein Vorabstand (0.0.x), und dort sind Brüche zwischen zwei
    Fassungen ausdrücklich erlaubt. Automatisch eingespielt bekämen Sie die
    nachts um halb vier.

    Von Hand aktualisieren:      deploy/update.sh $TARGET
    Bewusst trotzdem automatisch: AERONANCE_AUTO_UPDATE_PRERELEASE=1"
        fi
        echo "    Vorabstand $TARGET, ausdrücklich zugelassen."
        ;;
esac

say "Automatische Aktualisierung auf $TARGET"

# Und ab hier macht es das richtige Skript -- mit Signaturpruefung, Sicherung,
# Wartungsmodus und allem, was dort begruendet steht.
exec "$ROOT/deploy/update.sh" "$TARGET"
