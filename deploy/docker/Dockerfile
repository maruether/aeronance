# Aeronance als Container -- gebaut AUS dem Release-Tarball, nicht aus dem Repo.
#
# ──────────────────────────────────────────────────────────────────────────────
# CLAUDE.md: "Ein getaggtes Release ist die einzige Quelle; alle Kanäle
# konsumieren dasselbe Artefakt." Das ist hier wörtlich umgesetzt: dieses Image
# entsteht aus derselben .tar.gz, die auch der Webserver-Kanal ausliefert, und
# nicht aus einem zweiten Build. Wären es zwei Builds, könnten sie auseinander-
# laufen -- und die Abweichung fiele frühestens einem Verein auf.
#
# Deshalb wird hier NICHT composer install ausgeführt und NICHT npm run build:
# beides ist im Tarball schon drin, geprüft gegen Soll- und Verbotsliste.
#
# PHP-FPM UND SONST NICHTS. nginx, MariaDB, Worker und Scheduler sind eigene
# Dienste in der compose-Datei. Ein Container, der alles zugleich ist, lässt sich
# weder einzeln neu starten noch einzeln skalieren -- und ein Absturz nimmt alles
# mit.
# ──────────────────────────────────────────────────────────────────────────────
ARG PHP_VERSION=8.4

FROM php:${PHP_VERSION}-fpm-bookworm

# poppler-utils liefert pdftotext: die Kennblatt- und LTA-Listen der Hersteller
# richten ihre Spalten per Leerzeichen aus, und ohne -layout sind sie unlesbar.
# Systempaket, kein Composer-Paket -- siehe PdfLayoutText.
#
# mariadb-client liefert mariadb-dump: ohne ihn bricht `aeronance:backup` ab --
# und damit die nächtliche Sicherung des Scheduler-Containers UND der
# Sicherungs-Schritt in deploy/docker/update.sh. Ein Image ohne dieses Paket
# kann weder sichern noch aktualisiert werden. Läuft im install-Block und
# NICHT in der purge-Zeile darunter: die räumt nur Build-Abhängigkeiten ab.
#
# Die Extensions sind dieselben, die die CI installiert. Weicht das ab, läuft
# getestet etwas anderes als ausgeliefert.
RUN apt-get update \
 && apt-get install -yqq --no-install-recommends \
      libzip-dev libicu-dev libpng-dev libjpeg62-turbo-dev libfreetype6-dev \
      poppler-utils mariadb-client \
 && docker-php-ext-configure gd --with-freetype --with-jpeg \
 && docker-php-ext-install -j"$(nproc)" pdo_mysql zip intl bcmath exif gd opcache \
 && apt-get purge -yqq libzip-dev libicu-dev libpng-dev libjpeg62-turbo-dev libfreetype6-dev \
 && apt-get autoremove -yqq \
 && rm -rf /var/lib/apt/lists/*

# Opcache: ein Container wird nicht editiert, also darf der Cache die Dateien
# nicht ständig neu prüfen.
RUN { \
      echo 'opcache.enable=1'; \
      echo 'opcache.validate_timestamps=0'; \
      echo 'opcache.memory_consumption=192'; \
      echo 'opcache.max_accelerated_files=20000'; \
    } > /usr/local/etc/php/conf.d/opcache.ini \
 && { \
      echo 'memory_limit=512M'; \
      echo 'upload_max_filesize=32M'; \
      echo 'post_max_size=32M'; \
    } > /usr/local/etc/php/conf.d/aeronance.ini

WORKDIR /var/www/aeronance

# Das Release, entpackt. Der Build-Kontext ist das dist/-Verzeichnis des
# pack-Jobs; ADD entpackt .tar.gz von selbst.
ARG RELEASE
ADD ${RELEASE} /tmp/release/

# cp -a und NICHT mv: /var/www/aeronance ist das Arbeitsverzeichnis, und mv
# nach "." hinein scheitert dort mit "Device or resource busy". Gemessen im
# Kaniko-Lauf, nicht vermutet.
RUN cp -a /tmp/release/*/. /var/www/aeronance/ \
 && rm -rf /tmp/release \
 && chown -R www-data:www-data storage bootstrap/cache

COPY --chmod=0755 entrypoint.sh /usr/local/bin/aeronance-entrypoint

# Das Ziel der public/-Spiegelung, siehe entrypoint.sh. Angelegt und
# uebereignet, solange wir noch root sind.
RUN mkdir -p /srv/public-export && chown www-data:www-data /srv/public-export

# NICHT als root. Das ist der Unterschied zwischen einem ausgebrochenen Prozess
# im Container und einem ausgebrochenen Prozess mit allen Rechten.
USER www-data

EXPOSE 9000

ENTRYPOINT ["aeronance-entrypoint"]
CMD ["php-fpm"]
