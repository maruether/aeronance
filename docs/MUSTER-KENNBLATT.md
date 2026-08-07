# Luftfahrzeugmuster und Kennblatt

> Wir sollten da das Kennblatt mitführen. Der Vereinsflieger hat da ein schönes
> Interface zum Suchen … ich weiß aber nicht, woher die Datenbasis kommt.
>
> Am liebsten hätte ich eine durchsuchbare Liste mit der Möglichkeit zum Freitext.
> Und daran angehangen den automatischen Download der Kennblätter. Die können von
> EASA, FAA oder den nationalen Behörden kommen. Wenn das nicht geht, dann machen
> wir es halt manuell. Das Datenblatt sollte dabei verlinkt werden können.

---

## 1. Warum eine Tabelle und kein Feld am Flugzeug

Ein Feld am Luftfahrzeug wäre schneller gewesen und in zwei Punkten falsch.

**Erstens:** Drei ASK 21 in einer Halle könnten dann drei verschiedene
Kennblatt-Nummern tragen, und nichts würde es merken. Ein Kennblatt ist eine
Eigenschaft des **Musters** — ein Dokument, eine Nummer, gleich wie viele Zellen.

**Zweitens, und das bezahlt die Migration:** Das LTA/TM-Modul entschied die
Betroffenheit über **Namensvergleich** und musste „ASK 21" gegen „ASK 21 B" in
beide Richtungen tolerieren, weil man der Schreibweise eines Vereins nicht trauen
kann. Mit einem Musterdatensatz kann eine Anweisung auf das Muster zeigen, und der
Abgleich wird **exakt** — ein Vergleich zweier IDs statt einer Vermutung über
Rechtschreibung.

**`model` bleibt am Flugzeug.** Nicht redundant: Das Muster ist optional, ein
Verein fliegt vielleicht etwas, das niemand katalogisiert hat, und einen Namen zu
tippen muss weiter funktionieren. Das Muster ist die bessere Antwort, wo es sie
gibt — nicht die einzige.

Die Migration legt für **jedes vorhandene Modell** ein Muster an und verknüpft die
Flugzeuge. Sonst hätte niemand ein Muster, bis er durchklickt, und der exakte
Abgleich würde still nie greifen.

---

## 2. Der Abgleich: exakt wo möglich, unscharf sonst

| | |
|---|---|
| Anweisung **und** Flugzeug haben ein Muster | ID-Vergleich, exakt |
| eines von beiden fehlt | Namensvergleich wie bisher |

**Eine bewusste Unsymmetrie:** Eine Anweisung *mit* Muster und ein Flugzeug *ohne*
fällt auf den Namen zurück, statt „nein" zu antworten. Ein nicht katalogisiertes
Flugzeug darf einer Anweisung nicht entgehen — dieselbe Regel wie überall in dem
Modul.

Der unscharfe Vergleich **bleibt**, er ist kein Übergangszustand: Eine
Herstellerliste nennt Muster, die noch nicht katalogisiert sind, und eine Zeile
muss importierbar sein, bevor jemand das Muster pflegt. Beim Import wird verknüpft,
wo die Bezeichnung **exakt** übereinstimmt — ein unscharfer Treffer würde eine
Herstellerzeile an die falsche Variante hängen.

---

## 3. Die durchsuchbare Liste, und dass Freitext bleibt

Beide Hälften zählen. Die Behördensuche füllt, was sie weiß; eine Bezeichnung, die
niemand katalogisiert hat, lässt sich trotzdem eintippen.

Die Suche läuft **zweistufig**: erst Treffer, dann auswählen. Ein einstufiges „hol
das Kennblatt zu diesem Namen" müsste raten, welcher Treffer der richtige ist —
und „ASK 21" liefert in der EASA-Bibliothek mehrere. Erst der **gewählte** Treffer
kostet einen zweiten Abruf für Details und Dokument; über alle Kandidaten zu
holen wären Dutzende Abrufe bei fremden Servern für Zeilen, die niemand nimmt.

