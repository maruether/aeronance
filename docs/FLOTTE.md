# Flottenmodul

Luftfahrzeuge, was sie geleistet haben, was an ihnen hängt und was abläuft.

Der Modulschnitt kommt aus der Analyse und ist bestätigt: **Das Lager führt
kalendarischen Verfall, die Flotte führt Betriebszeiten.** Flugstunden,
Landungen und Zyklen beginnen mit dem Einbau.

---

## 1. Zähler

| Zähler | Wann |
|---|---|
| **Flugzeit** | immer |
| **Landungen** | immer |
| Motorlaufzeit | nur wenn ein Zähler verbaut ist |
| Starts, Zyklen | wenn Bauteile daran gemessen werden |

Die ersten beiden werden **hinzugerechnet, nicht gespeichert**. Vorgabe: „das muss
gesetzlich geregelt erfasst werden" — also kann keine Migration, kein Import und
keine von Hand geänderte Zeile ein Luftfahrzeug erzeugen, das sie nicht mehr
führt.

Bei der Motorlaufzeit steckt ein Detail, das leicht danebengegangen wäre: **Nicht
jedes Flugzeug mit Motor hat einen Zähler.** Aus „hat Motor" auf „hat
Motorstunden" zu schließen hätte Ablesungen erfunden, die niemand vornimmt.

Zählerstände sind **absolut und nur anfügbar** — so, wie sie am Instrument
stehen. Nach der Differenz zu fragen hieße, vor dem Tippen rechnen zu lassen, und
genau dort entstehen Fehler. Korrigiert wird durch einen weiteren Stand, der auf
den falschen verweist; beide bleiben sichtbar. Dieselbe Regel wie im
Bestandsjournal (E1), aus demselben Grund.

---

## 2. Laufzeitgrenzen — Zeilen, keine Spalten

> **„2 Jahre oder 500 Starts, was zuerst eintritt."**

die Tost-Schleppkupplung ist der Grund für die ganze Struktur. Eine
Komponente trägt **mehrere Grenzen verschiedener Art**, fällig ist die früheste.

Als Spalten wäre daraus ein halbleeres Feldpaar geworden, das nichts vergleicht —
und der Vergleich *ist* die Antwort. Als Zeilen ist „was ist fällig" ein Minimum
über eine Menge, und eine dritte Grenze ist ein Datensatz statt einer Migration.

Zwei Folgerungen, beide als Test festgehalten:

- **Überfällig, sobald *eine* Grenze reißt** — nicht wenn alle reißen. Andersherum
  dürfte ein Teil zwei Jahre über die Kalendergrenze fliegen, weil noch Starts
  übrig sind.
- **Eine Komponente ohne Grenzen ist der Normalfall.** „Ein Ölfilter geht mit der
  Motorwartung und ein neuer kommt."

---

## 3. TSN und TSO

Der Fall, an dem die erste Fassung zerbrochen ist:

> Motor A fährt zum Hersteller, der macht eine GÜ und **setzt die TSO auf null**;
> die TSN läuft weiter. Motor B fährt zum **selben** Hersteller zur Reparatur,
> die TSO wird **nicht** zurückgesetzt.

Identische Reisen, verschiedene Ergebnisse. Also darf **nichts an der Reise
entscheiden** — nicht dass sie stattfand, nicht wohin, nicht wie lange. Nur das
zurückkommende Papier sagt es, und eine Überholung zu behaupten verlangt genau
dieses Dokument: Die Laufzeit auf null zu setzen ist eine Aussage, und eine, die
nur auf einem gesetzten Haken beruht, ist die Sorte, nach der ein Audit fragt.

Zwei Akkumulatoren, die sich nur im Startpunkt unterscheiden — nach dem Einbau
laufen beide mit denselben Flugzeugstunden weiter.

### Die Basis an der Grenze

| Grenze | misst gegen |
|---|---|
| **TBO** | seit Überholung — dafür steht das O |
| **Lebensdauergrenze** | seit Neu |

Eine TBO gegen die TSN gelesen verurteilt einen frisch überholten Motor; eine
Lebensdauergrenze gegen die TSO gelesen lässt ihn ewig fliegen.

### Der Fehler, den der Fallback ausgelöst hat

Bauteile ohne TSN/TSO-Unterscheidung laufen über einen Fallback: Fehlt die TSO,
wird die TSN gelesen — bei einem nie überholten Teil *ist* sie das.

Genau dieser Fallback hat zugeschlagen: Bei der Überholung stand ein leeres Array
für „zurückgesetzt", das las sich als „nicht angegeben" und fiel auf die Zahl
zurück, die gerade genullt werden sollte. **Motor A kam identisch zu Motor B
heraus.** Jetzt schreibt eine Überholung ausdrückliche Nullen. Der Unterschied
zwischen „keine Angabe" und „null" ist der ganze Motor A.

---

## 4. Nutzung wird abgeleitet

