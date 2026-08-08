#!/usr/bin/env bash
#
# Eine Fassung veröffentlichen -- der bewusste Schritt nach GitHub.
#
#   deploy/publish.sh v1.2.0
#   deploy/publish.sh v1.2.0 --dry-run
#   deploy/publish.sh master  --neu      # verwirft die oeffentliche Historie
#
# ──────────────────────────────────────────────────────────────────────────────
# WARUM VERÖFFENTLICHEN EIN EIGENER SCHRITT IST UND KEINE SPIEGELUNG.
#
# Die Aktualisierungsprüfung der Anwendung liest die Tags des öffentlichen
# Repositorys. Wäre die Spiegelung automatisch, wäre jeder Tag im selben
# Augenblick ein Update für jede laufende Installation -- auch der, mit dem man
# nur schnell etwas ausprobieren wollte.
#
# Getrennt heißt: intern getaggt und gebaut, geprüft, und ERST DANN
# veröffentlicht. Zwischen "es ist fertig" und "alle bekommen es" liegt eine
# Entscheidung.
#
# ──────────────────────────────────────────────────────────────────────────────
# DIE ÖFFENTLICHE HISTORIE IST EINE RELEASE-HISTORIE.
#
# Je Veröffentlichung ein Commit, mit dem Baum genau dieser Fassung. Nicht die
# interne Entwicklungshistorie: Die besteht aus Notizen, die im Arbeitsablauf
# entstanden sind, und erklärt Außenstehenden nichts. Was erklärt, steht im Baum
# -- die Begründung jeder Entscheidung am Ort ihrer Wirkung, das
# Entscheidungsprotokoll in docs/, der CHANGELOG.
#
# Gebaut mit `git commit-tree`, nicht mit checkout und commit: So wird der
# Arbeitsbaum nicht angefasst. Wer das Skript mitten in einer anderen Arbeit
# aufruft, verliert nichts.
#
# ──────────────────────────────────────────────────────────────────────────────
# DER TAG WIRD LEICHTGEWICHTIG GESETZT, und das ist eine bewusste Abweichung.
#
# Intern trägt jeder Tag eine Signatur. Öffentlich zeigt derselbe Name auf einen
# ANDEREN Commit -- gleiche Fassung, andere Historie --, und zwei signierte
# Tag-Objekte gleichen Namens nebeneinander im selben Arbeitsverzeichnis sind
# eine Fehlerquelle ohne Gegenwert. Der Commit, auf den der öffentliche Tag
# zeigt, IST signiert; das trägt die Nachprüfbarkeit.
# ──────────────────────────────────────────────────────────────────────────────

set -euo pipefail

cd "$(dirname "$0")/.."

TAG="${1:-}"
MODUS="${2:-}"
FERNZIEL="${AERONANCE_PUBLIC_REMOTE:-github}"

say() { printf '\n\033[1m==> %s\033[0m\n' "$*"; }
die() { printf '\n\033[31mAbbruch: %s\033[0m\n' "$*" >&2; exit 1; }

[ -f artisan ] || die "hier steht kein Aeronance."
[ -n "$TAG" ] || die "welche Fassung? Beispiel:  $0 v1.2.0"

git rev-parse -q --verify "${TAG}^{commit}" >/dev/null \
    || die "\"$TAG\" gibt es hier nicht."

git remote get-url "$FERNZIEL" >/dev/null 2>&1 \
    || die "die Gegenstelle \"$FERNZIEL\" ist nicht eingerichtet (AERONANCE_PUBLIC_REMOTE)."

# ──────────────────────────────────────────────────────────────────────────────
# VEROEFFENTLICHT WIRD NUR GEPRUEFTES -- egal ob Tag oder Zweig.
#
# Bei einem Tag traegt das Tag-Objekt die Signatur, bei einem Zweig der Commit,
# auf den er zeigt. Beides ist eine pruefbare Aussage darueber, wer diesen Stand
# freigegeben hat; keine davon ist "irgendein Stand, der gerade herumlag".
# ──────────────────────────────────────────────────────────────────────────────
IST_TAG=0
if git rev-parse -q --verify "refs/tags/$TAG" >/dev/null; then
    IST_TAG=1
    git tag -v "$TAG" >/dev/null 2>&1 \
        || die "der Tag \"$TAG\" traegt keine pruefbare Signatur."
    echo "    Signatur des Tags geprüft"
