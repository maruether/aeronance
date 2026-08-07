# Erfahrungslogbuch (Part-66)

**Das war die ursprüngliche Anforderung.**

> Ich habe ein halbfertiges Lagertool und will einen besseren Weg, mein
> Part-66-Log zu führen.

Lager, Flotte und Arbeitskarten sind das Gerüst, das sich als nötig
herausgestellt hat, um dieses Logbuch sauber ableiten zu können. Deshalb ist es
das **kleinste Modul im Projekt**: keine Tabellen, keine Schreibvorgänge. Die
Arbeit ist vorher passiert — dadurch, dass die Part-66-Felder auf der *allerersten*
Arbeitskarte standen und nicht nachträglich ergänzt wurden, als es schon ein Jahr
Karten ohne sie gab.

---

## 1. Es liest nur

CLAUDE.md: *„Das Erfahrungslogbuch ist eine Auswertung, keine Extra-Pflege."*

Ein gespeichertes Logbuch wäre eine zweite Kopie dessen, was die Karten schon
sagen — und beim ersten Auseinanderlaufen wüsste niemand, welche gilt. Abgeleitet
heißt: Die Antwort sind immer die Karten.

**Das funktioniert nur wegen des Einfrierens.** Nach der Freigabe sind Karten und
Zeiten unveränderlich, also kann sich eine abgeleitete Zeile nicht mehr unter
jemandem wegdrehen. Ohne diese Sperre wäre ein berechnetes Logbuch eines, das sich
still selbst umschreibt — schlimmer als keins.

Ein Test prüft ausdrücklich, dass dieses Modul **keine eigenen Tabellen** hat.

### Vorläufig vs. festgeschrieben

| | |
|---|---|
| Vorgang **nicht** freigegeben | Zeile ist als **vorläufig** markiert — die Karte kann sich noch ändern |
| Vorgang freigegeben | Zeile trägt die Freigabe-Nummer und ist so fest wie das Zertifikat |

Das steht auch auf dem Ausdruck. Wer eine Lizenzunterlage zusammenstellt, soll
sehen, welche Zahlen noch wandern können.

---

## 2. Was eine Zeile enthält

Genau die Felder, die CLAUDE.md für die erste Karte gefordert hat: Datum,
Kennzeichen, Muster, ATA-Kapitel, Tätigkeitsart, Dauer, ausgeführt/unterstützt,
freigebende Person. Dazu die ausgeführte Arbeit und die Freigabe-Nummer.

**Zwei Personen an einer Karte ergeben zwei Zeilen** — 66.A.20(b) zählt, was
jemand *getan* hat, und unterstützt ist ein anderer Eintrag als ausgeführt.

**Abzeichnen ist ein eigener Nachweis.** Wer ein Jahr lang die Arbeit anderer
prüft, hat Erfahrung, die kein Stundeneintrag erfasst — deshalb werden
abgezeichnete Karten und erteilte Freigaben getrennt gezählt.

---

## 3. Aktualität — Zahlen, kein Urteil

Das ist die wichtigste Entscheidung in diesem Modul.

66.A.20(b)(1) verlangt sechs Monate Instandhaltungserfahrung in den
vorangegangenen zwei Jahren. Bei einem Angestellten ist das klar. Bei einem
Ehrenamtlichen, der drei Samstage im Monat arbeitet, **ist es nicht klar**: sechs
Monate *wovon*? Kalendermonate, in denen etwas passiert ist? Anwesenheitstage?
Stunden auf einen Arbeitsmonat umgerechnet?

Die Vorschrift entscheidet das für den Vereinsfall nicht — und eine hier
erfundene Zahl wäre **schlimmer als keine**, weil sich jemand darauf verlassen
würde.

Also zählt das Modul, was die Daten sagen:

| | |
|---|---|
| **Tage** mit Arbeit | drei Karten an einem Samstag sind *ein* Tag |
| **Monate** mit Arbeit | die Zahl, die dem Wortlaut am nächsten kommt — und genau darum nur angeboten |
| **Stunden** | Summe |
| **Lücke** | Tage seit dem letzten Eintrag |
| abgezeichnete Karten, erteilte Freigaben | getrennt |

Die **Lücke** ist die Zahl, die man eigentlich sucht: Ein Lizenzinhaber, der seit
vierzehn Monaten kein Flugzeug angefasst hat, hat ein Problem im Anmarsch — und
eine Stundensumme über zwei Jahre zeigt das nicht.

### Der ARS-Zähler

CLAUDE.md nannte in zwei Zeilen einen „Lizenz-/ARS-Zähler, abgeleitet aus den
Arbeitskarten" — ohne weitere Erläuterung. die Lesart: **Anzahl der ARCs und
die Stunden dahinter, in den letzten zwei Jahren, als Übersicht für
Part-66-Inhaber.** ARS heißt Airworthiness Review Staff.

Damit zählt das Modul drei verschiedene Akte getrennt, und die Trennung ist der
Punkt:

| | |
|---|---|
| **Stunden** | an einem Luftfahrzeug gearbeitet |
| **Abgezeichnete Karten / Freigaben** | fremde oder eigene Arbeit freigegeben |
| **Lufttüchtigkeitsprüfungen** | ein Luftfahrzeug geprüft — und nur das erhält eine ARS-Berechtigung |

