# Sicherheitslücken melden

**Kontakt: [security@aeronance.de](mailto:security@aeronance.de)**

Bitte melde Sicherheitslücken **nicht** über einen öffentlichen Issue. Ein
offener Issue ist eine Anleitung für jeden, der ihn vor dem Fix liest — und
Aeronance läuft in Vereinen, deren Werkstattdaten an Lufttüchtigkeit hängen.

Schreib stattdessen an die Adresse oben. Sie ist eine eigene Adresse und
ausdrücklich nicht `issues@`: Dort laufen die automatischen Benachrichtigungen
der Code-Verwaltung auf, und eine Meldung, die zwischen dreißig davon liegt,
wird übersehen. Genau so gehen Meldungen verloren — nicht durch Böswilligkeit,
sondern durch ein volles Postfach.

Du bekommst innerhalb weniger Tage eine Antwort; wenn nicht, hak nach — dann
ist die Mail untergegangen, nicht ignoriert worden.

Wenn du verschlüsselt schreiben willst, frag in einer ersten Mail ohne
Einzelheiten nach einem Schlüssel. Wir hinterlegen hier bewusst keinen, den
niemand pflegt: Ein veralteter Schlüssel im Repository ist schlimmer als
keiner, weil er so aussieht, als könnte man ihn benutzen.

## Was in eine Meldung gehört

Je mehr davon, desto schneller die Antwort:

- Was passiert, und was stattdessen passieren sollte
- Wie man es auslöst — am liebsten Schritt für Schritt
- Welche Fassung (`VERSION`-Datei im Installationsverzeichnis, oder
  `php artisan aeronance:update-check`)
- Wie installiert: eigener Server, Docker oder LXC
- Was du für die Auswirkung hältst, und warum

Roh und unvollständig ist besser als gar nicht. Eine Vermutung mit einem
halben Reproduktionsweg ist mehr wert als eine Lücke, die niemand meldet.

## Was danach passiert

1. **Eingangsbestätigung**, sobald jemand die Mail gelesen hat.
2. **Einschätzung** — bestätigt oder nicht, und warum.
3. **Fix**, dann eine Veröffentlichung. Bei ernsten Sachen außer der Reihe.
4. **Nennung**, wenn du möchtest: Wer eine Lücke findet und meldet, gehört in
   den Changelog. Wer lieber ungenannt bleibt, bleibt ungenannt.

Wir bitten um eine angemessene Frist zwischen Meldung und Veröffentlichung —
lang genug für einen Fix, kurz genug, dass niemand darauf sitzenbleibt. Feste
Tageszahlen versprechen wir nicht: Das Projekt wird ehrenamtlich gepflegt, und
eine Zusage, die im Urlaub bricht, ist keine.

## Welche Fassungen gepflegt werden

Aeronance steht bei **0.0.x** und ist damit ausdrücklich **Vorabstand**.
Gepflegt wird die jeweils neueste Veröffentlichung; ältere bekommen keine
Rückportierungen. Wer produktiv damit arbeitet, sollte aktuell bleiben — dafür
gibt es die Aktualisierungsprüfung.

## Was in den Anwendungsbereich fällt

Alles im Repository: die Anwendung, die Auslieferungsskripte unter `deploy/`,
die Beispielkonfigurationen. **Nicht** dazu gehören Fehler in Abhängigkeiten
(die gehören zu deren Herausgeber, wir ziehen die Aktualisierung nach) und
Fehlkonfigurationen einer einzelnen Installation.

Besonders interessieren uns Meldungen zu:

- **Rechteprüfung** — ein Bildschirm, der jemandem antwortet, der ihn nicht
  sehen dürfte. Die Anwendung prüft nach dem Grundsatz „alles verboten, was
  nicht ausdrücklich erlaubt ist", und `DenyByDefaultTest` hält das nach; wenn
  du eine Lücke findest, ist entweder der Test blind oder eine Annahme falsch.