```
Nutzung = mitgebrachte Vorgeschichte + (Zählerstand jetzt − Zählerstand beim Einbau)
```

Damit frisst ein Einbau in ein Flugzeug mit 3000 Starts nicht am ersten Tag das
ganze Leben, eine überholte Kupplung mit 300 Starts bekommt kein zweites volles
Leben, und ein ausgebautes Teil hört auf mitzuzählen.

Führt das Flugzeug den Zähler nicht, ist die Antwort **`null` statt `0`** — „0
verbraucht" läse sich als „reichlich übrig".

Die Vorgeschichte wird über die **Seriennummer** weitergereicht,
flugzeugübergreifend. Auf ein Flugzeug beschränkt würde jedes umgebaute Teil sein
Leben neu beginnen — leise, und in der Richtung, die dem Teil schmeichelt.

---

## 5. Pilot-Owner: die Kette läuft rückwärts

> „Ich darf auch an Privatflugzeugen nach Pilot-Owner freigeben, solange ich im
> AMP aufgeführt bin."

Die Berechtigung folgt der **Nennung im Instandhaltungsprogramm**, nicht dem
Eigentum. Wer genannt ist, darf — auch an einem fremden Flugzeug. Ein Halter, der
nicht genannt ist, darf nicht.

Der naheliegende Weg wäre gewesen, `Authority` die Flotten-Tabelle befragen zu
lassen. Das hätte den **Kern auf ein Modul** zeigen lassen — die eine Richtung,
die die Architektur verbietet, denn der Kern muss ohne jedes Modul laufen.

Also andersherum: Die Nennung schreibt **beides** — den Flotten-Datensatz und die
Kern-Qualifikation mit Kennzeichen als Geltungsbereich, die `Authority` längst
versteht. Der Kern erfährt nie, dass es eine Flotte gibt. Angefasst wurde er gar
nicht.

Austragen **beendet** die Qualifikation, statt sie zu löschen: Sie war bis heute
wahr, und ein verschwundener Datensatz kann nicht beantworten, ob die Arbeit im
Frühjahr gedeckt war.

---

## 6. Übergabe vom Lager

Das erste Modul-Interface im Projekt, und die Analyse hat es Monate vorher
verlangt: „Lager übergibt Nachweis an Flotte. Es lohnt sich, dafür beim Entwurf
ein Event vorzusehen, auch wenn zunächst niemand darauf hört."

**Die Nutzlast ist die Schnittstelle.** Alles, was ein Zuhörer braucht, reist als
einfache Werte mit — sonst müsste die Flotte eine Lagertabelle öffnen, und die
Grenze existierte nur noch in der Dokumentation.

| | |
|---|---|
| **Was ankommt** | alles außer Standard Parts. „Niemanden interessiert die Mutter oder Niete von Würth" |
| **Was mitgeht** | das Papier. „Wenn ein Form 1 oder CoC dranhängt geht das Papier mit aufs Flugzeug über" |
| **Was nicht passiert** | Laufzeiten werden nicht erfunden. Wer sie kennt, trägt sie ein |

Ein Form 1 kann in **mehreren** Flugzeugen enden — vier Ölfilter aus einem Los
gehen an vier Luftfahrzeuge. Die Übergabe ist deshalb eine **Kopie, keine
Verschiebung**: ein Dokument, mehrere Lebenslaufakten.

Das Lager sagt Bescheid und hört auf, sich zu kümmern. Ist die Flotte
abgeschaltet, bucht die Entnahme trotzdem. Kein Fremdschlüssel überquert die
Grenze, in keine Richtung — beide Seiten halten eine lose Referenz, und die Kette
ist von beiden Enden begehbar.

Das Event wird **unbedingt** registriert (D1) und fragt den ModuleManager selbst.
Nach Aktivierungszustand zu registrieren hieße, dass sich die Verdrahtung ändert,
sobald jemand ein Modul umschaltet — die Sorte Sache, die funktioniert, bis sie
es nicht mehr tut.

---

## 7. Fälligkeiten

Die Frage, für die das Modul existiert. Drei Quellen, eine Liste: die
Nachprüfung, ablaufende Bauteilgrenzen, und **Luftfahrzeuge ganz ohne
hinterlegte Nachprüfung**.

Der letzte Fall ist der, den solche Listen üblicherweise übersehen: Wo nichts
hinterlegt ist, läuft auch nichts ab — das Flugzeug liest sich als in Ordnung.
Es wird deshalb als überfällig gemeldet.

Gezählte Grenzen haben kein Datum. Gemeldet wird das **letzte Zehntel der
Laufzeit**, und das ist bewusst grob: Starts in Tage umzurechnen bräuchte eine
Flugrate, und ein Flugzeug, das letzten Sommer 200 Stunden geflogen ist, fliegt
diesen vielleicht keine. Lieber eine grobe Regel, die zugibt, was sie ist, als
Arithmetik, die sich als Vorhersage ausgibt.

