# Abrechnungsmodul — Entwurf

**Stand:** 2026-10-30 · **Status: entschieden am 2026-10-30, im Bau für 0.3.0.**
Die Antworten auf die Fragen aus Abschnitt 12 stehen in Abschnitt 14.
Anlass (Marvin, 2026-10-30): *„die Werften hier möchten ein Abrechnungsmodul
haben. Also Kundenstammdaten, Rechnungsstellung, VK-Preise an Teilen, Rabatte
(mit der Auswahl ob der Kunde sie sieht oder nicht), und was dir noch so
Sinnvolles einfällt."*

Dieses Dokument ist der Plan vor dem Bau. Es legt fest, was das Modul ist,
was es ausdrücklich nicht ist, wo es andockt, und welche Entscheidungen
vorher fallen müssen. Die offenen Fragen stehen in Abschnitt 12.

---

## 1. Was das Modul ist — und warum es E6 nicht bricht

E6 sagt: *Lagerhaltung, keine Warenwirtschaft.* Rechnungen und Preise
stehen dort in der Spalte „Draußen". Das bleibt so. E6 ist eine Aussage über
das **Lagermodul**, nicht über das Produkt — der Satz dazu lautet: *„Der
Preis … wäre ein Zusatzmodul"* und *„Wenn ein Verein später doch Beschaffung
will, ist das ein eigenes Modul, das am Lager andockt."* Das Abrechnungsmodul
ist genau dieses Zusatzmodul, mit einer Präzisierung: **Es dockt nicht am
Lager an, sondern an den Arbeitskarten.**

Der Grund ist fachlich. Abgerechnet wird nicht „ein Teil", sondern **ein
Vorgang**: die Arbeit an einem Luftfahrzeug, mit Arbeitszeit, den dabei
verbauten Teilen und gegebenenfalls Fremdleistung. Das alles steht schon in
den Arbeitskarten — Zeiten je Karte (`TaskCardTime`, Minuten,
Tätigkeitsart), Teile je Karte (eingetragen beim Entnehmen aus dem Lager,
mit Teilenummer, Los und Menge als Kopie), Fremdvergabe über die Flotte.
Die Rechnung ist eine **Auswertung des freigegebenen Vorgangs**, so wie das
Erfahrungslogbuch eine Auswertung der Karten ist.

Daraus folgt die Grenze:

| Im Abrechnungsmodul | Bleibt, wo es ist |
|---|---|
| Kunden, Preislisten, Stundensätze, Rabatte | Lieferant als Stammdatum (Lager) |
| Rechnung, Gutschrift/Storno, Zahlungsstatus | Teile, Lose, Bewegungen (Lager) — ohne Preis |
| Verkaufspreis je Teilenummer | Einkaufspreis — gibt es weiterhin nicht (E6) |
| Export für die Buchhaltung | Buchhaltung selbst — gibt es nicht |

**Das Lager erfährt nichts von Preisen.** Der Verkaufspreis lebt in einer
Preisliste des Abrechnungsmoduls, geschlüsselt über die Teilenummer. Das ist
dieselbe Naht, über die die Karte das Teil kennt: Die Teileentnahme kopiert
Teilenummer und Bezeichnung auf die Karte, die Abrechnung liest sie von dort.

---

## 2. Wen das Modul bedient

Zwei Betreiber mit verschiedenen Bedürfnissen, dasselbe Modul:

- **Die Werft** (Part-145 / CAO, Unternehmen): stellt Kunden Rechnungen mit
  Umsatzsteuer, braucht Pflichtangaben nach §14 UStG, lückenlose Nummern,
  Unveränderbarkeit, E-Rechnung ab 2027/2028.
- **Der Verein**: verrechnet Mitgliedern Teile zum Selbstkostenpreis oder
  stellt dem Halter eines fremden Flugzeugs die Werkstattstunden in Rechnung.
  Meist Kleinunternehmer nach §19 UStG oder im Zweckbetrieb — die Rechnung
  sieht anders aus (kein Steuerausweis, Hinweis statt Steuersatz).

