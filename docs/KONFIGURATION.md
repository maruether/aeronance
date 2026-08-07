# Konfiguration

> Vorgabe: „Ziel muss es sein, die Konsole nur für das Starten des fertig
> runtergeladenen Dockers und für den Break-glass zu benötigen. Wir können den
> Usern nicht zumuten, alles Mögliche in Config-Files zu schreiben."

## Was vorher war

Gezählt: **25 Werte** verlangten das Editieren einer Datei.

| Bereich | lag in |
|---|---|
| Name der Organisation, Zeitzone | `.env` |
| Sicherung, Verschlüsselung, Auslagerung, SFTP-Zugang | `.env` |
| Dokumentgröße, Fälligkeitsfenster, Toleranzen, Virenscanner | `.env` |
| **Aufbewahrungsfristen** | **`config/aeronance.php`** |

Die letzte Zeile war der schlimmste Fall: Die Aufbewahrungsregeln waren nicht
einmal über `.env` erreichbar, sondern nur durch Editieren einer PHP-Datei —
die im Docker-Kanal **im Image liegt** und bei jedem Update verlorengeht.
Retention war dort faktisch nicht einschaltbar, und niemandem war es
aufgefallen, weil der Schalter ja existierte.

Dazu: CLAUDE.md schreibt dem Einrichtungsassistenten eine „Basiskonfiguration
(Vereinsname, Logo)" zu. Gebaut waren Datenbank, Migration, Administrator und
Module. Im Administratorschritt gab es sogar ein Feld `club_name` — es wurde
**geprüft und danach nie benutzt**: Wer den Namen dort eintippte, verlor ihn
stillschweigend.

## Die Rangfolge

```
Datenbank  →  Umgebung  →  Vorgabe
```

die Entscheidung: **„db gewinnt, env nur initial."** Praktisch heißt das:

- Steht in der Tabelle nichts, gilt die Umgebungsvariable. Eine
  `docker-compose.yml` wirkt also wie erwartet.
- Sobald jemand den Wert **einmal** in der Oberfläche gesetzt hat, gilt die
  Tabelle — und die Umgebung wird für diesen Schlüssel nie wieder gelesen.

Das **muss die Oberfläche sagen**, und sie tut es: Ein Feld, das aus der
Umgebung gefüllt ist, sieht sonst aus wie ein gesetztes. Ändert später jemand
die compose-Datei und nichts passiert, ohne dass ein Fehler erscheint, ist das
genau die stille Sorte Fehler, die dieses Projekt an anderer Stelle schon
zweimal gekostet hat.

## Was in Dateien bleibt — und warum es bleiben muss

| Wert | Grund |
|---|---|
| `APP_KEY` | entschlüsselt genau diese Tabelle |
| `DB_*` | ist die Verbindung, über die die Tabelle erreicht wird |

Das ist die harte Untergrenze. Beides steht im Docker-Kanal in der
compose-Datei, wo es hingehört.

## Aufbau

- **`settings`** — Schlüssel, Wert, `is_secret`. **Alle** Werte verschlüsselt,
  nicht nur die Geheimnisse: Zwei Spalten wären eine Einladung, ein Passwort
  versehentlich in die falsche zu schreiben.
- **`SettingsCatalogue`** — die 25 Definitionen. Aus einer Beschreibung
  entstehen vier Dinge, die sonst auseinanderlaufen: Formular, Prüfung,
  Rangfolge und die Überlagerung der Konfiguration.
- **`Settings::applyToConfig()`** — beim Start, eine Zeile im
  `ModuleServiceProvider`. Damit bleibt `config()` die einzige Leseschnittstelle:
  `BackupCommand`, `RetentionCommand`, die Disks und der Virenscanner mussten
  **nicht angefasst** werden.
- **`SettingsPage`** — rechtegeprüft (`core.settings.manage`), gruppiert, im
  Audit-Log. Werte nie im Protokoll.

Vor der ersten Migration gibt es die Tabelle nicht; `Settings` fällt dann still
auf Umgebung und Vorgabe zurück. Ein Startfehler dort machte eine frische
Installation unbedienbar, bevor sie eingerichtet ist.

## Geheimnisse

Backup-Passwort, SFTP-Zugang, S3-Secret, privater Schlüssel: `encrypted`-Cast,
und in der Oberfläche **schreibend statt lesend**. Angezeigt wird „ein Wert ist
hinterlegt", das Feld bleibt leer.

**Ein leeres Geheimfeld heißt „nicht ändern", nicht „löschen".** Andernfalls
würde das Speichern einer beliebigen anderen Einstellung das Backup-Passwort
entfernen — und auffallen würde es erst beim nächsten nächtlichen Lauf, der
dann nichts mehr auslagern darf. Ein Test hält genau das fest.