Der Zähler am Menüpunkt zählt **nur Überfälliges**. Ein Zähler, der alles
Fällige der nächsten zwei Monate mitzählt, ist nie null — und einer, der nie
null ist, wird nicht mehr gelesen.

---

## 8. Der Rückweg: Flotte → Lager

Die Gegenrichtung zur Übergabe. Ein Bauteil, das vom Flugzeug kommt, liegt danach
im Regal — ohne dass es jemand ein zweites Mal eintippt.

Der Weg führt durch die **eigene Ausbau-Aktion des Lagers**, und das ist der
Punkt: Jede Regel, die dort schon gilt, gilt unverändert weiter.

| Regel | greift auch hier |
|---|---|
| „Brauchbar ausgebaut" ist eine Feststellung | Part-66-Lizenz, eingefroren |
| Ohne Feststellung → Sperrbestand | ja |
| TBR kommt nicht zurück | ja — das Teil geht ab, landet aber nicht im Regal |
| Ohne Form 1 nur ins Ursprungsflugzeug | ja |

Nichts davon steht auf diesem Weg noch einmal. Eine zweite Tür hieße ein zweites
Regelwerk, und das zweite ist immer das, was hinterherhinkt.

**Drei Fälle laufen still durch**, denn das hier hängt hinter dem Ausbau von
jemand anderem und darf einen richtigen Ausbau nie zum Fehler machen:

- kein Lagermodul installiert;
- der Bauteiltyp ist unbekannt — eine handgetragene Zeile in der Lebenslaufakte
  muss im Lager nicht existieren;
- das Lager lehnt ab. Zündkerzen sind der Normalfall: Sie kommen ab und werden
  entsorgt, und die Flotten-Buchung ist richtig, ob das Teil aufhebenswert ist
  oder nicht.

**Was ausdrücklich nicht mitreist, sind die Betriebszeiten.** Die gehören der
Flotte und bleiben dort: Ein Los im Regal hat eine kalendarische Lebensdauer,
keine laufende. Geht das Teil wieder ans Flugzeug, findet `FitComponent` seine
Geschichte über die Seriennummer — sie war nie im Lager und musste es nie sein.

---

## 9. Ausrüstungsverzeichnis und Betriebszeitenübersicht

Die BWLV führt zwei Blätter, weil Papier dieselben Zeilen nicht zweimal zeigen
kann. Hier ist es **eine Tabelle und zwei Drucke** — genau die Formulierung:
„ein Ausrüstungsverzeichnis, in dem ich auch gleich die Laufzeiten und co habe".

Zwei Haken, und sie beantworten **verschiedene** Fragen:

| Haken | Bedeutung |
|---|---|
| `is_present` | die BWLV-Spalte: „ankreuzen, wenn vorhanden" |
| `is_minimum_equipment` | die Zusatz, und der mit Zähnen |

> Das zusätzliche Garmin G5 darf raus und der Vogel fliegt. Die Analoganzeige
> raus, und er steht.

Der Unterschied ist dieser Haken und sonst nichts — beides sind Instrumente,
beides war eingebaut, nur eines ist gefordert.

**Der Hebelarm gehört dazu**, weil das Ausrüstungsverzeichnis zugleich Teil des
Wägungsnachweises ist: Die BWLV-Spalte heißt „Einbauort, oder Hebelarm in mm vom
Bezugspunkt (+/− Vorzeichen beachten)". Vorzeichenbehaftet, in Millimetern — ein
Hebelarm ohne Vorzeichen ist eine Zahl, mit der niemand rechnen kann.

### „fällig bei" und „fällig in"

Die eine Spalte, die das Formular hatte und das Modell nicht. Sie sind **nicht
dieselbe Frage**:

- **fällig in** — was übrig ist: „in 20 Starts", „in 3 Monaten"
- **fällig bei** — was das Instrument am Flugzeug anzeigen muss: „bei 1.700"

Am Hangar steht ein Zähler mit einer Zahl drauf. Wer davorsteht, will
vergleichen, nicht rechnen. Beide stehen jetzt nebeneinander in der
Komponentenliste und auf dem gedruckten Blatt.

Hergeleitet wird „fällig bei" aus dem Rest statt aus der Einbau-Momentaufnahme:
Die Nutzung des Bauteils läuft eins zu eins mit dem Flugzeugzähler mit, solange
es eingebaut ist — also ist der fällige Stand schlicht *heutiger Stand plus
Rest*. Offensichtlich, sobald man es aufschreibt, und deutlich schwerer falsch
zu machen als die Momentaufnahme noch einmal aufzudröseln.

Gedruckt wird wie die Sperrzettel als **HTML mit Millimeter-Geometrie**, aus
denselben Gründen — und weil diese Blätter in denselben Ordner wandern wie die,
die der CAMO schickt: Ein Blatt, das ein paar Millimeter daneben druckt, passt
dort nicht dazu.