Der Unterschied ist **Konfiguration**, nicht Code: Die steuerliche
Behandlung ist eine Einstellung der Organisation mit Überschreibung je Kunde
und je Rechnung (Abschnitt 6).

---

## 3. Das Datenmodell in einem Bild

```
customer ──────────────< invoice >──────────── work_order (TaskCards, Referenz)
   │  (Kunde)              │  (Rechnung)              │
   │                       │                          │
   │  default_discount     ├──< invoice_line          ├── task_card_time (Minuten)
   │  discount_visible     │     (Position: Snapshot) ├── task_card_part (Teil, Menge)
   │  tax_treatment        │                          └── external_work_order (Flotte)
   │  payment_terms_days   ├──< payment
   │  holder_id (Flotte,   │     (Zahlungseingang)
   │   optional)           │
   │  user_id (Kern,       └── corrects_invoice_id   (Storno/Gutschrift zeigt
   │   optional: Mitglied)                             auf das Original)

price_list ──< part_price      (Teilenummer → VK netto, gültig ab)
           ──< labour_rate     (Tätigkeitsart → €/h, gültig ab)

number_range                   (Nummernkreis: Präfix, Jahr, Zähler)
```

Keine Fremdschlüssel in fremde Tabellen — mit der einen Ausnahme, die es in
den Arbeitskarten schon gibt: ein Verweis auf das Luftfahrzeug bzw. den
Vorgang als Zahl, ohne Constraint, wie `taskcards.work_orders.aircraft_id`.
Was die Rechnung aus dem Vorgang braucht, wird beim Anlegen **kopiert**
(E7-Logik: Bescheinigungsinhalt ist Snapshot, nie Verweis). Eine Rechnung
bleibt lesbar, auch wenn die Arbeitskarten deaktiviert sind.

### Die Position ist ein Snapshot

Jede `invoice_line` trägt vollständig: Beschreibung, Menge, Einheit,
**Listenpreis**, **Rabatt** (Prozent oder Betrag), **Rabatt sichtbar
ja/nein**, Nettopreis, Steuersatz, Herkunft (`labour` / `part` / `external`
/ `manual`) und einen Verweis auf die Quelle (Karte, Zeiteintrag, Teil).
Ändert sich später die Preisliste, ändert sich keine gestellte Rechnung.

**Rabatt sichtbar oder nicht** heißt auf dem Dokument:

- sichtbar: Listenpreis, Rabattzeile, Netto — der Kunde sieht, was er spart.
- unsichtbar: nur der rabattierte Einzelpreis. Der Listenpreis steht trotzdem
  in der Datenbank; wer die Rechnung im System öffnet, sieht beides. Nichts
  wird vergessen, nur nicht gedruckt.

Vorbelegung vom Kunden (Standardrabatt, Sichtbarkeit), je Position
überschreibbar. Dazu ein Gesamtrabatt je Rechnung mit derselben Wahl.

---

## 4. Der Lebenslauf einer Rechnung

```
Entwurf ──(stellen)──> Gestellt ──(Zahlung erfassen)──> Bezahlt
   │                      │
   │ frei bearbeitbar     │ EINGEFROREN. Nummer vergeben, Dokument archiviert.
   │ keine Nummer         │
   │                      └──(stornieren)──> Storniert, per Stornorechnung
   └──(verwerfen)──> weg                     (neues Dokument, eigene Nummer,
                                              negative Beträge, zeigt auf das
                                              Original). Teilgutschrift ebenso.
```

Das ist dieselbe Regel wie bei der Freigabe: **Korrektur nur als neues,
referenzierendes Dokument, nie durch Editieren.** GoBD verlangt genau das;
das Modell verweigert `update` auf einer gestellten Rechnung technisch, wie
`Activity` es im Audit-Trail tut.

**Die Nummer entsteht beim Stellen**, nicht beim Anlegen. Entwürfe haben
keine; so bleibt der Nummernkreis lückenlos, auch wenn ein Entwurf verworfen
wird. Vergabe in einer Transaktion mit Sperre auf dem Zähler — zwei
gleichzeitige „Stellen" dürfen nicht dieselbe Nummer bekommen.

