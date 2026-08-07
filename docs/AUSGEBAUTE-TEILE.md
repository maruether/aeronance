# Ausgebaute Teile — Recherche und Vorschlag

**Stand:** 2026-07-29 · **Status:** Recherche vertieft, eine Frage bleibt für den CAO

> **die Antworten (2026-07-29):**
> 1. Selbst weiterrecherchieren → **getan, mit einem korrigierenden Ergebnis.**
> 2. Modul soll **dokumentieren**, nicht prüfen → übernommen.
> 3. Lebensdauerbegrenzte Teile: *„Es kommt drauf an."* **TBR** (Zündkerzen,
>    Schläuche) → weg, keine Wiederverwendung. **TBO**, teils auf Lebenszeit
>    (z. B. Schleppkupplung) → etwas anderes. Kennzeichen gut, aber die Daten
>    müssen **vom Flottenmodul kommen** und ins Lager wandern, wenn das Teil
>    bereits verbaut war.

die Frage: *„Prüfe bitte die Legalität der Wiederverwendung. Für Instrumente
und sowas brauchen wir das aber auf jeden Fall."*

---

> ## ⚠ Vorab: Belastbarkeit
>
> Der direkte Abruf von EUR-Lex, EASA und der CAA-Regelbibliothek ist aus dieser
> Umgebung netzseitig blockiert. Was folgt, stammt aus
> **Suchmaschinen-Zusammenfassungen** dieser Quellen und **nicht aus dem
> Verordnungswortlaut**.
>
> Das reicht, um das Datenmodell darauf auszurichten. Es reicht **nicht**, um
> eine Einbauentscheidung darauf zu stützen. Die verbindliche Auskunft gibt der
> CAMO/CAO-Betrieb oder das LBA — nicht diese Datei und nicht die Software.

---

## 1. Die kurze Antwort

**Ja, Wiederverwendung ist möglich** — aber über zwei verschiedene Wege, die
unterschiedliche Voraussetzungen haben. Welcher greift, hängt vom Teil und vom
Weg ab, den es genommen hat.

Für Instrumente sieht es günstig aus: Sie sind typischerweise weder
lebensdauerbegrenzt noch Teil der Primärstruktur noch der Steuerung — genau die
drei Ausschlusskriterien, an denen der einfachere Weg sonst scheitert.

## 2. Weg A — korrigiert: die Ausnahme ist ballonspezifisch

**Die frühere Fassung dieses Abschnitts war zu optimistisch.** Vertiefte
Recherche bestätigt aus zwei unabhängigen Quellen, dass die Formulierung
lautet: „removed in serviceable condition **from a balloon** … installed on
**another balloon**".

**Damit greift diese Erleichterung für Segelflugzeuge und Motorsegler nicht.**
Für sie gilt die Grundregel aus ML.A.501(a): Einbau nur mit Form 1 oder
Gleichwertigem, mit den Ausnahmen Standard Parts und 21.A.307(b)/(c).

### Was daraus praktisch folgt

