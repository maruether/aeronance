#!/usr/bin/env bash
# shellcheck disable=SC1090  # build.func kommt zur Laufzeit vom Community-Scripts-Repo
source <(curl -fsSL https://raw.githubusercontent.com/community-scripts/ProxmoxVE/main/misc/build.func)
# Copyright (c) 2026 Marvin Rüther
# Author: Marvin Rüther
# License: AGPL-3.0
# Source: https://github.com/maruether/aeronance
#
# ──────────────────────────────────────────────────────────────────────────────
# Aeronance -- Werkstatt- und Lagerverwaltung fuer Luftsportvereine.
#
# Dieses Skript laeuft auf dem PROXMOX-HOST und legt den Container an. Was DARIN
# passiert, steht in aeronance-install.sh -- so trennen es die
# Community-Scripts, und daran haelt sich das hier, damit eine Einreichung dort
# spaeter keine Umschreibung ist.
#
# DEBIAN 13 UND NICHT 12, und das ist keine Vorliebe: Aeronance verlangt PHP
# 8.4. Debian 12 liefert 8.2, dafuer braeuchte es ein Fremd-Repository
# (sury.org) -- eine zusaetzliche Vertrauensbeziehung und eine zusaetzliche
# Quelle, die bei jedem Update mitgepflegt werden muss. Debian 13 bringt PHP
# 8.4 und MariaDB selbst mit.
# ──────────────────────────────────────────────────────────────────────────────

APP="Aeronance"
var_tags="${var_tags:-aviation;maintenance}"
var_cpu="${var_cpu:-2}"

# 2 GB: PHP-FPM, MariaDB und ein Queue-Worker nebeneinander. Ein Verein mit
# zwanzig Flugzeugen kommt damit aus; der Speicher geht in die Datenbank, nicht
# in die Anwendung.
var_ram="${var_ram:-2048}"

# 8 GB reichen fuer das System und die Anwendung. Die Dokumente -- Form 1, CRS,
# Waegeberichte, Fotos -- wachsen mit dem Verein; wer viel scannt, gibt hier
# mehr an oder haengt spaeter ein Volume ein.
var_disk="${var_disk:-8}"

var_os="${var_os:-debian}"
var_version="${var_version:-13}"

# Unprivilegiert. Es gibt keinen Grund fuer mehr: Aeronance braucht weder
# Geraetezugriff noch Kernel-Module.
var_unprivileged="${var_unprivileged:-1}"

header_info "$APP"
variables
color
catch_errors

function update_script() {
  header_info
  check_container_storage
  check_container_resources

  if [[ ! -d /var/www/aeronance ]]; then
    msg_error "Keine Aeronance-Installation gefunden."
    exit
  fi

  # ────────────────────────────────────────────────────────────────────────────
  # AKTUALISIERT WIRD MIT DEM MITGELIEFERTEN SKRIPT, nicht mit einer zweiten
  # Umsetzung an dieser Stelle.
  #
  # deploy/update.sh macht das Richtige und in der richtigen Reihenfolge:
  # Sicherung (und bricht ab, wenn sie fehlschlaegt), Wartungsmodus,
  # Migrationen, Caches neu bauen. Das hier noch einmal nachzubauen hiesse, zwei
  # Wege zu pflegen, von denen einer irgendwann falsch ist -- und der falsche
  # waere der, der beim Verein laeuft.
  # ────────────────────────────────────────────────────────────────────────────
  msg_info "Aktualisiere $APP"
  $STD bash -c "cd /var/www/aeronance && ./deploy/update.sh"
  msg_ok "$APP aktualisiert"
  exit
}

start
build_container
description

msg_ok "Fertig."
echo -e "\n${APP} laeuft jetzt hier:"
echo -e "${TAB}${GATEWAY}${BGN}http://${IP}${CL}\n"
echo -e "Beim ersten Aufruf uebernimmt der Einrichtungsassistent: Vereinsname,"
echo -e "Administratorkonto und die Auswahl der Module. Der Datenbankzugang ist"
echo -e "bereits eingetragen und wird uebersprungen."