**Rechnung aus Vorgang** ist der Normalweg: Vorgang freigegeben → „Rechnung
anlegen" → das Modul zieht Zeiten (nach Tätigkeitsart × Stundensatz, auf
Viertelstunden gerundet, konfigurierbar), Teile (Menge × Preisliste) und
Fremdleistung (Betrag mit optionalem Aufschlag) als Positionen. Fehlt ein
Preis in der Liste, entsteht die Position mit Preis 0 und einer Markierung,
die das Stellen blockiert, bis jemand sie ausfüllt — eine vergessene
Position ist Geld, das keiner wiederholt.

Rechnung **ohne** Vorgang (freie Positionen) ist ebenfalls möglich; eine
Werft verkauft auch mal ein Teil über den Tresen.

---

## 5. Pflichtangaben und Form

Eine gestellte Rechnung trägt alles, was §14 Abs. 4 UStG verlangt:

| Angabe | Quelle |
|---|---|
| Name und Anschrift des Ausstellers | Organisationseinstellungen (neu: Adresse, Rechtsform, Register, Geschäftsführung — Pflicht auf Geschäftsbriefen) |
| Steuernummer oder USt-IdNr. | Organisationseinstellungen |
| Name und Anschrift des Empfängers | Kunde, als Snapshot auf der Rechnung |
| Rechnungsdatum, fortlaufende Nummer | beim Stellen |
| Menge und Art der Leistung | Positionen |
| Leistungszeitpunkt bzw. -zeitraum | aus dem Vorgang (Freigabedatum) oder von Hand |
| Entgelt nach Steuersätzen, Steuersatz, Steuerbetrag | berechnet |
| Hinweis bei §19 (Kleinunternehmer), §13b (Reverse Charge), §4 Nr. 2 i. V. m. §8 (steuerfreie Luftfahrt-Umsätze) | aus der steuerlichen Behandlung |
| Bankverbindung, Zahlungsziel | Organisationseinstellungen, Kunde |

**Dokument:** Gerendert wie die Bescheinigungen heute (HTML mit
Druck-Layout), zusätzlich als **PDF archiviert** im Moment des Stellens —
über medialibrary an der Rechnung, unveränderlich. Dafür braucht es einen
PDF-Erzeuger; Vorschlag `dompdf/dompdf` (reines PHP, keine neue
Systemabhängigkeit in den drei Kanälen). Das PDF ist auch das, was per Mail
an den Kunden geht (Mailversand existiert im Kern).

**E-Rechnung:** Für inländische B2B-Umsätze ist die elektronische Rechnung
(XRechnung oder ZUGFeRD) ab 2027 Pflicht für Aussteller über 800.000 € Umsatz,
ab 2028 für alle. Das Datenmodell enthält von Anfang an alles, was die
EN 16931 verlangt (Leitweg-ID für öffentliche Auftraggeber, Käuferreferenz,
Positionsdaten strukturiert). Die Erzeugung selbst ist Phase 2 — sie ist
ein Ausgabeformat, keine Datenfrage.

**Aufbewahrung:** Rechnungen sind Buchungsbelege. Die Frist ist mit dem
Bürokratieentlastungsgesetz IV 2025 von zehn auf **acht Jahre** gesenkt
worden; die Retention-Klasse `invoices` bekommt diesen Wert und ist, wie die
anderen, einzeln schaltbar. *(Vor dem Bau mit dem Steuerberater der Werft
bestätigen — siehe Frage 1.)* Die bestehende Regel „nichts hart löschen"
gilt ohnehin.

---

## 6. Steuerliche Behandlung als Einstellung

Dreistufig, jede Stufe überschreibt die vorige:

1. **Organisation:** Regelbesteuerung oder Kleinunternehmer §19; Standard-
   steuersatz (19 %); Steuernummer/USt-IdNr.
2. **Kunde:** Inland / EU-Unternehmer mit USt-IdNr. (Reverse Charge) /
   Drittland / Luftfahrtunternehmen mit Steuerbefreiung §8 UStG.