**Fällt eine Behörde aus, antworten die anderen weiter.** Eine Suche ist kein Ort,
an dem etwas fehlschlagen darf: Ein fehlender Treffer ist als fehlender Treffer
sichtbar, eine Ausnahme würde die Antworten aller anderen verbergen.

---

## 4. Woher die Nummer kommt — EASA, geprüft

Vereinsfliegers Datenbasis ist nicht bekannt und war nicht zu ermitteln. Deshalb
selbst nachgesehen, **vor** dem ersten Codezeile:

- Die EASA-Dokumentenbibliothek antwortet über einfaches HTTP, ohne Zugangsdaten
- Eine Musterseite trägt ihre Nummer im Titel: `EASA.A.221 — Schleicher ASK 21`
- Der Dokumentlink antwortet mit `application/pdf`

Damit ist „automatisch" ein ehrliches Wort. Es gibt **keine API und keinen
Datensatz zum Herunterladen** — das habe ich gesucht und nicht gefunden; es bleibt
also Abruf und Auslesen.

Zwei Fallen, beide beim Bauen aufgelaufen:

**Der Slug plattet die Punkte der Behörde.** `easaa221` ist keine Nummer, die
jemand von einem Dokument abliest — sie wird zu `EASA.A.221` zurückverwandelt.
`easaima120` wird `EASA.IM.A.120`.

**EASA liefert Dokumente unter `/en/downloads/<id>/en`**, nicht unter einem
`.pdf`-Pfad. Die erste Fassung suchte nach der Endung und kam leer zurück.

### Und der Hersteller weiß es auch

Nebenbefund beim Schleicher-Adapter: Der Kopf der Übersichts-PDF trägt

```
Muster: ASK 21     Kennblatt-Nr.: EASA.A.221
                   Anerkennung/Genehmigungszeichen: DE.21G.0010
```

Daraus kam die Kennblatt-Frage überhaupt ins Rollen. Ausgelesen wird sie dort
**nicht** — das bräuchte eine PDF-Bibliothek als neue Abhängigkeit, und die
EASA-Suche liefert dasselbe ohne. Der Link auf die Übersicht wird aber
mitgenommen: „die reichen und können zum Hersteller verlinken."

---

## 5. Der Download läuft durch die Dokumentenprüfung des Kerns

Das ist keine Formsache. Dies ist die **erste Stelle im Projekt, die eine Datei von
einer URL schreibt, die niemand im Verein gewählt hat** — heute der Server einer
Behörde, morgen eine Weiterleitung woandershin, wenn sie umbauen.

Die Prüfung des Kerns macht schon: Größenbegrenzung, Dateityp aus den **echten
Bytes** statt aus der Endung, Virenscan. Sie hier zu umgehen hieße, diese Arbeit
genau für den Fall zunichte zu machen, für den sie gebaut wurde.

**Ablegen ist optional.** Ein Link kostet nichts; eine gespeicherte Kopie ist, was
ein Verein will, wenn die Behörde ihre Website umbaut. Beides wird angeboten — und
**ein fehlgeschlagener Download lässt Nummer und Link stehen**, statt den ganzen
Vorgang scheitern zu lassen. Die sind für sich brauchbar.

---

## 6. Das Blaue Buch des LBA — die bessere Quelle

> Da stehen alle in D registrierbaren Flugzeuge und co mit Kennblatt drin.

Und es ist tatsächlich die bessere der beiden Quellen: **Ein Dokument nennt die
deutsche Kennblatt-Nummer und die EASA-Referenz nebeneinander**, wo die
EASA-Bibliothek nur die zweite liefert, ein Muster pro Abruf.

```
339/SP   ASK 21   Schleicher   ASK 21   6 (2/90)   EASA.A.221
```

Die beiden ergänzen sich statt sich zu ersetzen: Das Blaue Buch hat **kein
Datenblatt zum Verlinken** — es ist eine Liste. Was es liefert, ist das
Nummernpaar, und die EASA-Referenz führt zum eigentlichen Dokument, das der
EASA-Adapter holen kann.

