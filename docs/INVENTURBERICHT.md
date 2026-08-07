# Inventurbericht — Vorschlag

**Stand:** 2026-07-29 · **Status:** Vorschlag angenommen, teilweise gebaut

> **die Antworten (2026-07-29):**
> 1. Unterschreitungen und Verfall werden **eigene, jederzeit abrufbare Listen** —
>    Vermutung bestätigt. ✅ **gebaut**
> 2. Zählliste zum Ausdrucken: **ja**. ✅ **gebaut**
> 3. Gezählte Mengen zurück ins System: **ja** — „aber Achtung mit der Erhöhung
>    von Mengen bei Form-1-geführten Teilen. Da dürfen wir nicht aus Versehen ein
>    Teil einem falschen Form One zuweisen." ✅ **Logik gebaut**, Maske offen.
>
> Offen ist noch der **Stichtagsbericht selbst** (Abschnitt 1 und 6 unten).

---

## Die Ausgangslage

Der Vorgänger hatte einen Menüpunkt „Inventurbericht", hinter dem das Wort
„Inventurberichte" stand. Was drin stehen sollte, war nie festgelegt — deshalb
diese Frage.

Zwei Vorgaben grenzen das Feld ein:

- **Keine Bewertung in Euro.** Warenwirtschaft ist ausdrücklich draußen (E6);
  ein Preisfeld existiert, aber nichts hängt daran.
- **Der Bestand ist die Summe der Bewegungen** (E1). Das hat eine Folge, die
  der Vorgänger nicht haben konnte und die den Bericht erst interessant macht —
  siehe gleich.

---

## Der Kern: Bestand zum Stichtag

Eine Inventur ist per Definition eine Aussage **zu einem Datum**. Weil der
Bestand aus den Bewegungen entsteht und keine überschreibbare Mengenspalte
existiert, lässt sich für jeden beliebigen Tag exakt ausrechnen, was da war:

```
Bestand am Stichtag = Σ Bewegungen mit occurred_at ≤ Stichtag
```

Das ist keine Schätzung und keine Fortschreibung, sondern dieselbe Rechnung wie
für heute — nur mit einer anderen Obergrenze. Der Vorgänger hätte das nie
beantworten können, weil er die Menge überschrieb.

**Praktischer Nutzen:** Nach einer körperlichen Zählung im Januar lässt sich
fragen „was sagt das System für den 31.12.?" und Differenzen als Korrekturen
buchen — mit dem Original daneben, weil Korrekturen Gegenbuchungen sind.

---

## Vorschlag: ein Bericht mit sechs Abschnitten

Nicht sechs Berichte. Ein Dokument, das man ausdruckt und in den Ordner legt —
mit einer Kopfzeile aus Vereinsname, Stichtag und Erstellungszeitpunkt.

### 1. Bestandsliste zum Stichtag

Die eigentliche Inventur. Je Bauteiltyp:

| Spalte | Anmerkung |
|---|---|
| Bezeichnung, IPC-Nummer | |
| Klassifizierung | Bauteil / Standard Part / Verbrauchsmaterial |
| Lagerort | Ort [Fach] |
| **Verfügbar** | brauchbar und nicht verfallen |
| **Gesperrt** | im Haus, aber nicht verwendbar |
| Gesamt | zur Abstimmung mit der körperlichen Zählung |
| Einheit | |

Getrennte Spalten für verfügbar und gesperrt, weil beim Zählen im Regal beides
in der Hand liegt, in der Verwendbarkeit aber alles auseinandergeht.

Bei losgeführten Teilen aufklappbar bis auf die einzelnen Lose mit Losnummer,
Restmenge, Verfallsdatum und Form-1-Nummer.

### 2. Unterschreitungen

Was nachbestellt werden muss: Bauteiltypen unter Mindestbestand, mit Fehlmenge
und Lieferant. Der einzige Abschnitt, den man auch außerhalb der Inventur
regelmäßig braucht.

### 3. Verfall

Zwei Blöcke: **bereits abgelaufen** (liegt noch da und darf nicht verbaut
werden — Handlungsbedarf) und **läuft in den nächsten 90 Tagen ab**. Sortiert
nach Datum.

### 4. Gesperrter Bestand

Jedes Los, das nicht brauchbar ist: Zustand, Sperrzettelnummer, Grund, seit
wann, und wer es festgestellt hat. Die Liste, die man vor einem Audit sehen
will — und die zeigt, ob etwas seit Monaten unentschieden im Sperrlager steht.

### 5. Nachweislücken

Lose, die einen Form 1 brauchen und wo

- gar kein Nachweis erfasst ist, oder
- eine Nummer erfasst, aber **kein Dokument hinterlegt** ist.

Der zweite Fall ist der interessante: für die tägliche Arbeit reicht die
Nummer, für ein Audit nicht. Diese Liste macht die Lücke sichtbar, bevor jemand
anderes sie findet.