3. **Rechnung bzw. Position:** Steuersatz je Position (19 %, 7 %, 0 % mit
   Grund).

Der Rechnungstext für den jeweiligen Fall kommt aus Sprachdateien, nicht aus
Freitext — damit der Hinweis stimmt und nicht jemand „Umsatzsteuer entfällt"
tippt, ohne den Paragraphen zu nennen.

---

## 7. Kunden

`customer`: Name, Rechnungsadresse, Land, USt-IdNr., Ansprechpartner,
E-Mail für Rechnungsversand, Zahlungsziel in Tagen, Standardrabatt und
dessen Sichtbarkeit, steuerliche Behandlung, Notiz. Optional verknüpft:

- mit einem **Halter** der Flotte (`holder_id`): Die Werft rechnet mit dem
  Halter des Luftfahrzeugs ab. Beim Anlegen einer Rechnung aus einem Vorgang
  wird der Kunde vorgeschlagen, der am Halter des Luftfahrzeugs hängt.
- mit einem **Benutzer** (`user_id`): Der Verein verrechnet Mitgliedern.
  Pseudonymisierung ausgetretener Mitglieder (E3) lässt die Kundenstammdaten
  **unangetastet** — eine Rechnung muss ihren Empfänger acht Jahre lang
  nennen. Das ist derselbe Vorrang wie bei E3a.

Kunden werden nicht gelöscht, sondern inaktiv gesetzt.

---

## 8. Preise

- **Teilepreise** (`part_price`): Teilenummer → Nettopreis, Einheit, gültig
  ab. Mehrere Einträge je Teilenummer mit verschiedenen Gültigkeiten ergeben
  die Historie. Es gilt der jüngste Eintrag vor dem Leistungsdatum.
  Geschlüsselt über die **Teilenummer**, nicht über eine ID des Lagers: Das
  Lager kennt keine Preise, und eine Teilenummer ist die Sprache, in der beide
  Seiten dasselbe meinen. Ein Lager-Bauteiltyp ohne Teilenummer (Normteile mit
  Bezeichnung) wird über die Bezeichnung geschlüsselt.
- **Stundensätze** (`labour_rate`): je Tätigkeitsart (die Karten kennen
  bereits die Art der Arbeit) → €/h, gültig ab. Frage 4 klärt, ob das reicht.
- **Kundenspezifische Preise:** in Phase 1 über den Standardrabatt des
  Kunden. Eigene Preislisten je Kunde erst, wenn eine Werft sie braucht.
- **Aufschlag auf Fremdleistung:** Prozent, Vorbelegung in der Organisation.

Import per CSV (Teilenummer; Preis), weil eine Werft ihre Liste schon hat.

---

## 9. Rechte, Rollen, Demo

| Recht | Wer darf |
|---|---|
| `billing.view` | Rechnungen und Kunden lesen |
| `billing.manage` | Kunden, Preise, Entwürfe anlegen und ändern |
| `billing.issue` | Rechnung stellen, stornieren, Gutschrift — der Schritt, der eine Nummer verbraucht |
| `billing.payments` | Zahlungseingänge erfassen |

Standardvergabe: `workshop_manager` alles; `admin` nichts davon (wie überall:
Administration ist keine Fachrolle). Eine Werft legt sich eine Rolle
„Buchhaltung" an; die Rollenverwaltung kann das.

Der Rechte-Negativtest (`PanelDenyByDefaultTest`) verlangt für jede neue
Resource eine Regel — das Modul kommt also mit seiner Abdeckung.

**Demomodus:** drei Beispielkunden, eine gestellte und eine offene Rechnung;
kein Mailversand (gilt ohnehin). Preise sind Beispielwerte.

**Modulabhängigkeit:** `requires: ['taskcards']` (und damit `fleet`).
`warehouse` ist nicht nötig: Teile kommen über die Karte, und eine Werft
ohne Lagermodul trägt sie dort von Hand ein.

---

## 10. Was ich zusätzlich vorschlage