Die Daten lagen bereits: `IssueAirworthinessReview` schreibt jede ARC-Ausstellung
mit Person, Datum und eingefrorenem Namen. Der Zähler ist eine Abfrage, keine neue
Tabelle — wie das ganze Modul.

**Eine abgelaufene ARC zählt weiter.** Dieselbe Begründung wie bei ersetzten
Freigaben: Es ist ein Erfahrungs-, kein Gültigkeitsnachweis. Eine Prüfung, die
jemand vor achtzehn Monaten durchgeführt hat, wurde durchgeführt.

Auf dem Ausdruck stehen die ARCs **einzeln mit Kennzeichen und Datum**, nicht nur
als Zahl — eine Übersicht ist damit brauchbarer.

**Und wieder kein Urteil:** Welche Zahl eine ARS-Berechtigung erhält, entscheidet
das Werkzeug nicht. Dieselbe Zurückhaltung wie bei der Aktualität, aus demselben
Grund.

Nebenbei deklariert das Modul jetzt `requires: ['taskcards', 'fleet']`. Die Flotte
kam bisher nur mittelbar über die Arbeitskarten mit — sobald hier direkt aus
`airworthiness_reviews` gelesen wird, gehört sie ins Manifest, unabhängig davon,
dass etwas anderes sie ohnehin hereinzieht.

Dazu **Beobachtungen statt Beurteilungen**. Jede sagt etwas, das jemand bemerken
würde; keine sagt, ob die Lizenz in Ordnung ist. Dasselbe Prinzip wie bei der
Lufttüchtigkeitsprüfung: Das Werkzeug legt offen, was niemand suchen sollte, und
gibt sich nicht als Urteil aus, das es nicht fällen kann.

---

## 4. Wer wessen Logbuch sieht

Ungewöhnlich für dieses Projekt und mit Absicht:

**Das eigene Logbuch braucht keine Berechtigung.** Es ist die Aufzeichnung, wie
jemand seine eigenen Samstage verbracht hat — dafür freigeschaltet werden zu
müssen wäre absurd.

**Fremde brauchen eine.** Ein Erfahrungsnachweis sind personenbezogene Daten. Der
Werkstattleiter, der jemandes Erfahrung bestätigen muss, ist ein anderer Fall als
das neugierige Mitglied.

Auf dem Bildschirm fällt ein manipuliertes Personen-Feld auf **das eigene
Logbuch zurück**, nicht auf einen Fehler: sicher, weniger verwirrend, und es kann
nie ein fremdes zeigen.

### Die Werkstattleitung bekommt es voreingestellt

die Vorgabe — und dafür fehlte der Mechanismus komplett: `PermissionDefinition`
kannte nur Name und Gruppe, und `AccessSetup` vergibt bewusst nichts an bestehende
Rollen („eine Werkstatt, die ihre Rollen angepasst hat, behält das").

Das sieht nach einem Widerspruch aus und ist keiner: **Vergeben wird nur an
Berechtigungen, die gerade erst entstanden sind.** Über eine Berechtigung, die vor
einem Augenblick noch nicht existierte, kann niemand eine Meinung gehabt haben —
es gibt also keine Anpassung zu bewahren. Unangetastet bleibt alles, was schon da
war, und genau darum geht es bei der Regel: um den Fall, in dem eine Installation
absichtlich etwas weggenommen hat. Ein Test prüft, dass ein bewusster Entzug einen
zweiten Lauf übersteht.

Ohne das wäre die Voreinstellung praktisch wertlos: Rollen entstehen beim Setup,
Module werden Monate später aktiviert — eine Vorgabe, die nur bei gleichzeitigem
Ablauf greift, greift bei fast niemandem.

Vergeben wird beim **Aktivieren des Moduls** und im Audit-Trail vermerkt. Zu
erweitern, was eine Rolle darf, ist eine Zeile im Protokoll wert, auch wenn es
gewollt ist — wer in zwei Jahren hinschaut, soll sehen können, woher eine
Berechtigung kam, ohne die Release Notes zu lesen.

---

## 5. Der Ausdruck

Das eigentliche Ergebnis der ursprünglichen Anforderung: ein Blatt, das man einer
Behörde vorlegen oder abheften kann. A4 quer, mit Name, Lizenznummer, Zeitraum,
allen Zeilen, den Summen nach Tätigkeit und Muster, den Aktualitätszahlen samt
Vorbehalt — und Unterschriftsfeldern für die Person und die Werkstattleitung.

Gedruckt statt als Tabelle exportiert, weil eine Lizenzunterlage ein Dokument mit
einem Namen darauf ist.

---

## 6. Was noch offen ist

- *(erledigt: der ARS-Zähler, siehe Abschnitt 3)*
- **Bestätigter Auszug.** Ein zu einem Zeitpunkt unterschriebener Stand wäre
  denkbar. Bewusst *nicht* gebaut: Für freigegebene Arbeit ist die Ableitung schon
  unveränderlich, ein Schnappschuss wäre eine zweite Wahrheit. Erst bauen, wenn
  eine Behörde es tatsächlich so verlangt
