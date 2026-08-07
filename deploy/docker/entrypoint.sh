#!/bin/sh
set -eu

# ──────────────────────────────────────────────────────────────────────────────
# WARUM public/ BEI JEDEM START KOPIERT WIRD.
#
# nginx liefert die statischen Dateien selbst aus, ohne PHP zu fragen -- dafür
# braucht es dieselben Dateien wie die App. Der naheliegende Weg, ein benanntes
# Volume auf public/ zu legen, hat eine Falle: Docker füllt ein solches Volume
# NUR BEIM ERSTEN MAL aus dem Image. Nach einem Update läge dort weiter das alte
# JavaScript, während PHP die neue Anwendung ausführt -- eine Oberfläche, die zu
# ihrem eigenen Programm nicht passt, ohne dass irgendwo ein Fehler steht.
#
# Deshalb liegt public/ im Image und wird beim Start in das geteilte Volume
# gespiegelt. --delete, damit eine Datei, die es im Release nicht mehr gibt,
# auch verschwindet.
# ──────────────────────────────────────────────────────────────────────────────
if [ -d /srv/public-export ]; then
    rm -rf /srv/public-export/.tmp-sync
    cp -a /var/www/aeronance/public/. /srv/public-export/.tmp-sync 2>/dev/null || {
        mkdir -p /srv/public-export/.tmp-sync
        cp -a /var/www/aeronance/public/. /srv/public-export/.tmp-sync
    }

    find /srv/public-export -mindepth 1 -maxdepth 1 ! -name '.tmp-sync' -exec rm -rf {} +
    cp -a /srv/public-export/.tmp-sync/. /srv/public-export/
    rm -rf /srv/public-export/.tmp-sync
fi

exec "$@"