Der private SFTP-Schlüssel ist über die Oberfläche einzutragen (Vorgabe: „ja, key
hochladen") — mit dem ausgeschriebenen Hinweis, dass er damit auch in jeder
Sicherung liegt. Wer das nicht will, nimmt ein Passwort.

## Was bewusst *nicht* einstellbar ist

Es gibt keinen Schlüssel, der das Audit-Log abschaltet, und keinen, der die
Aufbewahrung unter die gesetzliche Frist drückt. Dass eine Einstellung fehlt,
ist hier gelegentlich der Mechanismus — siehe E3.

## Damit bleibt für die Konsole

1. Den Container starten.
2. Break-glass.

Sonst nichts.

## Logo

Hochladbar in den Einstellungen (PNG, JPEG, WebP, höchstens 1 MB). Abgelegt wird
es auf der **privaten** Disk und über die Route `/logo` ausgeliefert — nicht aus
`public/`. Der übliche Weg dorthin wäre `storage:link`, ein Symlink, den im
Docker-Kanal jeder neue Container neu bräuchte und den im Webserver-Kanal gern
jemand vergisst. Eine Route funktioniert in allen drei Kanälen gleich.

**Ohne Anmeldung**, und das ist Absicht: Das Logo steht auf der Anmeldeseite,
die naturgemäss niemand angemeldet aufruft. Es ist das Wappen einer
Organisation, kein Geheimnis. Ausgeliefert wird nur, was tatsächlich ein Bild
ist — geprüft am Inhalt der gespeicherten Datei, nicht am Dateinamen. Ein SVG
mit Skript wäre sonst genau die Lücke, die die CSP schliesst.

## Zurücksetzen

Jedes gesetzte Feld hat einen `zurücksetzen`-Knopf. Ohne ihn gäbe es keinen Weg
zurück: Ein einmal gesetzter Wert gewinnt für immer gegen die Umgebung, und wer
ihn wieder aus der `docker-compose.yml` beziehen wollte, müsste die Zeile in der
Tabelle von Hand löschen — also doch wieder auf die Konsole.

Angeboten wird er nur, wo tatsächlich ein gespeicherter Wert liegt. Ein Knopf,
der nichts tut, ist eine Frage, die niemand beantworten kann.

## Rückfallwerte sind auch Konfiguration

In der Nacht zum 2026-08-06 fielen bei einer systematischen Durchsicht **sechs**
Vorgaben auf, die noch aus dem Laravel-Gerüst stammten. Jede war jahrelang
unauffällig, und sie teilen dasselbe Muster:

| Datei | stand auf | Wirkung |
|---|---|---|
| `composer.json` | `license: MIT` | widerspricht der `LICENSE` (AGPL-3.0) |
| `config/app.php` | `locale: 'en'` | **ganze Oberfläche in rohen Schlüsseln** |
| `config/app.php` | `name: 'Laravel'` | Seitentitel, Anmeldemaske, Mailabsender |
| `config/database.php` | `'sqlite'` | **gegen das Hard Limit MariaDB** |
| `config/session.php` | `secure` ohne Wert | Sitzungscookie unverschlüsselt |
| `public/robots.txt` | `Disallow:` leer | Anmeldemaske im Suchindex |

**Warum sie so lange unsichtbar blieben, ist die eigentliche Lehre:**
`.env.example` überschreibt sie alle — **und `phpunit.xml` setzt dieselben
Werte.** Damit war der Rückfallwert weder im Betrieb noch im Test je zu sehen.
Er greift ausschließlich dort, wo jemand eine unvollständige `.env` hat, also
bei genau der Installation, die ohnehin Mühe hat.

Der Fall `APP_LOCALE` zeigt, wie leise das ausgeht: Laravel meldet eine
fehlende Sprachdatei nicht, es zeigt den Schlüssel an. Gemessen ergab eine
Installation ohne die Zeile:

```
users.field.name      ->  users.field.name
warehouse.scan.field  ->  warehouse.scan.field
```

Also eine vollständig unbenutzbare Oberfläche, mit HTTP 200 und ohne einen
einzigen Fehlereintrag. Auch die Rendering-Tests konnten das nicht sehen — die
Seiten bauen sich ja.

**Daraus folgt eine Regel für dieses Projekt:** Ein Rückfallwert muss für sich
allein richtig sein. `.env.example` ist eine Vorlage und eine Dokumentation,
kein Ersatz für eine brauchbare Vorgabe. `ConfigDefaultsTest` liest deshalb den
**Quelltext** der Konfigurationsdateien statt der geladenen Werte — die
geladenen sagen im Test immer das Richtige, und genau das war das Problem.

Derselbe Test prüft außerdem, dass jede `AERONANCE_*`-Einstellung in
`.env.example` vorkommt. Auch das war eine Lücke: Acht Einstellungen gab es im
Code, aber nicht in der Vorlage — darunter die gesamte Aktualisierungsprüfung.
Vorhanden, wirksam, unauffindbar.

## Begriff

Der Betreiber heisst **Organisation**, nicht „Verein" — die Software soll auch
von einem Part-145-Betrieb gefahren werden. Was das genau umfasst und wo
„Verein" richtig bleibt, steht in [`GLOSSAR.md`](GLOSSAR.md).