| Fall | Beleg |
|---|---|
| Aus- und **wieder in dasselbe Luftfahrzeug** im selben Arbeitsgang (Zugang, Umbau) | Kein Form 1. Das Teil wird mit Grund der Entnahme gekennzeichnet und wieder eingebaut — gängige Praxis, im 145-Umfeld ausdrücklich über das Betriebshandbuch geregelt. Für Part-ML liegt das Äquivalent bei den Verfahren des CAO bzw. des freigabeberechtigten Personals |
| **In ein anderes Luftfahrzeug** | **Form 1 erforderlich** — ausgestellt von einem Betrieb mit Komponentenberechtigung (Part-145 B/C oder Part-CAO mit Klasse „Komponenten") |

**Das ist die entscheidende Frage an euren CAO:** Hält die Akaflieg — oder der
Betrieb, mit dem sie arbeitet — eine Komponentenberechtigung, die es erlaubt,
für ein brauchbar ausgebautes Instrument einen Form 1 auszustellen? Wenn ja,
ist der Tausch zwischen Vereinsflugzeugen sauber machbar. Wenn nein, muss das
Teil dafür zu einem Betrieb, der das darf.

Für das Datenmodell ändert das weniger, als es klingt — aber es ergibt **eine
Regel, die die Software durchsetzen kann**:

> Ein Los, das aus einem Ausbau stammt und **keinen** Form 1 hat, darf nur in
> **dasselbe Luftfahrzeug** zurück, aus dem es kam.

Das ist dieselbe Art von Sicherung wie die Überschussregel bei der Inventur:
Die Software behauptet nichts über Zulässigkeit, sie verhindert nur, dass eine
Buchung eine Aussage trifft, die der Beleg nicht trägt.

## 3. Weg B — Vom Halter akzeptierte Teile (21.A.307(c))

Der zweite Weg, ergänzt durch VO 2021/699 mit Wirkung ab 18.05.2022. Ein Teil
darf ohne Form 1 eingebaut werden, wenn **alle** folgenden Bedingungen erfüllt
sind:

| # | Bedingung |
|---|---|
| 1 | **nicht** lebensdauerbegrenzt |
| 2 | **nicht** Teil der Primärstruktur |
| 3 | **nicht** Teil der Steuerung |
| 4 | in Übereinstimmung mit den anwendbaren Konstruktionsdaten gefertigt |
| 5 | gekennzeichnet nach Part 21 Subpart Q |
| 6 | für den Einbau in **dieses bestimmte Luftfahrzeug** bestimmt |
| 7 | der **Halter hat die Erfüllung geprüft und die Verantwortung dafür übernommen** |

Punkt 7 ist der wesentliche und derjenige, den Software nicht abnehmen kann:
Es ist eine ausdrückliche Übernahme von Verantwortung durch den Halter.

Punkt 4 legt nahe, dass dieser Weg eher auf **neue** Teile ohne Form 1 zielt als
auf gebrauchte. Auch das gehört zu den Fragen an den CAMO/CAO.

## 4. Was das für Aeronance heißt

**Die Software entscheidet nichts über Legalität.** Sie hat nur eine Aufgabe:
**festzuhalten, was den Fall trägt** — und zwar so, dass es Jahre später noch
lesbar ist.

Beiden Wegen ist dieselbe Anforderung gemeinsam:

> Ein Nachweis über den **Zustand beim Ausbau**, rückverfolgbar zum
> Luftfahrzeug, aus dem das Teil stammt, festgestellt von jemandem, der dafür
> qualifiziert war.

Und genau dafür existiert der Mechanismus bereits. Die Feststellung mit
eingefrorenem Snapshot (E7/E8) hält Name, Lizenznummer, Kategorie und
Gültigkeit fest — nur bisher in die andere Richtung („unbrauchbar").

### Vorschlag

Ein Los kann künftig **zwei Herkünfte** haben:

| Herkunft | Beleg | Vorhanden |
|---|---|---|
| Lieferant | Form 1, CoC | ✅ |
| **Ausbau aus einem Luftfahrzeug** | **Zustandsfeststellung beim Ausbau** | neu |

Konkret:

- `stock_lots.origin` — `supplier` oder `removal`
- Beim Ausbau: Kennzeichen, Muster, Ausbaudatum, Grund (die Felder, die der
  Sperrzettel ohnehin schon trägt)
- Eine **Feststellung beim Ausbau** wie jede andere: qualifizierter Akt,
  Snapshot der Qualifikation, unveränderlich
- `document_type` bleibt beim Ausbau **`none`** — nicht, weil kein Nachweis da
  wäre, sondern weil der Nachweis die Feststellung selbst ist und nicht ein
  Papier von jemand anderem. Ein eigener Wert `removal_record` hätte danach
  ausgesehen, als läge eine Bescheinigung vor; sie liegt nicht vor, und genau
  daran hängt die Einschränkung weiter unten

Damit läuft der Ablauf „Instrument aus D-KABC ausgebaut, brauchbar, eingelagert,
später in D-KXYZ eingebaut" durch dieselbe Maschinerie wie alles andere — und
die Kette Luftfahrzeug → Ausbau → Los → Einbau → Luftfahrzeug ist in beide
Richtungen abfragbar.

### Was dabei zu beachten ist

- **Der Ausbau ist eine Feststellung, kein Buchungsvorgang.** „Brauchbar
  ausgebaut" ist eine fachliche Aussage, für die jemand einsteht — also
  qualifikationspflichtig wie das Freigeben aus dem Sperrlager.
- **Ohne Feststellung landet das Teil im Sperrbestand.** Wer ein Teil ausbaut
  und nicht beurteilt, hat ein Teil unbekannten Zustands — nach derselben Logik
  wie Ware ohne Form 1.
- **Kennzeichen bleibt freier Text ohne Fremdschlüssel** (D4). Das Flottenmodul
  muss nicht installiert sein.
- **Lebensdauerbegrenzung ist nicht gleich Lebensdauerbegrenzung.** die
  Unterscheidung ist der Punkt, den mein erster Vorschlag verfehlt hat:

  | Art | Bedeutung | Beispiel | Nach dem Ausbau |
  |---|---|---|---|
  | `none` | keine Begrenzung | Halterung | wiederverwendbar |
  | `on_condition` | bis zum Befund | vieles | wiederverwendbar |
  | **`tbo`** | Überholungsintervall, teils auf Lebenszeit | **Schleppkupplung** | überholen, dann wieder verwendbar |
  | **`tbr`** | Austauschintervall | **Zündkerzen, Schläuche** | **weg** — kein Rückweg ins Lager |

  Ein pauschales „lebensdauerbegrenzt = gesperrt" hätte die Schleppkupplung
  mitgesperrt, obwohl gerade sie der Fall ist, für den sich der ganze Aufwand
  lohnt. **Nur `tbr` bekommt keinen Ausbau-Weg**; ein solches Teil geht beim
  Ausbau direkt in die Ausmusterung.

- **Die Luftfahrzeugdaten gehören dem Flottenmodul.** Vorgabe: Kennzeichen und
  Muster müssen von dort kommen und ins Lager wandern, wenn das Teil bereits
  verbaut war. Das Lager ist nicht die Quelle dieser Wahrheit, es empfängt sie.
  Solange das Flottenmodul fehlt, bleibt es freier Text; danach liefert das
  Flottenmodul die Angaben beim Ausbau mit, und das Lager schreibt sie fest.
  Das ist dieselbe Richtung wie beim Form 1, nur umgekehrt: dort gibt das Lager
  an die Lebenslaufakte ab, hier nimmt es von der Flotte entgegen.

### Die Regel, die aus die Vorgabe folgt

> „Grundsätzlich ist davon auszugehen, dass die Betriebe keine Bauteile
> reparieren dürfen."

Das verschiebt den Schwerpunkt der ganzen Sache. Solange ein Verein keinen
Form 1 für ein ausgebautes Teil ausstellen kann, ist der Einbau **in ein
anderes Luftfahrzeug** nicht der Randfall, sondern der Regelfall des
Nicht-Erlaubten. Was bleibt, ist der Weg, der ohne Bescheinigung auskommt:

> **Ohne Form 1 geht ein ausgebautes Teil nur dorthin zurück, wo es herkam.**

Ein Ausbauprotokoll belegt, dass das Teil beim Ausbau brauchbar war — mehr
nicht. Für das Ursprungsluftfahrzeug reicht das, denn dort war es ohnehin
schon eingebaut und der Zustand ist lückenlos belegt. Für ein anderes
Luftfahrzeug braucht es die Bescheinigung eines Betriebs mit
Komponentenberechtigung.

Das ist keine Einstellung und kein Haken, sondern die Voreinstellung: Ein Los
aus einem Ausbau trägt sein Kennzeichen mit sich, und die Ausbuchung prüft es.
Trägt das Los später einen Form 1 nach, fällt die Einschränkung von selbst weg
— dieselbe Prüfung, ein anderes Ergebnis.

## 4a. Umsetzung

Gebaut und getestet (17 Tests: `RemovalTest`, `RemovalPageTest`):

| Regel | Wo sie sitzt |
|---|---|
| Brauchbarkeit ist eine Feststellung, kein Haken | `RemovePartFromAircraft` — Berechtigung **und** Qualifikation, Snapshot in `lot_state_changes` |
| Ohne Feststellung → Sperrbestand | ebenda; die Buchung wird nicht verweigert, denn ein nicht erfasstes Teil ist schlimmer als eines unbekannten Zustands |
| TBR kommt nicht zurück | `PartType::allowsReuseAfterRemoval()`, geprüft vor jeder Buchung |
| Ohne Form 1 nur ins eigene Luftfahrzeug | `StockLot::mayBeFittedTo()`, erzwungen in `IssueStock::assertIssuable()` |
| Kein erfundenes Verfallsdatum | `expires_at` bleibt `null` — Lagerzeit läuft ab Herstellung oder Lieferung, nicht ab dem Tag des Ausbaus |

Zwei Entscheidungen, die beim Bauen dazukamen:

- **Die Buchung ohne Feststellung ist erlaubt.** Der erste Entwurf hat sie
  verweigert. Das Ergebnis wäre gewesen, dass das Teil gar nicht erfasst wird —
  es liegt dann trotzdem im Regal, nur weiß es niemand. Sperrbestand ist die
  ehrlichere Antwort.
- **Die Einschränkung wird auf dem Bildschirm gesagt, nicht in der
  Dokumentation.** Wer gerade eingelagert hat, ist derjenige, der das Teil
  später woanders einbauen will. Die Meldung bleibt stehen und verschwindet
  nicht nach drei Sekunden.

## 5. Beantwortet: keine Komponentenberechtigung

Die eine Frage, die keine Recherche beantworten konnte, ist entschieden:

> **Wir haben keine Berechtigung, und es ist erstmal davon auszugehen, dass sie
> niemand hat.**

Das ist keine Einschränkung des Entwurfs, sondern seine Voreinstellung. Alle
Annahmen, die von einer eigenen Form-1-Ausstellung ausgingen, sind damit vom
Tisch, und die Ausbau-Regel steht ohne Wenn und Aber:

> Ein ausgebautes Teil ohne Form 1 geht nur in dasselbe Luftfahrzeug zurück.

Damit fehlte allerdings der Ausweg. Ein Instrument aus der D-KABC wäre auf
ewig an die D-KABC gebunden — und für den Fall, dass es in die D-KXYZ soll,
hätte das System nur „nein" gesagt, ohne zu sagen, wie es doch geht.

**Es geht so: Weggeben.** Ein Betrieb, der die Berechtigung hat, setzt das Teil
instand und stellt die Form 1 aus. Was zurückkommt, trägt dessen Bescheinigung
und ist frei verwendbar. Die Bindung wird nicht umgangen, sie wird von jemandem
aufgelöst, der das darf. Genau dafür gibt es jetzt die Entnahme **„Zur
Reparatur"** — siehe [`LAGERMODUL.md`](LAGERMODUL.md), Abschnitt 12.

Damit ist der Kreis geschlossen:

```
Ausbau aus D-KABC ──► Los, gebunden an D-KABC
                          │
                          ├─ zurück in D-KABC ...................... geht immer
                          │
                          └─ zur Reparatur ──► Betrieb mit Berechtigung
                                                    │
                                                    ├─ mit Form 1 ──► frei
                                                    └─ ohne ───────► Sperrbestand,
                                                                     weiter gebunden
```

Alles Übrige war schon entschieden: dokumentieren statt prüfen, TBR ohne
Ausbau-Weg, TBO mit, Luftfahrzeugdaten vom Flottenmodul.

## Quellen

[EASA FAQ 136280 zu 21.A.307 / VO 2021/699](https://www.easa.europa.eu/en/faq/136280) ·
[CAA Aviation Regulation Library, 21.A.307](https://regulatorylibrary.caa.co.uk/748-2012/Content/Regs/03930_21A307_Release_of_parts_and_appliances_for_installation.htm) ·
[EASA Easy Access Rules, ML.A.501](https://www.easa.europa.eu/en/document-library/easy-access-rules/online-publications/easy-access-rules-continuing-airworthiness?page=57) ·
[AviLaw, Classification and installation](https://avilaw.info/avilaw-base/classification-and-installation-components/) ·
[AviLaw, Removal of serviceable components](https://avilaw.info/avilaw-base/removal-of-serviceable-components-from-aircraft-and-change-over-of-serviceable-components-between-aircraft/)