Nach Nutzen sortiert. Die ersten drei gehören in die erste Fassung, der Rest
ist Phase 2 und 3.

1. **Zahlungsstatus.** Zahlungsziel, „bezahlt am", Teilzahlungen, eine Liste
   „offen und überfällig" auf der Startseite. Ohne das ist die Rechnung
   geschrieben und vergessen.
2. **Export für die Buchhaltung.** CSV der gestellten Rechnungen je Zeitraum
   (Nummer, Datum, Kunde, netto, Steuer, brutto, Steuersatz) — der
   Steuerberater der Werft will genau das, und es sind zwanzig Zeilen Code.
   DATEV-Format erst auf Nachfrage.
3. **Rechnung per Mail versenden**, PDF im Anhang, mit Versandvermerk an der
   Rechnung. Der Mailweg existiert.
4. **Angebot / Kostenvoranschlag** (Phase 2): dieselben Positionen, eigener
   Nummernkreis, wird zum Vorgang und später zur Rechnung. Werften arbeiten
   so; der Verein braucht es nicht.
5. **E-Rechnung** XRechnung/ZUGFeRD (Phase 2, siehe Abschnitt 5).
6. **Sammelrechnung** je Kunde und Monat über mehrere Vorgänge, und
   **Abschlagsrechnung** bei langen Arbeiten (Phase 2).
7. **Mahnwesen** (Phase 3): Zahlungserinnerung, Mahnstufen, Mahngebühr.
   Erst, wenn jemand es braucht — jede Stufe ist ein eigenes Dokument.
8. **Arbeitsbericht als Anlage:** Die Rechnung verweist auf den Ausdruck des
   freigegebenen Vorgangs (gibt es schon). Kein neues Dokument, nur ein Link
   und die Vorgangsnummer auf der Rechnung.

Bewusst **nicht** vorgesehen: Lieferscheine, Bestellwesen, Einkaufspreise,
Lagerbewertung, Kassenbuch — alles Warenwirtschaft oder Buchhaltung, alles
E6.

---

## 11. Bauplan für die erste Fassung

1. Modulgerüst, Manifest, Rechte, Glossar-Einträge (Abschnitt 13).
2. Organisationseinstellungen erweitern (Adresse, Steuer, Bank, Register).
3. Kunden, Nummernkreise, Preislisten mit CSV-Import.
4. Rechnung: Modell mit Einfrieren, Positionen, Anlegen aus Vorgang,
   Stellen mit Nummernvergabe, Storno/Gutschrift.
5. Dokument: Druck-Layout, PDF-Archiv beim Stellen, Mailversand.
6. Zahlungen, Übersicht „offen/überfällig", CSV-Export.
7. Demo-Bestand, Handbuch-Kapitel, Rechte-Regeln im Negativtest.