else
    git verify-commit "${TAG}^{commit}" >/dev/null 2>&1 \
        || die "der Commit hinter \"$TAG\" traegt keine pruefbare Signatur."
    echo "    Signatur des Commits geprüft"
fi

say "Stand ermitteln"
BAUM="$(git rev-parse "${TAG}^{tree}")"

# Der bisherige oeffentliche Stand, falls es einen gibt. Beim ersten Mal nicht --
# dann entsteht ein Wurzel-Commit.
git fetch --quiet "$FERNZIEL" master 2>/dev/null || true
ELTERN="$(git rev-parse -q --verify "refs/remotes/${FERNZIEL}/master" || true)"

# ──────────────────────────────────────────────────────────────────────────────
# --neu VERWIRFT DIE OEFFENTLICHE HISTORIE und faengt mit einem Wurzel-Commit an.
#
# Gedacht fuer genau einen Fall: wenn dort etwas steht, das dort nicht stehen
# soll -- eine versehentlich gespiegelte Entwicklungshistorie etwa. Im
# Normalbetrieb ist es falsch: Dann ist jede Veroeffentlichung ein Nachfolger
# der vorigen, und Nutzer koennen sehen, was sich zwischen zwei Fassungen
# geaendert hat.
#
# Deshalb ausgeschrieben und nicht als Vorgabe. Wer es tippt, meint es.
# ──────────────────────────────────────────────────────────────────────────────
if [ "$MODUS" = "--neu" ] && [ -n "$ELTERN" ]; then
    say "Achtung: --neu -- die bisherige oeffentliche Historie wird verworfen"
    echo "    bisher: $(git rev-parse --short "$ELTERN")"
    ELTERN=""
fi

if [ -n "$ELTERN" ]; then
    VORHER="$(git rev-parse --short "$ELTERN")"
    echo "    bisher oeffentlich: $VORHER"

    if [ "$(git rev-parse "${ELTERN}^{tree}")" = "$BAUM" ]; then
        say "Der oeffentliche Stand ist bereits genau dieser Baum. Nichts zu tun."
        exit 0
    fi
else
    echo "    noch nichts veroeffentlicht -- es entsteht ein Wurzel-Commit"
fi

# ── Die Nachricht ─────────────────────────────────────────────────────────────
# Aus dem CHANGELOG, denn dort steht bereits, was diese Fassung ausmacht -- und
# eine zweite, von Hand gepflegte Fassung derselben Auskunft weicht irgendwann ab.
NACHRICHT="$(mktemp)"
trap 'rm -f "$NACHRICHT"' EXIT

if [ "$IST_TAG" = "1" ]; then
    {
        printf '%s\n\n' "$TAG"
        awk -v tag="${TAG#v}" '
            $0 ~ "^## \\[" tag "\\]" { drin = 1; next }
            drin && /^## \[/ { exit }
            drin { print }
        ' CHANGELOG.md | sed '/^$/{ N; /^\n$/D; }'
        printf '\nVollstaendiger Changelog: CHANGELOG.md\n'
    } > "$NACHRICHT"
else
    # Kein Tag, also keine Fassung -- und damit auch keine Changelog-Passage,
    # die dazu gehoerte. Ein Zwischenstand sagt genau das und behauptet nichts.
    {
        printf 'Aktueller Stand\n\n'
        printf 'Veroeffentlicht ohne Fassungsnummer. Was diese Fassung ausmacht,\n'
        printf 'steht im CHANGELOG unter [Unveroeffentlicht].\n'
    } > "$NACHRICHT"
fi

say "Commit bauen"
COMMIT="$(git commit-tree "$BAUM" ${ELTERN:+-p "$ELTERN"} -S -F "$NACHRICHT")"
echo "    $(git rev-parse --short "$COMMIT")"

if [ "$MODUS" = "--dry-run" ]; then
    say "Probelauf -- es wird nichts geschoben."
    echo
    git show --stat --no-patch "$COMMIT"
    exit 0
fi

say "Nach $FERNZIEL schieben"
# --force, weil die oeffentliche Historie beim ERSTEN Mal die gespiegelte
# ersetzt. Danach ist jeder Lauf ein regulaerer Nachfolger und braucht es nicht
# mehr -- es schadet aber auch nicht, und ein Sonderfall im Skript waere ein
# Zweig, der nur einmal im Leben genommen wird.
git push --force "$FERNZIEL" "${COMMIT}:refs/heads/master"

if [ "$IST_TAG" = "1" ]; then
    say "Tag setzen"
    git push --force "$FERNZIEL" "${COMMIT}:refs/tags/${TAG}"