---

## 10. Wägung

die Korrektur, und sie war nötig: **Die Hebelarme im Ausrüstungsverzeichnis
sind das Material, mit dem man rechnet, wenn etwas ausgebaut wird — nicht die
Wägung.** Die ist ein eigenes unterschriebenes Dokument mit eigener Arithmetik,
und die Zahl, die dabei herauskommt, ist die, auf die sich alles andere bezieht.

### Zwei Formen, nicht drei

Die BWLV-Formulare für Flugzeug und Motorsegler sind **dasselbe Dokument mit
anderer Überschrift** — gleiche Abschnitte, gleiche Spalten, gleiche Rechnung.
Diesen Unterschied nachzubauen hieße, eine Überschrift zu modellieren.

| | Segelflugzeug | Flugzeug / Motorsegler |
|---|---|---|
| Wägung | bauteilweise, je Zeile **zwei** Werte | auf Auflagen, mit Moment |
| Zweite Spalte | M.N.T. — die nichttragenden Teile haben eine eigene Grenze | — |
| Abzüge | keine | ausfliegbarer Kraftstoff (0,72 kg/l), Schmierstoff (0,89 kg/l) je Behälter |

Die zweite Spalte beim Segler ist kein Detail: Eine Fläche trägt, ein Rumpf
nicht, und aus keiner Summe lässt sich herauslesen, welcher Anteil welcher war.

### Die beiden gezeichneten Formeln sind eine

Das Segler-Blatt zeichnet

```
X = (G2 · b) / G − a        und        X = (G2 · b) / G + a
```

mit zwei kleinen Skizzen, was aussieht wie zwei Fälle zum Auswählen. Sie
unterscheiden sich **nur** darin, ob der Bezugspunkt vor oder hinter der
vorderen Auflage liegt. Mit vorzeichenbehaftetem `a` ist es

```
X = (G2 · b) / G + a
```

und das Vorzeichen entscheidet. Zwei Kästchen auf dem Papier, eine Gleichung im
Code — und eine Sache weniger, die sich um elf Uhr abends falsch ankreuzen lässt.

### Abzüge haben Hebelarme

Kraftstoff aus einem Flügeltank zu nehmen **verschiebt den Schwerpunkt** und
macht nicht nur leichter. Nur die Masse abzuziehen setzte den Leermassen-
Schwerpunkt genau um den Betrag daneben, auf den es ankommt. Ein Test hält das
fest.

### Was gespeichert wird

**Eingaben und Ergebnis.** Das Ergebnis ließe sich neu berechnen, und eine Weile
sieht das ordentlicher aus — aber die Zahlen eines unterschriebenen Dokuments
sind sein Inhalt (E7). Einen Bericht von 2019 mit Code von 2027 neu zu rechnen
hieße, eine fremde Unterschrift über eine Antwort zu setzen, die derjenige nie
gegeben hat.

### Ein Ergebnis außerhalb des Bereichs wird gemeldet, nicht verweigert

Eine Wägung, die aus dem Rahmen fällt, ist eine echte Messung an einem echten
Flugzeug. Das Speichern zu verweigern hieße, dass die einzige Kopie auf dem
Blatt in jemandes Hand liegt. Der Befund steht im Formular, bevor jemand
unterschreibt — und auf dem Ausdruck.

---

## 11. Beladeplan

Die Wägung sagt, wo das Flugzeug leer balanciert. Der Beladeplan beantwortet die
Frage, die der Pilot tatsächlich hat: **wie viel darf in den Sitz.**

```
X = (M·x_e + m·x_s) / (M + m)     nach m aufgelöst:
m = M·(X − x_e) / (x_s − X)
```

Aufgelöst statt gesucht — damit ist die Grenze exakt und nicht auf zehn Kilo
gerundet.

Drei Dinge, die beim Bauen wichtig waren:

- **Die Fluggewichts-Schwerpunktlagen sind nicht die Leermassen-Grenzen.** Zwei
  verschiedene Zahlenpaare; das eine fürs andere zu nehmen wäre in genau die
  Richtung falsch, die jemanden Schweren einsteigen lässt. Fehlen sie, gibt es
  **keinen** Plan statt eines plausiblen.
- **Welche Grenze das Minimum liefert, hängt vom Sitzplatz ab.** Ein Sitz hinter
  dem Schwerpunkt kehrt es um. Angenommen statt sortiert hätte das einen
  negativen Bereich ergeben.
- **Eine Untergrenze ist ein realer Fall.** Ein leer hecklastiger Segler braucht
  einen Mindestpiloten — ein Plan, der nur Maxima meldet, verstecke das.

Beim Doppelsitzer kommt eine **Tabelle zum Querlesen**, weil ein Beladeplan im
Cockpit so aussieht: Man geht vom Gewicht des Hintermanns aus.