### 6. Bewegungsjournal (optional, im Anhang)

Alle Bewegungen im Berichtszeitraum. Fürs Abstimmen und weil eine Inventur ohne
die Bewegungen dazwischen nur eine Momentaufnahme ist. Standardmäßig
abgeschaltet, weil es der mit Abstand längste Teil wird.

---

## Bedienung

**Ein Formular, vier Angaben:**

| Feld | Vorbelegung |
|---|---|
| Stichtag | heute |
| Nur ein Lagerort | alle |
| Abschnitte | 1–5 an, 6 aus |
| Nullbestände zeigen | aus |

**Ausgabe:** dieselbe Technik wie beim Sperrzettel — eine druckoptimierte
HTML-Seite. Kein PDF-Paket nötig, Vorschau inklusive, und für einen Bericht ist
Millimetergenauigkeit ohnehin nicht nötig. Zusätzlich **CSV je Abschnitt**, weil
jemand die Zählliste in eine Tabellenkalkulation nehmen will.

**Recht:** `stock.report` — existiert bereits, wird bisher nirgends geprüft.

---

## Was ich bewusst weglasse

| Nicht drin | Warum |
|---|---|
| Bewertung in Euro | Warenwirtschaft, E6 |
| Reichweitenberechnung, Verbrauchsprognose | Braucht Verbrauchshistorie über Jahre und rät sonst |
| Lieferantenauswertung | E6 |
| ABC-Analyse | Für Vereinsgröße ohne Nutzen |
| Automatischer Versand | Erst wenn jemand danach fragt |

---

## Offene Fragen dazu

1. **Reicht ein Bericht mit Abschnitten, oder willst du die Unterschreitungen
   und den Verfall als eigene, jederzeit abrufbare Listen?** Meine Vermutung:
   ja — das sind Alltagsfragen, keine Jahresfragen. Dann wären es zwei Dinge:
   ein Inventurbericht zum Stichtag und ein „Was liegt an?"-Bildschirm.
2. **Zählliste zum Ausdrucken?** Also dieselbe Bestandsliste, aber mit leerer
   Spalte „gezählt" zum Eintragen von Hand. Das ist der Zettel, mit dem man
   tatsächlich durchs Lager geht.
3. **Sollen gezählte Mengen zurück ins System?** Also eine Maske, die die
   Zählliste aufnimmt und die Differenzen als Korrekturbuchungen anlegt. Das
   wäre der nächste logische Schritt und braucht das Recht, Korrekturen zu
   buchen — bisher gibt es den Bewegungstyp, aber keine Maske.


---

## Nachtrag: die Regel hinter die Warnung zu F3

Der Einwand trifft einen Punkt, der nicht nach einem Sicherheitsproblem aussieht
und einer ist:

> **Ein Überschuss bei einem losgeführten Teil darf niemals auf ein vorhandenes
> Los gebucht werden.**

„+1" auf ein Los ist keine Rechenkorrektur, sondern eine **Behauptung**: dass
dieses zusätzliche Teil mit jener Lieferung kam und folglich von jenem Form 1
gedeckt ist. Wer ein Regal zählt, weiß das nicht. Fünf Ölfilter, wo vier
verbucht sind, heißen: ein Filter unbekannter Herkunft — und unbekannte Herkunft
heißt kein Nachweis.

**Umgesetzt** in `RecordStocktake`:

| Fall | Verhalten |
|---|---|
| Sammelbestand, Differenz in beide Richtungen | Korrekturbuchung, unproblematisch — kein Nachweis im Spiel |
| Los, **Fehlmenge** | Korrekturbuchung gegen das Los. „Dieses Los ist eines zu wenig" behauptet nichts über einen Nachweis |
| Los, **Überschuss** | **abgelehnt** mit der Begründung |
| Gefundenes Teil | **neues Los**, ohne Nachweis, **gesperrt** |

Das gefundene Teil bekommt bewusst **kein erfundenes Verfallsdatum** — es müsste
aus einem Eingangsdatum abgeleitet werden, das niemand kennt, und ein erfundenes
Datum auf einem Lufttüchtigkeitsnachweis ist schlimmer als gar keines.

Dass ein gefundenes Teil im Sperrbestand landet, ist keine Strafe fürs
Falschzählen, sondern die Lage: Nach ML.A.504 ist ein Teil, dessen
Lufttüchtigkeitsstatus sich nicht bestimmen lässt, unbrauchbar. Jemand muss nun
klären, woher es kam — und entweder das Papier beibringen oder es ausmustern.

Die Zählliste bricht losgeführte Teile deshalb **je Los** herunter: Gezählt wird
pro Los, nicht pro Bauteiltyp, sonst ließe sich die Regel gar nicht anwenden.
