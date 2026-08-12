#!/usr/bin/env bash
#
# Aeronance im Docker-Betrieb aktualisieren -- ein Befehl, wie beim Webserver-Pack.
#
#   deploy/docker/update.sh v1.2.0
#
# ──────────────────────────────────────────────────────────────────────────────
# WARUM ES DIESES SKRIPT ÜBERHAUPT GIBT.
#
# Der naheliegende Weg -- Tag in der `.env` ändern, `docker compose pull`,
# `up -d` -- lässt genau den Schritt aus, auf den es ankommt: DIE MIGRATION.
# Der Entrypoint spiegelt nur `public/` ins geteilte Volume (siehe dort, guter
# Grund), er migriert nicht. Nach einem Image-Wechsel läuft also die neue
# Anwendung gegen das alte Schema.
#
# Das fällt beim ersten Zugriff auf eine neue Spalte auf. Oder schlimmer: Es
# fällt nicht auf, weil die betroffene Seite selten aufgerufen wird.
#
# ──────────────────────────────────────────────────────────────────────────────
# DIE REIHENFOLGE IST DIESELBE WIE IN deploy/update.sh, und aus denselben
# Gründen -- Sicherung zuerst, Wartungsmodus vor der Migration, `set -e`.
#
# Ein Unterschied: DAS IMAGE WIRD ZUERST GEHOLT, noch vor der Sicherung. Es ist
# der einzige Schritt, der von außen abhängt, und er ist folgenlos, solange
# nichts anderes angefasst wurde. Bricht er ab -- Tag vertippt, Registry nicht
# erreichbar --, läuft die alte Installation unverändert weiter, und niemand
# musste eine Sicherung dafür anfertigen.
#
# ──────────────────────────────────────────────────────────────────────────────
# KEIN WATCHTOWER, KEIN `latest`, und das ist eine bewusste Entscheidung.
#
# Ein Dienst, der neue Images von selbst zieht, tut genau das, was hier nicht
# passieren darf: aktualisieren ohne Sicherung, ohne Wartungsmodus, ohne
# Migration. Bei einer Anwendung mit Aufbewahrungspflicht ist das der Abend, an
# dem ein Verein seine Wartungsnachweise verliert.
#
# Automatisch ist hier deshalb die BENACHRICHTIGUNG (aeronance:update-check),
# nicht die Ausführung. Was aktualisiert wird, entscheidet ein Mensch.
# ──────────────────────────────────────────────────────────────────────────────

set -euo pipefail

cd "$(dirname "$0")"
HERE="$(pwd)"
TARGET="${1:-}"

say() { printf '\n\033[1m==> %s\033[0m\n' "$*"; }
die() { printf '\n\033[31mAbbruch: %s\033[0m\n' "$*" >&2; exit 1; }

[ -f docker-compose.yml ] || die "hier steht kein Compose-Setup ($HERE)."
[ -f .env ] || die ".env fehlt. Vor dem ersten Update muss das Setup gelaufen sein."

command -v docker >/dev/null || die "docker fehlt."
docker compose version >/dev/null 2>&1 || die "docker compose (v2) fehlt."

[ -n "$TARGET" ] || die "welche Fassung? Beispiel:  $0 v1.2.0

    Absichtlich kein \"neuestes nehmen\": Was eingespielt wird, entscheidet ein
    Mensch. Welche Fassung es gibt, sagt  aeronance:update-check."

# ── Zielbild bestimmen ────────────────────────────────────────────────────────
say "Stand ermitteln"

CURRENT_IMAGE="$(grep -E '^AERONANCE_IMAGE=' .env | head -n1 | cut -d= -f2-)"
[ -n "$CURRENT_IMAGE" ] || die "AERONANCE_IMAGE steht nicht in der .env."

# Repository vom Tag trennen. Der Doppelpunkt in einer Registry mit Port
# (registry:5000/aeronance:v1) macht das zur Fummelei -- deshalb ab dem
# letzten Schrägstrich suchen und nicht global.
REPO="${CURRENT_IMAGE%:*}"
case "${CURRENT_IMAGE##*/}" in
    *:*) : ;;                       # Tag vorhanden, REPO stimmt
    *)   REPO="$CURRENT_IMAGE" ;;   # gar kein Tag -- dann ist alles Repository
esac

TARGET_IMAGE="$REPO:$TARGET"

echo "    von $CURRENT_IMAGE"
echo "    nach $TARGET_IMAGE"

if [ "$CURRENT_IMAGE" = "$TARGET_IMAGE" ]; then
    say "Bereits auf $TARGET. Nichts zu tun."
    exit 0
fi