**Das Flughandbuch bleibt maßgeblich**, und der Ausdruck sagt das. Handbücher
enthalten Größen, die hier nicht bekannt sind — Hecktank, Wasserballast, feste
Trimmgewichte. Dieselbe Regel wie beim LTA-Modul: Das Werkzeug darf Arbeit
erzeugen, nie welche wegnehmen.

### Ergebnis außerhalb des Bereichs

Vorgabe: „ist ein Ergebnis, verhindert halt die Freigabe, aber das ist im echten
Leben so." Genau so ist es gebaut — der Befund steht im Formular und auf dem
Ausdruck, gespeichert wird trotzdem.

### Abgezeichnet heißt unveränderlich

die Vorgabe, und sie ist die Regel, die dieses System überall sonst schon
hält: Bestandsbewegungen lassen sich nicht ändern, Zählerstände nicht löschen,
eine Freigabe ist nach der Erteilung eingefroren. Die Begründung ist jedes Mal
dieselbe — **das Dokument ist der Nachweis, und ein Nachweis, der sich nachher
überarbeiten lässt, ist keiner.**

„Speichern und drucken" rechnet und unterschreibt **in einer Transaktion**, damit
zwischen der Arithmetik und dem Namen darunter keine Zeile mehr wechseln kann.
Danach sind Kopf **und Zeilen** gesperrt — nur den Kopf zu sperren wäre Zierde
gewesen, denn die Zahlen kommen aus den Zeilen.

**Korrektur ist eine neue Wägung**, nie eine geänderte. Das ist keine
Softwareschikane, sondern was auf Papier auch passiert: Das alte Blatt trägt eine
Unterschrift und lässt sich nicht still verbessern.

Nebenbei schärft das die Abweichungsprüfung: Vorher hatte ein Auseinanderlaufen
zwei mögliche Ursachen — geänderte Zeilen oder geänderte Rechnung. Sind die
Zeilen gesperrt, bleibt nur die zweite. Ein abgezeichneter Bericht, der nicht
mehr zu seiner eigenen Arithmetik passt, sagt damit etwas über den *Code*, und
das ist die interessantere Hälfte.

### Was in die nächste Wägung übernommen wird

| übernommen | nicht übernommen |
|---|---|
| Massengrenzen, Schwerpunktbereiche, Cockpit-Zuladung | **Abstände der Waagen zum Bezugspunkt** |
| Fluggewichts-Schwerpunktlagen | alle gemessenen Werte |
| **Definition** des Bezugspunkts und der Bezugslinie | |
| Sitzplätze mit Hebelarm | |
| Zeilen*bezeichnungen* der Bauteile | deren Massen |

Die Trennlinie ist: **Was aus dem Handbuch kommt, wird kopiert. Was gemessen
wurde, fängt leer an.** Handbuchwerte beschreiben das Muster und alle vier Jahre
neu abzutippen sind vier Gelegenheiten, eine Ziffer zu drehen. Eine Messung
weiterzureichen ließe dagegen einen Fehler von 2022 still zum Ergebnis von 2026
werden, ohne dass das Blatt es je zeigte.

Hier lag die Linie zuerst falsch: Ich hatte den *Bezugspunkt selbst*
ausgenommen. Der ist aber definiert — „Flügelvorderkante Wurzelrippe" — und
wandert nicht. Was jedes Mal neu gemessen wird und sich tatsächlich ändern kann,
sind die **Abstände der Waagen dazu**.

Ein vorbelegtes Feld, das man prüfen soll, ist ein Feld, das niemand prüft.

Kopiert wird nur aus **abgezeichneten** Vorgängern. Ein liegengebliebener Entwurf
kann Zahlen enthalten, die nie jemand geprüft hat — die weiterzureichen hieße,
sie in den nächsten Bericht zu waschen.

### Warum Eingaben *und* Ergebnis gespeichert werden

**Eingaben** sind die gemessenen Werte, **Ergebnis** die daraus gerechneten
Zahlen. Beides liegt in der Datenbank, statt das Ergebnis bei jeder Anzeige neu
zu rechnen — denn das Ergebnis ist das, was unterschrieben wurde. Ändert sich
später der Rechencode, zeigte ein Bericht von 2019 andere Zahlen unter derselben
Unterschrift.

Die Kehrseite ist, dass beides auseinanderlaufen kann. Genau das wird jetzt
gemeldet: `figuresMatchRows()` vergleicht Gespeichertes mit Gerechnetem. Es gibt
nur zwei Ursachen, und beide will man wissen — jemand hat die Zeilen nach der
Unterschrift geändert, oder die Rechnung selbst hat sich geändert. Gemeldet als
Hinweis, nicht als Alarm: **Neu rechnen ist nicht automatisch richtig, die alte
Zahl ist die unterschriebene.**

---

## 12. Dokumente, Fristen und „noch offen"

### Fristen sind ein „kommt drauf an"