else
    # KEIN Tag ohne Fassungsnummer. Ein Tag namens "master" waere Unsinn, und
    # die Aktualisierungspruefung wuerde ihn als Fassung anbieten.
    say "Kein Tag -- veroeffentlicht wurde ein Zwischenstand"
fi

# ──────────────────────────────────────────────────────────────────────────────
# DAS RELEASE-ARTEFAKT GEHOERT ZUR VEROEFFENTLICHUNG DAZU.
#
# Tarball-Installationen (Webserver-Pack, LXC) aktualisieren sich aus den
# GitHub-Releases: deploy/update.sh laedt dort aeronance-<tag>.tar.gz nebst
# .asc und prueft die Signatur gegen den veroeffentlichten Schluesselbund.
# Ein Tag OHNE Artefakt heisst fuer diese Installationen: es gibt nichts zu
# holen -- der Kanal bleibt auf Handarbeit angewiesen, ohne dass es jemand
# merkt.
#
# Der Tarball kommt aus dem pack-Job der CI (dist/…tar.gz) und wird HIER
# signiert, nicht dort: Der Signaturschluessel bleibt auf diesem Rechner, die
# CI hat ihn nie gesehen. Dieselbe Arbeitsteilung wie bei Commits und Tags.
# ──────────────────────────────────────────────────────────────────────────────
if [ "$IST_TAG" = "1" ]; then
    ARTEFAKT="${AERONANCE_ARTEFAKT:-dist/aeronance-${TAG}.tar.gz}"

    if [ ! -f "$ARTEFAKT" ]; then
        say "Kein Release-Artefakt gefunden ($ARTEFAKT)"
        echo "    Der Tarball-Kanal (Webserver-Pack, LXC) kann dieses Release so nicht laden."
        echo "    So kommt es nach: pack-Artefakt der CI zu diesem Tag nach dist/ legen"
        echo "    und dieses Skript erneut ausfuehren -- oder AERONANCE_ARTEFAKT=<pfad> setzen."
    else
        say "Release-Artefakt signieren"
        if [ ! -f "${ARTEFAKT}.sha256" ]; then
            (cd "$(dirname "$ARTEFAKT")" && sha256sum "$(basename "$ARTEFAKT")" > "$(basename "$ARTEFAKT").sha256")
        fi
        # --yes: Ein erneuter Lauf desselben Releases ueberschreibt seine eigene
        # Signatur, statt an einer Rueckfrage zu haengen.
        gpg --batch --yes --armor --detach-sign -o "${ARTEFAKT}.asc" "$ARTEFAKT" \
            || die "das Artefakt liess sich nicht signieren."
        echo "    $(basename "$ARTEFAKT").asc"

        say "Release-Artefakt nach $FERNZIEL laden"
        REPO_SLUG="$(git remote get-url "$FERNZIEL" | sed -E 's#^.*github\.com[:/]##; s#\.git$##')"

        if command -v gh >/dev/null 2>&1; then
            if gh release view "$TAG" --repo "$REPO_SLUG" >/dev/null 2>&1; then
                gh release upload "$TAG" --repo "$REPO_SLUG" --clobber \
                    "$ARTEFAKT" "${ARTEFAKT}.asc" "${ARTEFAKT}.sha256" \
                    || die "der Upload der Release-Assets ist fehlgeschlagen."
            else
                gh release create "$TAG" --repo "$REPO_SLUG" \
                    --title "$TAG" --notes-file "$NACHRICHT" \
                    "$ARTEFAKT" "${ARTEFAKT}.asc" "${ARTEFAKT}.sha256" \
                    || die "das GitHub-Release liess sich nicht anlegen."
            fi
            echo "    https://github.com/${REPO_SLUG}/releases/tag/${TAG}"
        else
            echo "    gh (GitHub CLI) ist nicht installiert -- der Upload bleibt Handarbeit:"
            echo "    Release zum Tag ${TAG} anlegen und diese drei Dateien anhaengen:"
            echo "        $ARTEFAKT"
            echo "        ${ARTEFAKT}.asc"
            echo "        ${ARTEFAKT}.sha256"
        fi
    fi
fi

say "Veroeffentlicht: $TAG"
echo
if [ "$IST_TAG" = "1" ]; then
    echo "    Ab jetzt meldet aeronance:update-check diese Fassung."
    echo "    Wer sie NICHT bekommen soll, war vorher dran."
else
    echo "    Ohne Tag meldet aeronance:update-check nichts -- gewollt."
fi
