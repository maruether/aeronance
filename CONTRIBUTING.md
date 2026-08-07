# Mitmachen

Aeronance ist ein Werkzeug für Luftsportvereine, geschrieben von Leuten, die
selbst in einer Vereinswerkstatt stehen. Beiträge sind willkommen — besonders
von denen, die den Betrieb kennen.

## Bevor du Code schreibst

**Sprich vorher.** Öffne einen Issue und beschreibe, was du vorhast. Nicht aus
Bürokratie, sondern weil dieses Projekt viele Entscheidungen mit Begründung
getroffen hat, die man dem Code nicht auf den ersten Blick ansieht — und es ist
schade um deine Zeit, wenn eine Änderung an einer solchen Entscheidung
scheitert, die in einem Kommentar zehn Zeilen weiter oben steht.

Bei Fragen zur **Fachlichkeit** — Luftfahrtregularien, Vereinsabläufe — frag
lieber einmal zu viel. Geraten wird hier nicht.

## Lizenz: inbound = outbound

Aeronance steht unter der **AGPL-3.0-or-later**. Es gibt **kein CLA**: Du
behältst dein Copyright, und dein Beitrag steht unter derselben Lizenz wie der
Rest. Was du beiträgst, bleibt frei — auch für dich.

Die AGPL ist bewusst gewählt. Die GPL greift nur bei *Verbreitung*; wer die
Software als Netzwerkdienst betreibt, verbreitet sie nicht und müsste seine
Änderungen nicht herausgeben. §13 der AGPL schließt genau diese Lücke.

## Zwei harte Regeln

**1. Alles wird signiert.** Jeder Commit und jedes Tag trägt eine gültige
GPG-Signatur. Unsigniertes wird nicht übernommen — auch nicht „vorläufig".

```
git config commit.gpgsign true
git log --format='%h %G? %s' origin/master..HEAD   # jede Zeile muss G tragen
```

**2. MariaDB, nichts anderes.** Kein PostgreSQL, kein SQLite — auch nicht für
Tests oder lokal. Die Tests laufen gegen eine echte MariaDB (mindestens 10.11).
Das ist keine Vorliebe: Ein Projekt, das auf zwei Datenbanken „auch irgendwie"
läuft, läuft auf keiner richtig, und die Vereine, die das betreiben, haben
niemanden für Datenbankrätsel.

## Wie ein Beitrag aussehen soll

**Commits:** [Conventional Commits](https://www.conventionalcommits.org) —
`feat:`, `fix:`, `docs:`, `refactor:`, `chore:`, `test:`.

**Commit-Nachrichten erklären das WARUM.** Was geändert wurde, steht im Diff.
Warum es geändert wurde und was passiert wäre, wenn nicht, steht nirgends sonst
— und genau das braucht der Mensch, der in zwei Jahren davorsitzt. Schau dir
die Historie an, dort ist der Ton zu sehen.

**Tests gehören dazu.** Nicht als Pflichtübung: Ein Test, der beschreibt, was
schiefginge, ist die haltbarste Form der Begründung. Bei sicherheitsrelevanten
Stellen mindestens ein Test, der beweist, dass es *nicht* geht.

**Sprache:** Code, Datenbank und Commits auf Englisch; die Oberfläche auf
Deutsch über Sprachdateien. Kommentare dürfen deutsch sein — Hauptsache, sie
erklären den Grund und nicht die Zeile darunter.

## Bevor du den Merge Request aufmachst

```
./vendor/bin/pint            # Formatierung
./vendor/bin/phpunit         # die Suite
./vendor/bin/phpunit --group rendering   # baut jeden Bildschirm wirklich
```

Beide Testläufe müssen grün sein. Der zweite ist ausgelagert, weil er die
Anwendung mehrfach neu hochfährt — er ist der einzige, der eine kaputte Seite
überhaupt finden kann.

## Was nicht angenommen wird

- **Multi-Tenant.** Eine Instanz, ein Verein. Keine `tenant_id`, auch nicht
  „vorsorglich".
- **Vereinsspezifisches im Code.** Namen, Logos, Kennzeichenformate,
  Anmeldeverfahren sind Konfiguration.
- **Code, der zur Laufzeit nachgeladen wird.** In keiner Ausbaustufe.
- **Hartes Löschen.** Datensätze werden nicht entfernt, sie werden als gelöscht
  markiert. Ein Nachweis, der verschwinden kann, ist keiner.
- **Umgehungen des Rahmenwerks** — rohe SQL-Zeichenketten statt Eloquent,
  ausgeschaltetes Escaping, Rechte, die nur im Bildschirm versteckt sind.

## Modulgrenzen

Jedes Modul bringt seine eigenen Migrationen, Modelle, Bildschirme und
Rechteprüfungen mit. Zwischen Modulen wird nur über festgelegte Schnittstellen
geredet, nie direkt auf fremde Tabellen. Der Kern muss ohne jedes Modul laufen,
und jedes Modul muss sich abschalten lassen, ohne den Rest mitzureißen.
`ModuleBoundaryTest` prüft das — auch für Übersetzungen.

## Sicherheitslücken

Nicht als Issue. Siehe [SECURITY.md](SECURITY.md).