die Warnung, und sie ist die Sorte, die man leicht abnickt und dann falsch
baut:

> Manche Luftfahrzeuge brauchen z. B. alle 4 Jahre eine Wägung, andere nur bei
> Bedarf. Das gilt für alle Dokumente und Bauteile.

Also: **Ein Dokument ohne Ablaufdatum läuft nicht ab.** Es ist kein Dokument,
dessen Ablauf jemand vergessen hat. Die Abwesenheit als Versäumnis zu behandeln
füllte die Fälligkeitsliste mit Arbeit, die niemand schuldet — der schnellste
Weg, dass sie niemand mehr liest.

**Die Nachprüfung ist die einzige Ausnahme.** Sie schuldet jedes Luftfahrzeug
immer, also wird ihr Fehlen gemeldet. Bei allem anderen ist Fehlen einfach
Fehlen.

### Das AMP ist ein Dokument

> IHP gibt es nicht mehr, ist inzwischen ein AMP. Das lässt sich als Dokument
> anhängen, sich daraus ergebende Änderungen in Laufzeiten und
> Wartungsintervallen wird anderswo eingepflegt.

Damit ist die Tabelle `maintenance_programmes` **entfernt**. Sie war auf der
Annahme gebaut, ein AMP sei ein Datensatz mit Feldern — Referenz, Genehmigung,
nächste Überprüfung. Ist es nicht. Was daraus folgt, steht an den
Laufzeitgrenzen der Bauteile, dort wo man danach handeln kann, und nicht an einer
zweiten Stelle, die von der ersten abdriftet. Eine ungenutzte Tabelle stehen zu
lassen, die der Fachlichkeit widerspricht, wäre schlechter gewesen als sie zu
löschen.

### „Hier ist noch was offen"

die Formulierung, und eine ehrlichere Sache zu bauen als ein Urteil:
Lufttüchtigkeit beurteilt eine qualifizierte Person mit dem Flugzeug vor sich.
Was Software kann, ist dafür sorgen, dass nichts, was diese Person wissen wollte,
unbemerkt in einer Datenbank liegt.

**Eine leere Liste heißt „nichts gefunden", nicht „lufttüchtig".** Der Bildschirm
sagt das dazu, statt den grünen Haken etwas anderes andeuten zu lassen.

**Zusammensetzbar**, weil die Antwort über Module reicht: Ein Verein nur mit der
Flotte hat Papiere und Grenzen; mit Arbeitskarten kommen offene Befunde dazu, mit
Freigaben eine fehlende CRS. Jedes Modul meldet, was es weiß —
`ContributesOpenItems` —, und eines, das nicht installiert ist, meldet nichts.
Dasselbe Muster wie die Rechte-Registry im Kern.

Innerhalb der Toleranz ist eine Grenze ein **Hinweis**, darüber hinaus
**blockierend**. Beides gleich einzufärben ist, wie eine Liste aufhört gelesen zu
werden.

Ausgebaute **Mindestausrüstung** blockiert — außer sie wurde ersetzt. Ein
Instrument auszubauen, um ein neues einzusetzen, ist nicht dasselbe wie ohne zu
fliegen.

---

## 13. Übernahme (Onboarding)

Hier lag ich zweimal falsch, und die zweite Korrektur ist die interessantere.

**Zuerst richtig:** Ein Bauteil von Hand einzubauen geht nicht. die
Gegenfrage — „Woher kommt das Bauteil, wenn nicht aus dem Lager? Woher soll ich
wissen, dass es legal ist?" — hat keine gute Antwort. Der Alltagsweg bleibt
Wareneingang → Lager → Entnahme.

**Dann falsch eingeordnet:** Ich habe den Fall „Flugzeug kommt schon bestückt" als
*Migration* abgelegt und vertagt. Vorgabe: > Selbst wenn ich ein nagelneues Flugzeug kaufe, sind da schon Bauteile drin. Das
> ist keine Migration in dem Sinne, das ist die Anlage eines neuen Datensatzes.
> Gleiches Thema, wenn der Kunde zum 145-Betrieb kommt. Der Vogel mag seit 60
> Jahren fliegen, ist aber für den Betrieb neu. Das nennt sich Onboarding, nicht
> Migration. Migration wäre es, wenn ich vorher ein anderes System gehabt hätte.

Das ändert die Einordnung grundlegend. Eine Migration passiert **einmal** und darf
ein Skript sein, das man danach wegwirft. Onboarding passiert **immer wieder** —
jedes neue Flugzeug, jeder neue Kunde — und gehört damit ins Produkt.

### Bezeugt oder abgeschrieben

Damit fällt auch meine Begründung anders aus. Beim Onboarding **hat** das Bauteil
eine Herkunft — sie steht auf den Papieren, die mit dem Flugzeug kommen. Der
Unterschied ist nicht *Herkunft ja/nein*, sondern:

| | |
|---|---|
| **aus dem Lager** | Wir haben den Zugang gesehen, die Bescheinigung lag in unserem Regal |
| **bei Übernahme erfasst** | Jemand hat es von fremden Unterlagen abgeschrieben und war beim Einbau nicht dabei |

Beides ist gültig. Nur eines davon ist **unser eigener** Nachweis, und auf die
Frage „woher wissen Sie das" gehört jeweils eine andere Antwort. Deshalb bleibt
die Kennzeichnung dauerhaft am Datensatz — in der Komponentenliste und auf dem
gedruckten Ausrüstungsverzeichnis.

### Die eine Pflichtangabe

**Aus welchem Dokument** — und ohne sie wird abgelehnt. Ein abgeschriebener
Eintrag ist nur so gut wie das Papier dahinter. „Betriebszeitenübersicht des
Vorbetriebs vom 12.03.2019" beantwortet die Prüferfrage, „steht so in unserer
Datenbank" nicht. Ohne diese Pflicht wäre Onboarding genau die Hintertür, die das
Verbot des Handeinbaus verhindern sollte.

### Zwei Fallen, die im Test stehen

- **Das Einbaudatum kommt aus den Unterlagen, nicht von heute.** Heute
  einzutragen würde jede Kalendergrenze am Übernahmetag neu starten — und einer
  fünfzehn Jahre alten Schleppkupplung zwei frische Jahre schenken.
- **Übernahmedatum ≠ „in Betrieb seit".** Ein Segler fliegt seit 1964 und gehört
  uns seit März. Beides ist wahr, und zusammengelegt verlöre man das, was die
  Frage „seit wann sind wir verantwortlich" beantwortet.

---

## 14. Externe Aufträge

Wartung oder Reparatur an einen Fremdbetrieb vergeben. Zwei Dinge machen daraus
einen eigenen Datensatz statt einer Notiz.

### Teile aus fremdem Bestand

Sie kamen nie durch unser Lager, wir haben sie nie ankommen sehen — aber sie
gingen in **unser** Flugzeug, während es unsere Verantwortung war. Das ist eine
**dritte Herkunft**:

| Herkunft | Was sie bedeutet |
|---|---|
| `stock` | Wir haben den Zugang gesehen, die Bescheinigung lag in unserem Regal |
| `onboarding` | Von fremden Unterlagen abgeschrieben, vor unserer Zeit |
| `external` | Von einem Fremdbetrieb verbaut, während unserer Verantwortung |

