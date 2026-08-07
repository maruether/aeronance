#!/bin/sh
# Was im fertigen Image schiefgegangen sein kann, ohne dass es auffällt.
#
# ──────────────────────────────────────────────────────────────────────────────
# Ein Image, das baut, ist noch kein Image, das läuft. Geprüft wird deshalb
# genau das, was still fehlschlägt:
#
#   - eine fehlende PHP-Extension meldet sich erst beim ersten Aufruf der Seite
#   - fehlendes pdftotext macht alle vierzehn Übersichtsblätter unlesbar, und
#     zwar als "der Hersteller hat nichts veröffentlicht"
#   - ein Tarball, der falsch entpackt wurde, hinterlässt ein Image ohne
#     public/index.php -- der Webserver liefert dann 404 statt der Anwendung
#
# Als Datei und nicht als Zeile in der .gitlab-ci.yml, weil das dort drei
# Ebenen Zitierung bedeutet hätte (YAML, sh, PHP) -- und ein Prüfschritt, der
# an einem Anführungszeichen scheitert, prüft am Ende nichts.
# ──────────────────────────────────────────────────────────────────────────────
set -eu

php -v | head -n1

for ext in pdo_mysql zip intl bcmath exif gd opcache; do
    php -r "exit(extension_loaded('$ext') ? 0 : 1);" || {
        echo "FEHLT: PHP-Extension $ext"
        exit 1
    }
done

command -v pdftotext >/dev/null || {
    echo "FEHLT: pdftotext -- die Uebersichtsblaetter waeren unlesbar"
    exit 1
}

test -f /var/www/aeronance/public/index.php || {
    echo "FEHLT: public/index.php -- das Release wurde nicht richtig entpackt"
    exit 1
}

test -f /var/www/aeronance/VERSION || {
    echo "FEHLT: VERSION -- die Installation koennte nicht sagen, was sie ist"
    exit 1
}

# Startet die Anwendung ueberhaupt? Das faengt einen fehlenden vendor/-Ordner
# und eine kaputte Autoload-Karte.
php /var/www/aeronance/artisan --version

echo "Image geprueft: $(cat /var/www/aeronance/VERSION)"