### Es ist ein PDF, und das kostete eine Abhängigkeit

`smalot/pdfparser` — reines PHP, kein Systembinary. Wichtig für die drei
Auslieferungskanäle: `pdftotext` hätte in Webserver-Pack, Docker-Image und
LXC-Skript als Voraussetzung nachgezogen werden müssen.

### Was das Layout wirklich hergibt

Geprüft, bevor gebaut wurde. Die Extraktion trennt Spalten mit
**Tab-Leerzeichen-Tab** und zerlegt Wörter *innerhalb* einer Spalte mit einem
bloßen Tab. Also: an Ersterem brechen, die Stücke einer Spalte **ohne Trenner**
zusammenfügen — genau das setzt `SZD` + `-48-1` zu `SZD-48-1` zurück.

Eine Zeile umfasst **eine bis vier Textzeilen**. Ein Mehrvarianten-Muster trägt
mehrere Modelle und mehrere Ausgabestände in parallelen Spalten. Deshalb liest der
Parser die **vier zuverlässigen Felder** — Kennblatt-Nr., Bezeichnung, Hersteller,
EASA-Referenz — und lässt Varianten- und Ausgabespalten in Ruhe. Sie falsch zu
lesen wäre schlimmer als sie nicht zu lesen: Ein Ausgabestand an der falschen
Variante ist eine Angabe, die niemand ohne das Dokument nachprüfen kann.

Die EASA-Referenz wird **je Zeilenblock** gesucht, nicht je Textzeile — bei
Zeile 338/SP steht sie auf der zweiten.

### Ein Fehler, den erst die echten Daten fanden

Das Dokument schreibt mindestens vier legitime Formen — `EASA.A.221`,
`EASA.SAS.A.028`, `EASA.IM.A.120` — plus einen Tippfehler `EASA A.038` mit
Leerzeichen statt Punkt. Meine erste Normalisierung plattete alles und setzte
einen Punkt **pro Buchstabe** ein: Aus `EASA.SAS.A.024` wurde `EASASAS.A.024`.

Sie erfand Struktur, wo das Dokument sie schon richtig hatte. Jetzt: Tabs der
Extraktion entfernen, verbleibende Leerzeichen zu den Punkten machen, die sie
hätten sein sollen — und **nichts weiter**.

### Hart gecacht, und das ist nicht Bequemlichkeit

Jeder Band ist ~450 kB PDF, und die veröffentlichte Ausgabe ist von **2021**. Das
pro Tastendruck einer Suchmaske zu holen und zu parsen wäre absurd. Also: einmal
holen, geparste Treffer 30 Tage behalten. `refresh()` gibt es für den Tag, an dem
eine neue Ausgabe erscheint — erkennen kann das nichts hier, weil das Ausgabedatum
im Text des PDF steht und nicht in einem billig prüfbaren Header.

### Umfang

Verdrahtet sind die **Luftfahrzeug**-Bände: Segelflugzeuge, Motorsegler, Flugzeuge
bis 2 t (da wohnt die Schleppmaschine). Der Band über 2 t ist deklariert, aber
nicht in der Vereinssuche — ein großes Dokument, das die Suche nur bremsen würde.

Motoren, Propeller, **Schleppkupplungen und Winden** stehen ebenfalls im Blauen
Buch, und die Tost-Kupplung macht sie fachlich interessant. Sie sind aber
**Komponenten, keine Luftfahrzeugmuster**, und diese Naht erzeugt
`AircraftType`-Datensätze. Im Enum benannt, damit die Lücke sichtbar bleibt statt
vergessen zu werden.

### Getestet gegen das echte Dokument

`tests/Fixtures/Lba/blaues-buch-segel.pdf` ist `04_segel.pdf`, wie veröffentlicht.
Ein amtliches Werk (§ 5 UrhG), also urheberrechtsfrei — und die einzige
Möglichkeit, die ganze Kette samt PDF-Extraktion zu prüfen. Selbstgeschriebener
Text hätte weder die umbrechenden Zeilen noch die zerlegten Bindestriche noch die
fünf EASA-Schreibweisen gehabt.