# ── Image holen, bevor irgendetwas angefasst wird ─────────────────────────────
say "Image holen"
docker pull "$TARGET_IMAGE" || die "das Image \"$TARGET_IMAGE\" gibt es nicht oder die Registry ist nicht erreichbar.
    Bis hierher wurde nichts verändert -- die Installation läuft unverändert weiter."

# ── Sicherung, vor allem anderen ──────────────────────────────────────────────
say "Sicherung"

# Im laufenden ALTEN Container, denn nur der passt noch zum aktuellen Schema.
# Die Sicherung landet im storage-Volume und überlebt das Neuerstellen.
docker compose exec -T app php artisan aeronance:backup \
    || die "die Sicherung ist fehlgeschlagen -- ohne sie wird nicht aktualisiert."

# ── Ab hier wird verändert ────────────────────────────────────────────────────
say "Wartungsmodus"
docker compose exec -T app php artisan down --render="errors::503" --retry=60 || true

# Egal wie das Update ausgeht: die Instanz darf nicht im Wartungsmodus
# steckenbleiben. Ein Verein, der vor einer 503 steht und nicht weiss warum,
# ist schlechter dran als einer mit der alten Version.
#
# `|| true` an jeder Stelle: Diese Aufraeumroutine laeuft auch dann, wenn gar
# kein Container mehr steht -- und sie darf den Abbruchgrund nicht ueberschreiben.
finish() {
    docker compose exec -T app php artisan up >/dev/null 2>&1 || true
}
trap finish EXIT

say "Fassung eintragen"
# In der .env, nicht auf der Kommandozeile: Ein spaeteres `docker compose up`
# ohne dieses Skript muss dieselbe Fassung starten, sonst faellt die
# Installation beim naechsten Neustart still auf die alte zurueck.
TMP_ENV="$(mktemp)"
sed "s|^AERONANCE_IMAGE=.*|AERONANCE_IMAGE=$TARGET_IMAGE|" .env > "$TMP_ENV"
cat "$TMP_ENV" > .env          # Inhalt ersetzen statt Datei tauschen -- Rechte
rm -f "$TMP_ENV"               # und Besitzer der .env bleiben, wie sie waren

say "Container neu erstellen"
# App, Worker und Scheduler benutzen DASSELBE Image -- alle drei muessen mit.
docker compose up -d --remove-orphans

# nginx MUSS danach einmal durchstarten, und das ist gemessen, nicht Theorie:
# Er loest den Upstream "app" beim Start auf und haelt die IP. Der neue
# app-Container bekommt eine andere -- und jede Anfrage lief beim
# v0.1.2-Update in ein 502, waehrend darunter alles gesund war. Ein restart
# kostet eine Sekunde; die Alternative (resolver-Direktive mit variablem
# Upstream in der nginx-Konfiguration) kaeme bei einem Update NICHT bei
# Bestandsinstallationen an, deren nginx.conf schon liegt.
docker compose restart web >/dev/null 2>&1 || true

say "Auf die Anwendung warten"
for _ in $(seq 1 30); do
    if docker compose exec -T app php artisan --version >/dev/null 2>&1; then
        break
    fi
    sleep 2
done
docker compose exec -T app php artisan --version >/dev/null 2>&1 \
    || die "der neue Container antwortet nicht. Die Instanz steht im Wartungsmodus;
    Protokoll ansehen mit:  docker compose logs app"

say "Voraussetzungen"
docker compose exec -T app php artisan aeronance:requirements \
    || die "das neue Image bringt nicht alles mit -- siehe oben."

say "Migrationen"
# DER SCHRITT, DEN DER HANDBETRIEB AUSLAESST.
docker compose exec -T app php artisan migrate --force

say "Caches"
docker compose exec -T app php artisan optimize:clear
docker compose exec -T app php artisan config:cache
docker compose exec -T app php artisan route:cache
docker compose exec -T app php artisan view:cache

say "Dienste"
# Der Worker laeuft bereits im neuen Image -- er wurde oben mit erneuert.
# queue:restart trotzdem, damit ein Auftrag aus der alten Fassung, der
# gerade noch lief, nicht mit neuem Code weiterarbeitet.
docker compose exec -T app php artisan queue:restart || true

say "Fertig: $CURRENT_IMAGE -> $TARGET_IMAGE"
echo
echo "    Sicherungen liegen im storage-Volume unter app/backups."
echo "    Zurück geht es mit:"
echo "        $0 ${CURRENT_IMAGE##*:}"
echo "    und dem Einspielen der Sicherung von vorhin -- eine Migration"
echo "    nimmt sich nicht von selbst zurück."
