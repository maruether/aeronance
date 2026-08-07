#!/usr/bin/env bash
# Copyright (c) 2026 Marvin Rüther
# Author: Marvin Rüther
# License: AGPL-3.0
# Source: https://github.com/maruether/aeronance
#
# ──────────────────────────────────────────────────────────────────────────────
# Laeuft IM Container. Legt den Stack an, holt das Release und uebergibt an den
# Einrichtungsassistenten.
#
# WAS ES NICHT TUT: die Einrichtung selbst. Vereinsname, Administratorkonto und
# Modulauswahl macht der Assistent im Browser -- er kennt die Abhaengigkeiten
# zwischen den Modulen und erklaert sie. Ein Skript, das das nachbaut, waere ein
# zweiter Weg, der irgendwann anders entscheidet.
#
# Der Datenbankzugang wird dagegen HIER geschrieben. CLAUDE.md sieht das so vor:
# "Der Assistent erkennt per Env vorkonfigurierte Werte und ueberspringt die
# betreffenden Schritte." Ein Verein soll kein Passwort erfinden muessen, das
# das Skript ohnehin schon kennt.
# ──────────────────────────────────────────────────────────────────────────────

# shellcheck disable=SC1090,SC1091  # Die Hilfsfunktionen reicht build.func zur Laufzeit herein
source /dev/stdin <<<"$FUNCTIONS_FILE_PATH"
color
verb_ip6
catch_errors
setting_up_container
network_check
update_os

msg_info "Installiere Abhaengigkeiten"
$STD apt-get install -y \
  curl ca-certificates gnupg unzip git \
  nginx \
  mariadb-server \
  php8.4-fpm php8.4-mysql php8.4-intl php8.4-gd php8.4-zip php8.4-bcmath \
  php8.4-mbstring php8.4-xml php8.4-curl \
  poppler-utils
msg_ok "Abhaengigkeiten installiert"

# ──────────────────────────────────────────────────────────────────────────────
# poppler-utils ist KEIN Beiwerk. Ohne pdftotext bleiben die Kennblatt-Listen
# des LBA und die Uebersichtsblaetter der Hersteller ungelesen -- und zwar
# lautlos: Die Anwendung meldet dann "kein Eintrag gefunden", was aussieht wie
# "der Hersteller hat nichts veroeffentlicht". Deshalb steht es oben und nicht
# als Nachgedanke.
# ──────────────────────────────────────────────────────────────────────────────

msg_info "Richte die Datenbank ein"
DB_NAME=aeronance
DB_USER=aeronance
DB_PASS="$(openssl rand -base64 24 | tr -d '/+=' | head -c 24)"

$STD mariadb -u root -e "CREATE DATABASE IF NOT EXISTS \`${DB_NAME}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
$STD mariadb -u root -e "CREATE USER IF NOT EXISTS '${DB_USER}'@'localhost' IDENTIFIED BY '${DB_PASS}';"
$STD mariadb -u root -e "GRANT ALL PRIVILEGES ON \`${DB_NAME}\`.* TO '${DB_USER}'@'localhost';"
$STD mariadb -u root -e "FLUSH PRIVILEGES;"
msg_ok "Datenbank eingerichtet"

msg_info "Hole das Release"
# ──────────────────────────────────────────────────────────────────────────────
# EIN FERTIGES TARBALL, kein Bauen auf dem Zielsystem. Es bringt vendor/ und die
# uebersetzten Assets mit -- auf dem Container laeuft deshalb weder Composer
# noch Node. Genau dafuer gibt es den pack-Job in der CI.
#
# Die Adresse ist ueberschreibbar, damit ein Verein aus einem eigenen Spiegel
# installieren kann. Schlaegt der Abruf fehl, bricht das Skript ab: eine halb
# entpackte Installation, die trotzdem startet, ist der schlechteste Zustand.
# ──────────────────────────────────────────────────────────────────────────────
RELEASE_URL="${AERONANCE_RELEASE_URL:-}"

if [[ -z "$RELEASE_URL" ]]; then
  msg_error "AERONANCE_RELEASE_URL ist nicht gesetzt. Bitte die Adresse des Release-Tarballs angeben."
  exit 1
fi

mkdir -p /var/www
curl -fsSL "$RELEASE_URL" -o /tmp/aeronance.tar.gz || {
  msg_error "Das Release liess sich nicht von ${RELEASE_URL} holen."
  exit 1
}

# Pruefsumme, wenn eine danebenliegt. Ein beschaedigter Download faellt sonst
# erst beim ersten Seitenaufruf auf.
if curl -fsSL "${RELEASE_URL}.sha256" -o /tmp/aeronance.tar.gz.sha256 2>/dev/null; then
  (cd /tmp && sha256sum -c --status --ignore-missing aeronance.tar.gz.sha256) || {
    msg_error "Die Pruefsumme des Downloads stimmt nicht."
    exit 1
  }
fi

tar -xzf /tmp/aeronance.tar.gz -C /tmp
mv /tmp/aeronance-*/ /var/www/aeronance
rm -f /tmp/aeronance.tar.gz /tmp/aeronance.tar.gz.sha256
msg_ok "Release entpackt"

msg_info "Konfiguriere Aeronance"
cd /var/www/aeronance || exit 1

cp .env.example .env