---

## 7. Die Zuordnung am Flugzeug — die Kette schließt

Ohne dieses Feld existierte die Mustertabelle, und **nichts im normalen Ablauf
füllte sie**: Wer ein Flugzeug anlegt, begegnete ihr nie. Der exakte
LTA-Abgleich und das Kennblatt wären beide theoretisch geblieben.

**Beides steht im Formular**, Muster *und* freies Modellfeld. Ein Muster zu wählen
macht den Abgleich exakt und hängt das Kennblatt dran; einen Namen zu tippen
funktioniert weiter für alles, was niemand katalogisiert hat.

Die Musterauswahl kann **direkt anlegen** (`createOptionForm`). Das Muster, das
jemand braucht, fehlt in der Regel genau dann, wenn er das Flugzeug einträgt — ihn
dafür auf eine andere Seite zu schicken ist, wie ein Feld leer bleibt.

Ein gewähltes Muster **belegt das Modellfeld vor**, damit die beiden nicht still
auseinanderlaufen. Der Hersteller wird nur *angeboten*, nie erzwungen: Ein
Luftfahrzeug kann legitim einen anderen führen (Lizenzbau), und das stillschweigend
zu überschreiben wäre schlimmer als es stehen zu lassen.

### Wo die Logik jetzt liegt, und was nicht getestet ist

Die Vorbelegung stand zuerst in einer Closure im Formular. Sie liegt jetzt am
Modell (`AircraftType::prefill()`), denn **Logik in einer Formular-Closure ist
nicht testbar** — und sie entscheidet, was ein Mensch in zwei Feldern sieht.

Ehrlich benannt: Das **Filament-Formular selbst ist nicht getestet**. Dieses Panel
baut seine Resources beim Boot aus der Modultabelle, und `RefreshDatabase` gibt
jedem Test eine leere — die Flugzeug-Resource über Livewire zu fahren scheitert
also an fehlenden Routen und nicht an irgendetwas an dieser Funktion. Das Framework
dafür zu bekämpfen wäre ein Test von Framework-Verhalten. Was ungetestet bleibt,
ist ein Select, den Filament an eine Relation hängt.

---

## 8. Musterbetreuung — und Muster, für die es keine mehr gibt

Vorgabe: *„den typen können wir aufnehmen und die Liste erstmal mit der Warnung
‚Achtung! Kein Musterbetreuer!' versehen."*