- **Fremde Daten** — Zugriff auf Datensätze über manipulierte Kennungen.
- **Dateiablage** — Form 1, Freigaben und Fotos liegen außerhalb des Webroots
  und werden nur über geprüfte Wege ausgeliefert. Ein Weg daran vorbei ist eine
  Lücke.
- **Unveränderlichkeit** — ein freigegebener Vorgang lässt sich nicht mehr
  ändern, ein Protokolleintrag nicht löschen. Wer das aushebelt, hebelt den
  Nachweis aus.
- **Geheimnisse** — Zugangsdaten zu externen Systemen liegen verschlüsselt.
  Wenn eines davon im Klartext auftaucht, in einem Log oder in einer Sicherung,
  ist das eine Meldung wert.

## Prüfen ja — aber auf deiner eigenen Installation

Aeronance wird selbst gehostet. Jede laufende Instanz gehört einem Verein, und
dieses Projekt kann dir keine Erlaubnis geben, an fremdem Eigentum zu testen —
niemand hier ist ihr Betreiber.

Deshalb, klar getrennt:

**Auf einer Installation, die dir gehört**, darfst du alles ausprobieren, was
dir einfällt. Wer auf diesem Weg eine Lücke findet und sie uns nach dieser
Seite meldet, hat unsere volle Unterstützung, und wir werden daraus keine
rechtliche Sache machen. Zum Ausprobieren reicht das Docker-Paket; du brauchst
dafür keine fremde Instanz.

**Auf einer fremden Installation** hast du hier gar nichts zu suchen, auch nicht
„nur zum Nachsehen". Wer die Instanz eines Vereins anfasst, greift auf
Personendaten seiner Mitglieder und auf Nachweise zu, an denen die
Lufttüchtigkeit von Luftfahrzeugen hängt. Eine Erlaubnis dafür kann nur der
Verein selbst geben.

Und in keinem Fall: keine Denial-of-Service-Versuche, kein Ausleiten fremder
Daten über das hinaus, was den Fund belegt, keine Änderungen an fremden
Datenbeständen. Wenn du beim Prüfen versehentlich an echte Daten kommst, hör
auf, lösche sie und schreib es in die Meldung.

## Was keine Lücke ist

- Ein fehlender Schutz, der nur im Entwicklungsmodus fehlt (`APP_DEBUG=true`).
  Wer so produktiv fährt, hat ein Betriebsproblem, kein Sicherheitsproblem.
- Ergebnisse eines Scanners ohne nachvollziehbare Auswirkung. Eine Liste
  fehlender Header ohne Angriffsweg beantworten wir nicht.
- **Dass alle Benutzer einer Instanz dieselben Daten sehen können**, soweit
  ihre Rolle es zulässt. Eine Instanz ist ein Verein — es gibt keine
  Mandantentrennung im Datenmodell, und das ist eine bewusste Entscheidung und
  kein Versäumnis. Wer mehrere Vereine trennen will, betreibt mehrere
  Instanzen; die Trennung kommt dann aus der Infrastruktur.
- Etwas, das erst nach einem Zugriff auf den Server selbst möglich wird. Wer
  `.env` lesen oder Artisan-Befehle ausführen kann, ist bereits drin; dagegen
  hilft keine Anwendung.

## Was der Betreiber selbst tun muss

Ein Teil der Absicherung liegt nicht im Code, sondern in der Installation, und
darüber sollte sich niemand täuschen: HTTPS erzwingen, `APP_DEBUG=false`,
`SESSION_SECURE_COOKIE=true` hinter TLS, den Datenbankzugang nicht nach außen
öffnen, Sicherungen anlegen **und das Zurückspielen einmal ausprobieren**.

Die Auslieferungspakete unter `deploy/` bringen brauchbare Vorgaben mit;
Einzelheiten zur Konfiguration stehen in
[docs/KONFIGURATION.md](docs/KONFIGURATION.md). Wenn dir dort etwas als
gefährliche Voreinstellung auffällt — also etwas, das eine normale
Installation unsicher macht, ohne dass der Betreiber einen Fehler gemacht hat
—, ist das sehr wohl eine Meldung nach dieser Seite wert.