# ─────────────────────────────────────────────────────────────────────────────
# SESSION_SECURE_COOKIE MUSS HIER AUF false -- sonst kommt niemand hinein.
#
# .env.example setzt es auf true, und das ist fuer den Regelbetrieb richtig.
# Dieser Container startet aber ueber http: Er hat noch kein Zertifikat, und
# der vhost weiter unten laesst TLS bewusst weg. Ein Secure-Cookie schickt der
# Browser ueber http NICHT zurueck -- man gibt die richtigen Zugangsdaten ein
# und landet wieder auf der Anmeldemaske. Ohne Fehlermeldung, ohne Logeintrag.
#
# Wer spaeter TLS einrichtet, dreht beides um: APP_URL auf https und diese
# Zeile auf true.
# ─────────────────────────────────────────────────────────────────────────────
sed -i \
  -e "s|^APP_ENV=.*|APP_ENV=production|" \
  -e "s|^APP_DEBUG=.*|APP_DEBUG=false|" \
  -e "s|^APP_URL=.*|APP_URL=http://$(hostname -I | awk '{print $1}')|" \
  -e "s|^SESSION_SECURE_COOKIE=.*|SESSION_SECURE_COOKIE=false|" \
  -e "s|^DB_CONNECTION=.*|DB_CONNECTION=mariadb|" \
  -e "s|^DB_HOST=.*|DB_HOST=127.0.0.1|" \
  -e "s|^DB_DATABASE=.*|DB_DATABASE=${DB_NAME}|" \
  -e "s|^DB_USERNAME=.*|DB_USERNAME=${DB_USER}|" \
  -e "s|^DB_PASSWORD=.*|DB_PASSWORD=${DB_PASS}|" \
  .env

php artisan key:generate --force
chown -R www-data:www-data storage bootstrap/cache
chmod -R 750 storage bootstrap/cache

# Die .env traegt jetzt das Datenbankpasswort und gleich den APP_KEY, mit dem
# die verschluesselten Felder stehen und fallen.
chown www-data:www-data .env
chmod 600 .env
msg_ok "Aeronance konfiguriert"

msg_info "Richte den Webserver ein"
# Der mitgelieferte vhost, nur ohne TLS: Ein frischer Container hat kein
# Zertifikat, und ein nginx, der wegen einer fehlenden Datei nicht startet,
# waere ein schlechter erster Eindruck. Wer HTTPS will, nimmt deploy/nginx.conf.
cat > /etc/nginx/sites-available/aeronance <<'NGINX'
server {
    listen 80 default_server;
    listen [::]:80 default_server;
    server_name _;

    # NUR public/. Eine Ebene hoeher waeren .env, storage/ und der gesamte
    # Anwendungscode ueber HTTP lesbar -- der eine Fehler, der aus einer
    # sauberen Installation eine offene macht.
    root /var/www/aeronance/public;
    index index.php;

    client_max_body_size 32m;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ ^/index\.php(/|$) {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:/run/php/php8.4-fpm.sock;
        fastcgi_hide_header X-Powered-By;
        fastcgi_read_timeout 300;
    }

    # Kein zweiter Einstieg: ohne das kann eine hochgeladene Datei mit .php im
    # Namen ausgefuehrt werden, wenn sie je unter public/ landet.
    location ~ \.php$ {
        return 404;
    }

    location ~ /\.(?!well-known) {
        deny all;
    }
}
NGINX

rm -f /etc/nginx/sites-enabled/default
ln -sf /etc/nginx/sites-available/aeronance /etc/nginx/sites-enabled/aeronance
$STD nginx -t
systemctl reload nginx
msg_ok "Webserver eingerichtet"

msg_info "Richte Worker und Zeitplan ein"
# Die mitgelieferten Units. Langlaufende Prozesse duerfen hier vorausgesetzt
# werden -- CLAUDE.md: Zielbild ist ein eigener Server oder Container, kein
# Shared Webspace.
install -m 644 deploy/aeronance-worker.service /etc/systemd/system/
install -m 644 deploy/aeronance-scheduler.service /etc/systemd/system/

# Der Update-Timer wird MITINSTALLIERT, aber nicht scharf geschaltet: Das
# Skript dahinter prueft AERONANCE_AUTO_UPDATE in der .env und endet still,
# solange dort nichts steht. So muss niemand spaeter Dateien nachkopieren, um
# die Automatik einzuschalten -- eine Zeile in der .env genuegt.
install -m 644 deploy/aeronance-update.service /etc/systemd/system/
install -m 644 deploy/aeronance-update.timer /etc/systemd/system/
$STD systemctl daemon-reload
$STD systemctl enable --now aeronance-worker.service
$STD systemctl enable --now aeronance-scheduler.service
$STD systemctl enable --now aeronance-update.timer
msg_ok "Worker und Zeitplan laufen"

msg_info "Pruefe die Voraussetzungen"
# Das eigene Pruefprogramm, statt hier eine zweite Liste zu fuehren. Es kennt
# die Mindestversionen und die noetigen Erweiterungen -- und meldet, was fehlt,
# bevor der erste Nutzer darueber stolpert.
$STD php artisan aeronance:requirements
msg_ok "Voraussetzungen erfuellt"

motd_ssh
customize

msg_info "Raeume auf"
$STD apt-get -y autoremove
$STD apt-get -y autoclean
msg_ok "Aufgeraeumt"