Der Nachweis ist der **Arbeitsbericht** des Betriebs — und er landet im selben
Feld wie die Quelle einer Übernahme-Zeile. Zwei Konventionen für dieselbe Frage
(„woher wissen Sie das") wären eine zu viel.

### Wer unterschreibt, ist offen — und muss es bleiben

Vorgabe: „Es ist dabei offen, ob ich selbst freigebe oder die Fremdwerft."

| | |
|---|---|
| **Der Betrieb** | Seine Unterschrift, seine Betriebsnummer, seine Verantwortung. Wir schreiben auf, was auf dem Papier steht |
| **Wir** | Jemand hier nimmt Arbeiten ab, bei denen er **nicht dabei war** — auf Grundlage eines Berichts. Das ist eine Feststellung: Qualifikation nötig, Credential eingefroren |

Die zweite Variante ist die, nach der ein Prüfer zuerst fragt. Ein Datensatz, der
beide nicht auseinanderhalten kann, beantwortet genau diese Frage nicht.

Die Prüfung läuft über `Authority` wie überall sonst — dadurch funktioniert auch
der **Pilot-Owner-Fall** ohne eine Zeile Zusatzcode: Wer im AMP dieses
Luftfahrzeugs steht, darf abnehmen; für ein anderes nicht.

### Zurück ≠ freigegeben

Zwei getrennte Einträge, und die Lücke dazwischen ist gewollt sichtbar. Das
Flugzeug steht in der Halle und sieht fertig aus — **genau dann** wird es
geflogen, weil es ja „wieder da" ist. Solange die Freigabe fehlt, meldet die
Airworthiness-Prüfung das.

Ein Auftrag, der noch **läuft**, wird dort *nicht* gemeldet: Das Flugzeug ist
woanders, und niemand ist im Begriff, es zu fliegen. Das wochenlang auf jeder
Liste zu haben wäre Rauschen.

### Grenze zu den Arbeitskarten

Angemerkt wurde, und die Linie ist: **Hier steht das Ereignis am
Luftfahrzeug** — wer hatte es, was kam zurück, wer hat unterschrieben. Die
Aufgaben, Befunde und Stunden gehören zur Arbeitskarte. Kommt das Modul, kann ein
Auftrag eine Karte referenzieren; die Lebenslauf-Seite steht ohnehin für sich,
denn sie muss auch dann sagen, was mit dem Flugzeug passiert ist, wenn niemand
Karten geschrieben hat.

---

## 15. Was noch fehlt

*Stand 2026-08-02. Die beiden Punkte, die hier standen — Arbeitskarten und
LTA/TM als eigene Module — sind gebaut. Das Abhaken einer Wartung läuft
seither über die Arbeitskarte statt über einen Haken.*

- **Nichts modulintern offen.** Was die Flotte betrifft, liegt in den
  Querschnittsthemen: AuthZ-Negativtests je Filament-Resource und die
  Retention-Jobs, beide in [`INFRASTRUKTUR.md`](INFRASTRUKTUR.md).

---

## Kennblattnummern: ein Muster, mehrere Nummern

### Warum eine Spalte nicht reichte

`aircraft_types.type_certificate` hielt **eine** Nummer. Dasselbe Flugzeug steht
aber bei verschiedenen Behörden unter verschiedenen Nummern, und die Behörden
zitieren einander. Das Blaue Buch des LBA druckt beide in einer Zeile:

```
339/SP   ASK 21   Schleicher   ASK 21   6 (2/90)   EASA.A.221
^^^^^^                                             ^^^^^^^^^^
deutsches Kennblatt                                EASA-TCDS
```

Die NfL nennt für ein europäisch zugelassenes Muster die EASA-Referenz, für ein
Annex-I-Muster das nationale Kennblatt — und ältere Ausgaben nennen das
Kennblatt auch für Muster, die heute ein TCDS haben.

Folge: Ein Verein, der die EASA-Nummer übernommen hatte, sah **keine nationalen**
Anweisungen für dieses Muster; einer, der das Kennblatt übernommen hatte, sah
keine europäischen. Beide bekamen keinen Fehler — nur eine kürzere Liste. Genau
die Fehlerform, die dieses Modul verhindern soll.

### Welche Nummer führt

die Vorgabe, die den Ausschlag gibt:

> Ein Lfz, das ursprünglich nach nationalem Recht zugelassen wurde und später
> eine Änderung erhalten hat, hat initial ein LBA-Kennblatt erhalten und danach
> ein EASA-TCDS. **Wenn beides oder nur ein TCDS angegeben sind, zählt immer das
> TCDS.** Nur wenn keines vorhanden ist, zählt das alte LBA-Kennblatt.

Die zwei Nummern einer Blaubuch-Zeile sind also **keine Gleichrangigen**. Beim
Übernehmen wird deshalb nicht die Nummer gespeichert, unter der gesucht wurde,
sondern die, die gilt — sonst hinge die führende Nummer davon ab, welchen
Katalog jemand zufällig geöffnet hat.

Das alte Kennblatt bleibt trotzdem erhalten, als **Nebennummer**: es kostet eine
Zeile und ist das, was eine Veröffentlichung mit der alten Nummer überhaupt
noch treffen lässt.

### Aufbau

- `aircraft_types.type_certificate` bleibt die **führende** Nummer. Jede
  Oberfläche, jeder Export und das Audit-Log beziehen sich darauf, und eine
  Anweisungsliste liest man unter der Nummer, die ein Mensch zitiert.
- `aircraft_type_certificates` führt **alle** Nummern, die führende mit
  `is_primary` markiert. Relational und nicht als zweite Spalte oder JSON:
  ein Muster kann mehr als zwei tragen (EASA plus national plus `UK.TC` nach
  dem Brexit), und die Leitplanke sagt „lieber sauber relationale Spalten".
- Der Spiegel wird beim Speichern des Musters nachgezogen (`AircraftType::booted`),
  nicht an jeder Aufrufstelle. Die Aufrufstellen sind das Problem: das
  Filament-Formular, die Übernahme-Aktion und ein künftiger Import schreiben
  alle diese Spalte, und jede müsste daran denken.
- `TypeLookup::byCertificate` trifft auf **jede** Nummer eines Musters. Die
  Spalte wird zusätzlich abgefragt — für den Fall, dass eine Zeile von Hand
  gelöscht wurde oder ein Test die Spalte direkt schreibt.

### Von Hand pflegbar

Im Musterformular gibt es „Weitere Kennblattnummern". Nicht jede Nummer steht in
einem Katalog, den dieses System abfragen kann, und ein Verein kennt sein
eigenes Muster besser als jede Suche. Die führende Nummer steht dort bewusst
**nicht** noch einmal — sie an zwei Stellen ändern zu können, lädt dazu ein, sie
verschieden zu ändern.

### Was dabei aufgeräumt wurde

Das Blaue Buch transportierte die EASA-Referenz bisher im Feld `dataSheetUrl`,
mit dem Kommentar, ein eigenes Feld lohne sich für die eine Behörde nicht, die
es füllt. Das stimmte, solange ein Muster genau eine Nummer halten konnte. Jetzt
heisst das Feld `alsoFiledAs` und trägt, was es ist: eine Kennblattnummer. Ein
URL-Feld, in dem eine Zertifikatsnummer steht, wird früher oder später als URL
gelesen.
