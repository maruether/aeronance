# Komponentenmuster

> Auch die haben TMs.

Stimmt, und die ASK-21-Liste beweist es: Die Tost-Kupplung trägt **„2 Jahre oder
500 Starts, whatever comes first"**, eigene Technische Mitteilungen und ein
eigenes Kennblatt (`60.230/2` im Kupplungsband des LBA).

---

## 1. Eine eigene Tabelle, nicht ein gemeinsamer „Typ"

`ComponentType` steht neben `AircraftType`, nicht als dessen Variante mit einer
Art-Spalte. Sie sehen ähnlich aus und verhalten sich verschieden: Ein
Luftfahrzeugmuster hat **eine** Zelle je Kennzeichen, ein Komponentenmuster kommt
in einer Flotte **vielfach** vor und trägt Laufzeiten **je Einbau**. Verschmolzen
müsste sich jede Abfrage über beide merken, welches der Fälle gerade gemeint ist.

**Wohin es nicht reicht: der Teilestamm des Lagers.** Das Lager fordert die Flotte
nicht, ein Fremdschlüssel von dort würde also die Modulgrenze brechen oder das
Lager zur Pflicht machen. Ein aus dem Lager eingebautes Teil bekommt sein
Komponentenmuster **am Einbau** — der Datensatz der Flotte. Dieselbe Disziplin wie
bei jeder anderen Lager-Flotte-Verbindung in diesem Projekt.

---

## 2. Was das bringt: exakte Zuordnung, wie beim Flugzeugmuster

Eine Bauteil-Anweisung verglich bisher Text gegen `part_name`. Mit katalogisiertem
Muster auf beiden Seiten ist es ein ID-Vergleich.

Konkret: Eine TM für die **Europa G 73** trifft ein Flugzeug mit **G 88** nicht
mehr, obwohl beide „Sicherheitskupplung Europa G …" heißen.

**Der Seriennummernbereich engt weiter ein**, auch bei exaktem Mustertreffer:
Katalogisiert heißt nicht bedingungslos. Eine Anweisung ab S/N 0100 trifft eine
0042 nicht, nur weil das Muster stimmt.

**Und die bekannte Unsymmetrie bleibt:** Ein *nicht* katalogisierter Einbau fällt
auf den Namensvergleich zurück. Den Katalog zu pflegen darf die ungepflegten
Bauteile nicht aus der Reichweite einer Anweisung entfernen.

Die **Teilenummer** steht getrennt von der Bezeichnung, weil eine TM das eine oder
das andere nennt — eine Teileliste und ein Mensch sagen Verschiedenes über
dasselbe Ding.

---

## 3. Von Hand erfasst — und das ist ein Befund

Der Versuch, die Komponentenbände des LBA maschinell zu lesen, ist gescheitert.
Nicht am Aufwand, sondern an den Dokumenten:

**Motoren und Propeller haben in der Textebene überhaupt keine Trenner.** Die
Extraktion liefert:

```
Piston Engines4502/ENPorsche 678Dr. Ing. H.c. F. Porsche KG678/1
```

Felder aneinandergeklebt, dazu „Walter" als „W alter" zerbrochen. Daraus lassen
sich keine Spalten rekonstruieren.

**Das Kupplungsband trennt sauber, bricht aber die Bezeichnungen um:**

```
60.230/2        Sicherheitskupplung
Europa G 88 Tost GmbH  Sicherheitskupplung Europa G 88  1 (2/89)
```

Drei Tost-Kupplungen kämen alle als „Sicherheitskupplung" heraus. Ein Versuch, das
aus der Baureihenspalte zu vervollständigen, funktionierte für Kupplungen — und
machte in den Flugzeugbänden aus **„SF 34" ein „SF 34 B"**, beförderte also eine
Variante zum Muster. Eine Regel, die die funktionierenden Bände beschädigt, ist
die falsche Regel; sie wurde entfernt statt mit Sonderfällen gerettet.

Vorgabe zu genau dieser Möglichkeit: *„Wenn das nicht geht, dann machen wir es halt
manuell."* Drei Kupplungen von Hand richtig benannt schlagen drei vom Parser
„Sicherheitskupplung" genannte.

**Die Kennblatt-Nummer bleibt trotzdem tippenswert:** Danach fragt ein Prüfer, und
sie steht auf dem Dokument, das demjenigen vorliegt, der sie einträgt.

### Was der Parser dabei trotzdem gewonnen hat

Zwei Reparaturen, die die *Flugzeug*-Bände besser machen und im Zuge dieser
Untersuchung entstanden:

**Vier Nummernformate statt einem.** `339/SP`, `4502/EN`, `60.230/2`,
`32.100/1/PR`. Die erste Fassung kannte nur „Ziffern + /BUCHSTABEN" und hätte in
den Komponentenbänden **still nichts** gefunden — still, weil ein Band ohne
Treffer genauso aussieht wie ein sauber geparstes.

**Trennerstil am Ergebnis erkannt, nicht am Vorkommen eines Tabs.** Das
Kupplungsband trennt mit Leerzeichen, lässt aber am Zeilenende Tabs stehen — „hat
einen Tab" schickte diese Zeilen in den Tab-Pfad, der die ganze Zeile als eine
Spalte zurückgab.

---

## 4. Winden: geprüft, und nicht nötig

Auf die Frage, ob im Windenband auch **eingebaute** Seilwinden stehen: Nein.
`07-2-winden.pdf` ist überschrieben „Startgeräte / Launching Devices" und listet
Bodenwinden — Rhön, Tost-Doppeltrommelwinde, System Dunkel. Bodengerät wird nicht
unter dem Instandhaltungsprogramm eines Luftfahrzeugs gewartet, also gibt es hier
nichts, was es sein könnte. Ein Test hält das fest, damit die Frage nicht wieder
aufkommt.

---

## 5. Was noch fehlt

- **Laufzeiten am Komponentenmuster vorschlagen** — „2 Jahre oder 500 Starts"
  steht heute je Einbau. Am Muster hinterlegt könnte der Einbau sie vorbelegen;
  die Grenzen selbst müssen aber am Einbau bleiben, denn sie laufen ab Einbaudatum
- **Hersteller-TM-Listen für Komponenten** — Rotax und Tost veröffentlichen je
  eine. Das Feld dafür existiert; ein Adapter wäre je Hersteller einer, mit
  denselben Vorbehalten wie bei Schleicher
- **Komponentenmuster beim Einbau wählen** — die Zuordnung existiert im
  Datenmodell und im Katalog; das Feld an der Einbaumaske fehlt noch. Dieselbe
  Lücke, die beim Flugzeugmuster zuletzt geschlossen wurde