Ein neues Modul ist nach SemVer ein Minor-Schritt. Ob das 0.2.4 oder 0.3.0
heißt, entscheidet Marvin beim Schneiden (CHANGELOG, „Vorgemerkt").

---

## 12. Offene Fragen — vor dem Bau zu klären

1. **Steuerlicher Rahmen der Werften.** Regelbesteuerung mit 19 %? Kommt die
   Steuerbefreiung für Luftfahrtunternehmen (§4 Nr. 2, §8 UStG) bei deren
   Kunden vor? Gilt die 8-Jahres-Frist nach Auskunft des Steuerberaters?
   *Ich rate hier nicht; das Modul baut die Fälle, die vorkommen.*
2. **Nummernkreis.** Format (z. B. `RE-2026-0001`), je Jahr neu beginnend?
   Getrennte Kreise für Rechnung, Gutschrift, Angebot?
3. **PDF.** `dompdf` als neue Composer-Abhängigkeit in Ordnung? Alternative:
   nur Browser-Druck wie bisher, dann gibt es kein archiviertes PDF.
4. **Stundensätze** je Tätigkeitsart (Mechanik, Prüfung, Avionik …) oder je
   Person? Rundung auf Viertelstunden?
5. **Verein als Nutzer.** Soll die Akaflieg das Modul auch nutzen
   (Mitgliederverrechnung)? Dann kommt die Verknüpfung Kunde ↔ Benutzer in
   die erste Fassung, sonst später.
6. **Rabatt verborgen.** Zeigt die Rechnung dann den rabattierten
   Einzelpreis (mein Vorschlag) oder nur den Gesamtbetrag der Position?
7. **E-Rechnung** schon in der ersten Fassung oder Phase 2?
8. **Zahlungen.** Reicht manuelles „bezahlt am" mit Betrag, oder soll ein
   Kontoabgleich (CAMT-Import) kommen? Mein Vorschlag: manuell.
9. **Mehrere Werften, eine Instanz?** Nein nach Leitplanke (eine Instanz =
   eine Organisation). Falls die Werften einen gemeinsamen Betrieb
   bilden, ist das eine Organisation; sonst je eine Instanz. Nur zur
   Bestätigung.

---

## 13. Glossar-Einträge (zur Übernahme nach Entscheidung)

| Deutsch (UI) | Englisch (Code/DB) |
|---|---|
| Abrechnung (Modul) | `billing` |
| Kunde | `customer` |
| Rechnung | `invoice` |
| Rechnungsposition | `invoice_line` |
| Gutschrift / Stornorechnung | `credit_note` (Rechnung mit `corrects_invoice_id`) |
| Nummernkreis | `number_range` |
| Preisliste | `price_list` |
| Verkaufspreis | `part_price` |
| Stundensatz | `labour_rate` |
| Rabatt | `discount` |
| Rabatt sichtbar | `discount_visible` |
| Zahlungseingang | `payment` |
| Zahlungsziel | `payment_terms_days` |
| Steuerliche Behandlung | `tax_treatment` |
| Angebot | `quote` (Phase 2) |

---

## 14. Entscheidungen vom 2026-10-30 (Marvin)

1. **Steuer:** Regelbesteuerung, **19 % als Vorgabe**. Vereine holen sich die
   Vorsteuer zurück, eine Befreiung gibt es beim Kraftstoff — beides berührt
   die Werkstattrechnung nicht. Die dreistufige Behandlung (Organisation,
   Kunde, Position) bleibt, damit Reverse Charge und §8 UStG eingestellt
   werden können, wenn sie vorkommen; gebaut wird kein Sonderfall, der nicht
   vorkommt. Die Aufbewahrungsfrist bleibt bei acht Jahren bis jemand
   widerspricht.
2. **Nummernkreis:** Jahr, Monat, fortlaufende Nummer: `RE-202610-0001`.
   Der Zähler läuft **je Jahr** durch, der Monat steht zur Orientierung in
   der Nummer. So bleibt die Folge innerhalb eines Jahres lückenlos
   aufsteigend, was ein Prüfer sehen will, und die Nummer verrät trotzdem,
   wann sie entstand. Präfix je Dokumentart: `RE` Rechnung, `GS` Gutschrift.
3. **PDF:** `dompdf/dompdf`. Reines PHP, keine neue Systemabhängigkeit in
   den drei Kanälen; das PDF entsteht beim Stellen und wird an der Rechnung
   archiviert. *(„mach was du für sinnvoll hältst")*
4. **Stundensätze je Tätigkeitsart**, nicht je Person.
5. **Kein Mitgliedsbezug.** Das Modul ist für den gewerblichen Betrieb; die
   Verknüpfung Kunde ↔ Benutzer entfällt. Die Werft ist der Nutzer.
6. **E-Rechnung kommt mit, wird aber nicht erzwungen.** Zu jeder gestellten
   Rechnung lässt sich eine XRechnung (UBL 2.1, EN 16931) herunterladen und
   mitschicken; das PDF bleibt das Dokument, das jeder Kunde bekommt —
   *„manche Kunden wollen leider Papier."* Validiert wird gegen das Schema,
   nicht gegen die vollständige Schematron-Regelmenge; das steht offen in
   der Doku, bis ein Kunde die erste XRechnung tatsächlich einreicht.

**Version:** 0.3.0 — ein neues Modul ist ein Minor-Schritt.

---

## 15. Was beim Bauen gefunden wurde (2026-10-30)

1. **Die Teile einer Karte liegen gar nicht auf der Karte.** Die Entnahme
   schreibt eine Bestandsbewegung im Lager, geschlüsselt über die
   Kartennummer; die Arbeitskarten lesen sie von dort zurück. Für die
   Abrechnung hieße das, Lagermodelle anzufassen — genau die Grenze, die
   Abschnitt 1 zieht. Die Lösung ist eine Schnittstelle der Karten:
   `IssuePartToCard::usedOn()` liefert `PartUsed` (Name, Teilenummer, Los,
   Menge, Einheit) als reine Daten. Das Abrechnungsmodul importiert nichts
   aus dem Lager und weiß nicht, dass es eines gibt; der Modulgrenzen-Test
   bestätigt das.
2. **Die Einstellungsseite zeigte Gruppen abgeschalteter Module.** Die
   Vereinsflieger-Felder standen auch dann da, wenn die Anbindung aus war.
   Mit der Gruppe „Abrechnung" wäre das die zweite Stelle gewesen; jetzt
   blendet die Seite jede Gruppe aus, deren Name ein nicht aktives Modul
   ist (D3). Die Einstellungen des Moduls leben im Kern-Katalog, wie die
   von Vereinsflieger — das ist der bestehende Weg, kein neuer.
3. **dompdf verlangt `ext-iconv`**, und auf der Entwicklungsmaschine war die
   Erweiterung als Modul vorhanden, aber abgeschaltet. Die drei Kanäle
   bringen sie mit (Debian-Paket, Docker-Basisimage, CI-Image); lokal
   startet PHP mit `-d extension=iconv`. Composer prüft die Plattform beim
   Autoload, deshalb betrifft das jeden lokalen Aufruf, nicht nur den
   PDF-Druck.
4. **dompdf kann kein Flexbox.** Die Bescheinigungen sind mit `display:
   flex` gesetzt; die Rechnung ist deshalb mit Tabellen gesetzt, wie Briefe
   seit jeher. Dieselbe Blade-Vorlage rendert Bildschirm und PDF.
5. **Namensräume in DOM:** `setAttribute('xmlns:cbc', …)` ist kein
   Namensraum, sondern ein Attribut, das so heißt — DOM wiederholte die
   Deklaration dann auf jedem Kindelement. `setAttributeNS` mit dem
   xmlns-Namensraum ist der richtige Aufruf; der XML-Test hat es gezeigt.
6. **Geld rechnet bcmath**, nicht float: 0,1 + 0,2 ist in float nicht 0,3,
   und eine Summe, die einen Cent neben der Summe ihrer Positionen liegt,
   findet ein Kunde. `Money` arbeitet auf Zeichenketten mit vier Stellen
   und rundet kaufmännisch auf zwei — und Mengen behalten drei Stellen, was
   beim Negieren für die Gutschrift erst einmal verloren ging (1,250 h
   wurden -1,25 h). Der Test hat es gefangen.
7. **Die Gutschrift nennt den Empfänger des Originals**, nicht den Kunden
   von heute. Das Stellen frischt den Kundenschnappschuss normalerweise
   auf; bei einer Gutschrift (`corrects_invoice_id` gesetzt) bleibt die
   Kopie aus dem Original.
8. **Der Rechte-Negativtest** hat die vier neuen Resources sofort
   eingefordert und dabei jede Seite als Betrachter und Verwalter
   gerendert — die Formulare, die Ansicht, die Listen. Das war der erste
   Durchlauf der Oberfläche, und er war grün.

**Nicht gebaut, bewusst:** ein Aufschlag auf Fremdleistung als Einstellung
(stand im Entwurf, wäre eine halbe Funktion gewesen — der Betrag der
Fremdrechnung wird eingetragen, fertig), Retention für Rechnungen (sie
werden ohnehin nie gelöscht), DATEV-Format (CSV reicht, bis ein
Steuerberater es anders will).