Am Muster hängen zwei Felder: **`type_support`** (Freitext — wer betreut heute?
Bei den Grob-Segelflugzeugen steht dort „LTB Lindner") und
**`without_type_support`** (Kennzeichen: es gibt niemanden mehr).

### Warum das ein ausdrückliches Feld ist und keine Ableitung

Das LTA/TM-Modul sichert überall gegen einen einzigen Fehler ab: **Eine leere
Liste darf nie aussehen wie „der Hersteller hat nichts Neues veröffentlicht."**
Bei verwaisten Mustern ist die Liste aber legitim leer — nur aus einem ganz
anderen Grund. Drei Zustände, die nicht dasselbe sind:

| | Zustand | Was zu sehen ist | Reaktion |
|---|---|---|---|
| 1 | Musterbetreuer da, Quelle eingerichtet | die normale Liste | — |
| 2 | Musterbetreuer da, Quelle **nicht** eingerichtet | leere Liste, die **beide Lesarten benennt** | Aufgabe für den Administrator |
| 3 | **kein Musterbetreuer** | „Achtung! Kein Musterbetreuer!" | Dauerzustand, nichts zu konfigurieren |

Zustand 3 aus „keine Quelle gefunden" abzuleiten würde ihn mit Zustand 2
verwechseln — und die beiden wollen gegensätzliche Reaktionen: 2 verschwindet,
sobald jemand die Quelle einrichtet, 3 nie. Deshalb sagt es ein Mensch, am
Muster.

Betroffen sind Muster, deren Hersteller es nicht mehr gibt und für die niemand
die Musterbetreuung übernommen hat: Bölkow Phoebus, SHK-1, Fauvel AV-36, IS-28B2,
alte K-Muster, SN Centrair (Pégase). Der Verein ist dann auf sich gestellt und
muss selbst recherchieren.

### Wo die Warnung steht

- **LTA/TM-Übersicht am Bildschirm** — als roter Callout **über** der Zählung.
  Bewusst dort: Bei einem verwaisten Muster ist jede Zahl darunter weiterhin
  korrekt und trotzdem irreführend, weil die gezählte Liste nicht mehr wachsen
  kann. Ein dezenter Hinweis neben einem grünen „0 offen" verliert diese
  Auseinandersetzung jedes Mal.
- **Druckübersicht** — als schwerer, grau hinterlegter Kasten ganz oben, noch vor
  der „nicht beurteilt"-Meldung. Das ist das Blatt, das ein Prüfer bei der
  Jahresnachprüfung in die Hand nimmt; ohne diesen Satz behauptet es eine
  Vollständigkeit, die es nicht hat. Beide Stellen ziehen **denselben
  Sprachschlüssel** (`directives.orphaned.*`) — zwei Kopien einer Warnung laufen
  genau einmal auseinander.
- **Leere Tabelle** — auch die Zelle „kein Eintrag" sagt jetzt, welche der beiden
  Lesarten gilt, statt Schweigen für eine Antwort durchgehen zu lassen.

Verwendet wird Filaments eigener `x-filament::callout`, nicht eine selbstgebaute
Box: Er wird vom Panel-Stylesheet des Releases gestylt und ist damit unabhängig
vom Asset-Build rot.

### Pflege

Im bestehenden Musterformular (Anlegen wie Bearbeiten), plus Spalte und Filter in
der Musterliste. Beide Felder bleiben unabhängig editierbar; trägt jemand beides
ein, **gewinnt das Kennzeichen** — aufgelöst an einer Stelle
(`AircraftType::typeSupport()`) statt im Formularzustand ausgefochten. Beide
Felder stehen im Aktivitätsprotokoll: Wer das Kennzeichen wann gesetzt hat, ist
genau die Frage, die ein Jahr später kommt.

---

## 9. Was noch fehlt

- **Zustand 2 wirklich erkennen** — heute benennt die leere Liste nur beide
  Lesarten. Ob für ein Muster überhaupt eine Quelle eingerichtet ist, lässt sich
  nicht offline beantworten: Die Hersteller-Specs führen ihre Musterlisten über
  HTTP-Indizes, nicht als abfragbares Feld. Bräuchte einen Deckungsabgleich
  Muster ↔ Quelle
- **Flugzeuge ohne Muster** — ein Luftfahrzeug mit reinem Freitextmodell hat
  keinen Musterdatensatz, der gekennzeichnet sein könnte, und bekommt deshalb nie
  eine Warnung. Bewusst so, aber eine Lücke
- **FAA-Adapter** — die Naht trägt nachweislich zweimal. Die FAA hat ein
  Dokumentensystem (DRS)
- **Komponentenmuster** — Motoren, Propeller, Schleppkupplungen und Winden aus dem
  Blauen Buch. Braucht eine eigene Entität, kein `AircraftType`
- **Blaues Buch: Ausgabewechsel erkennen** — das Datum steht im Text des PDF, nicht
  im Header. Heute muss `refresh()` von Hand kommen
- **Kennblatt-Fristigkeit** — ein Kennblatt wird neu ausgegeben. Ob und wie ein
  „geprüft am" zur Wiedervorlage werden soll, ist offen
- **Panel-Tests für Modul-Resources** — kein Test fährt bisher eine
  Filament-Resource, weil das Panel seine Resources beim Boot aus der Modultabelle
  baut. Lösbar (Modul vor dem Panel-Bau aktivieren), aber eine eigene Aufgabe
