# Arbeitskarten

Vorgänge, Karten, Befunde, Arbeitszeiten. Das Modul, auf das CLAUDE.md seit
Anfang zeigt — und die Datenbasis, ohne die das Erfahrungslogbuch nicht das sein
kann, was dort steht: *„eine Auswertung, keine Extra-Pflege"*.

**Erste harte Abhängigkeit im Projekt:** `requires: fleet`. Nicht aus Bequemlichkeit
— eine Karte hält Arbeit an einem Luftfahrzeug fest, und ohne einen Ort für
Luftfahrzeuge wären Karten Notizzettel. Deshalb darf dieses Modul auch einen
echten Fremdschlüssel auf `aircraft` halten, wo das Lager bewusst einen freien
Text führt: Das Lager fordert die Flotte **nicht**, seine Referenz muss also
überleben, dass es die Tabelle nicht gibt.

---

## 1. Zwei Unterschriften auf einer Karte

die Entscheidung gegen eine einzelne:

> Wer die Arbeit gemacht hat, meldet sie fertig. Ein Qualifizierter zeichnet sie
> danach ab. Das bildet die Werkstattrealität ab.

| | wer | was nötig ist |
|---|---|---|
| **fertig gemeldet** | wer die Arbeit gemacht hat | nur die Berechtigung |
| **abgezeichnet** | wer qualifiziert ist | Part-66 oder Pilot-Owner, eingefroren |

Das löst außerdem etwas, das eine einzelne Unterschrift nicht kann. Ein Mechaniker
ohne Lizenz **muss** seine eigene Karte abschließen können — sonst unterschreibt
jemand anderes für einen Nachmittag, den er nicht verbracht hat. Und ungeprüfte
Arbeit darf nicht als abgezeichnet gelten. Beides gleichzeitig geht nur mit zwei
Unterschriften.

Nur die zweite ist eine **Feststellung**. Zu sagen, dass man fertig ist, ist eine
Tatsachenbehauptung über den eigenen Nachmittag.

Keine von beiden ist eine **Freigabe**. Die CRS ist ein eigenes Modul und betrifft
das Luftfahrzeug, nicht die Karte.

### Der Pilot-Owner darf nur, was er selbst gemacht hat

die Korrektur, und sie hat **zwei** Stellen betroffen, an denen ich die
beiden Qualifikationen als austauschbar behandelt hatte:

> CRS darf Fremdarbeiten freigeben. PO explizit nur das, was er selbst gemacht
> hat. Das steht hart in der 1321/2014 drin.

Part-66-Freigabeberechtigte geben Arbeit frei, gleich wer sie ausgeführt hat —
dafür ist die Lizenz da. Eine Pilot-Owner-Berechtigung erlaubt, für die **eigene**
begrenzte Instandhaltung am eigenen Luftfahrzeug zu unterschreiben, und für nichts
sonst. Das sind keine Abstufungen derselben Befugnis.

**Die strenge Lesart ist hier die richtige.** Haben mehrere an einer Karte
gearbeitet, darf ein Pilot-Owner sie nicht zeichnen — die Arbeit enthält fremde,
und „nur was er selbst gemacht hat" trifft dann schlicht nicht zu. Die lockere
Lesart („er hat *etwas* davon gemacht") ließe einen Pilot-Owner die Arbeit eines
Mechanikers abzeichnen, indem er seinen Namen auf zehn Minuten davon setzt — genau
die Konstruktion, die die Regel verhindern soll.

Beaufsichtigte Zeit zählt als fremde Beteiligung: Beaufsichtigt zu werden heißt,
dass jemand anderes für das Wie eingestanden ist.

**Die zweite Stelle war schlimmer.** Bei externen Aufträgen hatte ich beide
Qualifikationen akzeptiert — das ließ einen Halter die Arbeit einer Fremdwerft am
eigenen Flugzeug abzeichnen. Dort ist es keine Schwelle, sondern eine Definition:
Externe Arbeit **ist** fremde Arbeit, es gibt also keine Variante dieses Akts, die
in eine Pilot-Owner-Berechtigung fiele.

Zwei Tests hatten das falsche Verhalten festgeschrieben und sind mit korrigiert.

### Der Zustand, den es sichtbar zu machen gilt

„Fertig gemeldet, aber nicht abgezeichnet" taucht in der Lufttüchtigkeits-Liste
des Luftfahrzeugs auf. Genau dafür gibt es die zweite Unterschrift: Sonst wäre es
der Zustand, der unbemerkt bliebe.

Und ein **Vorgang lässt sich nicht schließen**, solange eine Karte nur fertig
gemeldet ist. Sonst begräbe das Schließen genau das, was die zweite Unterschrift
aufdecken soll.

---

## 2. Ohne Zeiten keine fertige Karte

Keine Bürokratie, sondern die Bedingung dafür, dass das Erfahrungslogbuch eine
Auswertung bleibt: Eine Karte ohne Arbeitszeiten hat es für keine Lizenz je
gegeben. Gefragt wird jetzt, solange sich jemand erinnert — nicht im Januar
rekonstruiert.

**Je Person und Karte**, weil 66.A.20(b) zählt, was jemand *getan* hat:

| | |
|---|---|
| ausgeführt | die Arbeit gemacht |
| unterstützt | jemandem geholfen |
| beaufsichtigt | zugesehen, verantwortlich |

Zwei Mechaniker an einer Karte sind zwei Logbucheinträge — und wenn einer
assistiert hat, ein dritter Eintrag wieder.

**In Minuten.** Auf dem Zettel steht „1:45", nicht „1,75". Wer nach dem zweiten
fragt, bekommt das erste eingetippt.

Nach dem Abzeichnen lassen sich keine Zeiten mehr nachtragen — das änderte, was
jemand unterschrieben hat.

---

## 3. Kennzeichen wird kopiert

Registrierung und Muster stehen **auf der Karte**, nicht nur am Vorgang. Ein
Logbucheintrag hält fest, woran jemand an dem Tag gearbeitet hat, und diese
Tatsache ändert sich nicht, weil das Flugzeug verkauft, umregistriert oder aus
der Flottenliste entfernt wurde. Ein Test löscht das Luftfahrzeug und liest die
Karte danach.

---

## 4. Befunde

Eigene Entität, weil sie das sind: Man dreht eine Schraube heraus und sieht einen
Riss. Er gehört nicht zu der Karte, die man gerade macht, und er verschwindet
nicht, weil sie fertig ist.

**Melden ist nicht entscheiden**, und beides braucht verschiedene Leute:

- **Melden** darf jeder mit der Berechtigung. Das ist eine Beobachtung — sie durch
  eine Lizenzpflicht zu erschweren hieße, dass Risse im Aufenthaltsraum erwähnt
  werden statt im Datensatz.
- **Zurückstellen** ist eine Feststellung. „Hält bis zur nächsten Nachprüfung" ist
  eine Aussage über Lufttüchtigkeit, verlangt eine Qualifikation und wird mit ihr
  festgeschrieben.

### Fünf Zustände, und zwei davon verdienen Erklärung

**Zurückgestellt** ist der Zustand, der das Design rechtfertigt — und das ganze
Risiko einer Zurückstellung ist, dass sie leise wird. Ein zurückgestellter Befund
bleibt deshalb auf der Liste des Luftfahrzeugs. Läuft die Frist ab, **blockiert er,
egal wie er erfasst wurde**: Die Erlaubnis zu warten galt bis zu einem Datum, und
das ist vorbei — genau dann denkt niemand mehr daran.

**Verworfen** ist nicht *behoben*. Es wurde nichts gemacht, und ein Datensatz, der
etwas anderes sagt, wäre auf eine Art falsch, auf die sich jemand verlassen könnte.

Ob ein Befund den Betrieb verhindert, wird **eingetragen und nicht abgeleitet**.
Ob ein Riss kosmetisch ist, kann nur ein Mensch sagen; ein System, das rät, rät in
eine Richtung — und beide sind falsch.

---

## 5. Die Brücke zur Flotte

die Regel, wie die beiden Module zusammenkommen:

> Eine anstehende Aufgabe bekommt eine Arbeitskarte. Ist diese abgezeichnet, ist
> auch die Aufgabe erledigt.

Eine Karte kann gegen eine **fällige Laufzeitgrenze** angelegt werden; das
Abzeichnen erledigt sie. Das läuft über die **eigene Aktion der Flotte**
(`RecordMaintenance`), damit die asymmetrische Anker-Regel unverändert gilt — zu
spät ankert auf der alten Fälligkeit, zu früh auf dem tatsächlichen Stand. Diese
Regel hier zu wiederholen hieße, sie binnen eines Jahres falsch zu wiederholen.

Scheitert das Erledigen, bleibt die Unterschrift trotzdem stehen. Die Karte
**ist** abgezeichnet; dass eine Grenze sich nicht weiterschieben lässt, ist ein
Flottenproblem und darf keine korrekt gegebene Unterschrift rückgängig machen.

### Der Andockpunkt wird zum ersten Mal benutzt

`ContributesOpenItems` — die Erweiterungsstelle, die die Flotte offengelassen
hat. Dieses Modul meldet offene Befunde und ungeprüfte Karten, ohne dass die
Flotte erfährt, was ein Befund ist. Ein Verein nur mit der Flotte bekommt schlicht
weniger Einträge.

---

## 6. ATA-Kapitel

Freitext mit Vorschlagsliste, die Wahl. Im Segelflug wird ATA oft nicht oder
nur grob geführt — eine feste Liste zwänge dazu, ein passendes Kapitel zu suchen,
wo keins passt, und was dann angeklickt wird, ist schlechter als nichts.

---

## 7. Teileentnahme

CLAUDE.md: „Teileentnahme nur, wenn das Lagermodul aktiv ist." Also **fragt** das
Modul, statt zu fordern — ein Verein mit Karten und ohne Lager ist ein echter Fall,
und die Karten funktionieren dann trotzdem.

**Gebucht wird über die eigene Aktion des Lagers**, und das ist der ganze Punkt.
Damit gelten alle dortigen Regeln unverändert: FEFO, Verfallsprüfung,
Sperrlagerfach — und die, die hier am leichtesten verlorenginge: **ein Ausbau-Los
ohne Form 1 geht nur zurück in das Luftfahrzeug, aus dem es stammt.**

Genau das ist der Weg, auf dem man versucht wäre, Regeln zu wiederholen — und eine
wiederholte Regel ist binnen eines Jahres falsch wiederholt. Ein Test baut deshalb
ein Los aus der D-KXYZ aus und versucht, es über eine Karte in die D-KABC zu
bekommen.

Die Karte steuert bei, was das Lager nicht wissen kann: **welcher Vorgang** und
**welches Luftfahrzeug**. Beides existiert am Bewegungsdatensatz längst als freier
Text — es überquert also nichts Neues die Modulgrenze.

Was zu einer Karte ging, wird **aus dem Journal zurückgelesen**, nicht ein zweites
Mal hier geführt. Das Lager besitzt diesen Datensatz; eine Kopie wäre eine zweite
Wahrheit, die abdriftet.

Nach dem Abzeichnen lässt sich nichts mehr auf eine Karte buchen.

---

## 8. Die Freigabe kommt hierher, nicht in ein eigenes Modul

CLAUDE.md sah dafür ursprünglich ein Modul „Freigaben (CRS)" vor. Nach dem Bau
der Nachbarmodule ist das falsch, und der Änderung wurde zugestimmt.

**Sie ist nicht abwählbar.** Nach ML.A.801 braucht jede Instandhaltung eine
Freigabebescheinigung. Lager und Flotte sind echte Optionen — wer Arbeitskarten
führt, erteilt aber zwangsläufig Freigaben. Ein Modul, das niemand abschalten
kann, ist keins.

**Die Unveränderlichkeitsregel koppelt hart.** Sie spricht von *Vorgängen*, und
die liegen hier. In einem anderen Modul müsste jenes in fremde Tabellen greifen
oder die Karten müssten ein Modul befragen, das sie nicht fordern — beides bricht
genau die Grenze, die die Trennung begründen sollte. Der Widerspruch stand in
CLAUDE.md die ganze Zeit zwei Bildschirmseiten auseinander.

**Es wäre die vierte Fassung derselben Regel.** „Jemand Qualifiziertes steht für
etwas ein, Credential eingefroren" existiert bereits dreimal — Loszustand,
externe Arbeit, Kartenabzeichnung. Als die Pilot-Owner-Grenze nachgezogen wurde,
war sie an **zwei von drei** Stellen falsch. Das ist kein hypothetisches Risiko.

Die Freigabe wird deshalb die **dritte Stufe**: nach *fertig gemeldet* und
*abgezeichnet*, auf Vorgangsebene. Ein eigenes Modul rechtfertigen erst die
**145-Bausteine** — unabhängige Zweitprüfung, Eingangsprüfung,
Werkzeugkalibrierung.

---

## 9. Was der Audit gefunden hat

Fünf Dinge waren gebaut, getestet — und über die Oberfläche nicht erreichbar.
Alle derselben Sorte: eine Aktion ohne Knopf.

**Die Arbeitszeiten waren unsichtbar**, und das war der schlimmste Fall. Nur die
Summe stand da. Wer wie lange und in welcher Rolle — also *genau die Daten, für
die dieses Projekt existiert* — ließ sich nur in der Datenbank nachlesen. Eine
Summe lässt sich nicht in Logbucheinträge zurückzerlegen.

**Ein überflüssige Karte blockierte ihren Vorgang für immer.** Schließen verlangt,
dass jede Karte abgezeichnet oder storniert ist — und eine nicht benötigte kann
weder das eine noch das andere sein. Der Ausweg lag in der Aktion und hatte
keinen Knopf.

**Der Weg Befund → Arbeitskarte** war über die Oberfläche gar nicht gehbar, obwohl
er die Kernschleife ist. Angeboten werden dabei alle offenen Befunde des
Luftfahrzeugs, nicht nur die aus diesem Vorgang: Ein im März gefundener Riss wird
bei nächster Gelegenheit erledigt, und genau das ist ein Vorgang.

Dazu **Arbeitsanweisung und ausgeführte Arbeit** (man sah den Titel, nicht was
drinstand) und die **entnommenen Teile**.

---

## 10. Die Freigabe (CRS)

Die dritte und letzte Unterschrift — und die einzige, auf die ein Betrieb
handelt:

| | sagt |
|---|---|
| **fertig gemeldet** | die Arbeit ist getan |
| **abgezeichnet** | sie war in Ordnung |
| **freigegeben** | das Luftfahrzeug darf fliegen |

### Vier Verweigerungen

**Jede Karte abgezeichnet.** Eine ungeprüfte Karte ist genau das, was die zweite
Unterschrift aufdecken soll — eine Freigabe darüber würde sie unter einem
Zertifikat begraben, das das Gegenteil behauptet.

**Kein offener blockierender Befund.** Und hier verdient sich die Zurückstellung
ihren Platz: Ein Befund, bei dem jemand Qualifiziertes entschieden hat, dass er
warten kann, blockiert nicht. Einer, über den niemand geurteilt hat, blockiert.

**Pilot-Owner nur für die eigene Arbeit — auf Vorgangsebene.** Kartenweise wäre
zu wenig: Eine Freigabe deckt den ganzen Vorgang, also genügt **eine** Karte von
jemand anderem, dass ein Part-66-Inhaber unterschreiben muss — auch wenn der
Halter die anderen neun selbst gemacht hat. Eine stornierte Karte zählt nicht
mit; die Arbeit hat niemand gemacht.

**Nicht zweimal.** Eine zweite Freigabe ist eine Korrektur, die auf die erste
verweist — kein Duplikat daneben.

### Das Einfrieren

Die Leitplanke, die seit dem ersten Tag wartete: *Vorgänge mit erteilter CRS sind
eingefroren.* Umgesetzt in den **Modellen**, nicht in den Masken — eine Regel, die
in einem Formular wohnt, ist eine Regel, die ein Import nicht kennt.

Eingefroren werden **Vorgang, Karten und Zeiten**. Nur den Vorgang zu sperren wäre
Zierde gewesen: Das Zertifikat sagt, was die Karten sagen, und die Zeiten sind
das, woraus jemandes Lizenzhistorie gebaut wird.

**Befunde bleiben beweglich** — bewusst. Ein zurückgestellter Befund überlebt den
Vorgang, in dem er gefunden wurde, und muss später behoben werden können.

Für das Einfrieren steht ein `released_at` am Vorgang — die **einzige
denormalisierte Stelle im Projekt**. Jeder Schreibvorgang an einer Karte oder
Zeit muss fragen „bin ich eingefroren?", und das über die Freigabe-Tabelle zu
lösen hieße eine Abfrage pro Speichern, auch in den Schleifen, die Karten
anlegen. Geschrieben wird sie in derselben Transaktion wie das Zertifikat, kann
also nicht abdriften.

### Korrektur statt Änderung

Das Zertifikat selbst ist **unveränderlich** — `update` und `delete` werfen. Eine
Korrektur ist ein neuer Datensatz, der auf den alten verweist und sagt, was
falsch war. Der alte behält seinen Text und seine Unterschrift.

Auf der Oberfläche werden ersetzte Freigaben **angezeigt, nicht versteckt**. Der
ganze Sinn von „Korrektur ist ein neuer Eintrag" ist, dass der alte lesbar
bleibt — ihn dort zu verbergen, wo jemand hinschaut, hätte das aufgehoben.

### Der Freigabevermerk wird festgeschrieben

Der Text über der Unterschrift wird beim Erteilen gespeichert, nicht bei jeder
Anzeige neu gebaut. **Eine Unterschrift gehört zu den Worten, die über ihr
standen** — nicht zu dem, was eine spätere Vorlage daraus macht. Vorbelegt aus
einem Standardtext, aber editierbar, denn unterschreiben tut ein Mensch.

### Was der neue Check aufgedeckt hat

Ein Vorgang mit allen Karten abgezeichnet und ohne Freigabe sieht von außen am
fertigsten aus: Häkchen überall, Flugzeug in der Halle, und nichts sagt, dass es
fliegen darf. Vorher erzeugte das **keinen** offenen Punkt — die Kartenprüfung war
still geworden, weil die Karten fertig waren.

Ein bestehender Test hieß „once signed off it disappears again" und hat genau
diese Lücke als „erledigt" gelesen. Er heißt jetzt anders und prüft, dass ein
offener Punkt durch den nächsten ersetzt wird.

---

## 11. Externer Auftrag und Bescheinigungsdruck

**Der externe Auftrag ist jetzt am Vorgang verknüpfbar** — die Spalte lag seit
der ersten Migration brach. Der Sinn: Die Jahresnachprüfung, deren Motor bei der
Fremdwerft war, zeigt auf derselben Seite, welcher Auftrag das war und wer
freigegeben hat — statt dass zwei Aufzeichnungen dasselbe Ereignis beschreiben,
ohne voneinander zu wissen.

Eine Verweigerung mit Biss: **Der Auftrag muss zum Luftfahrzeug des Vorgangs
gehören.** Eine für die D-KXYZ beauftragte Überholung sagt nichts über die
Jahresnachprüfung der D-KABC — die Verknüpfung würde eine falsche Spur in beide
Aufzeichnungen setzen. Ein freigegebener Vorgang verweigert die Verknüpfung
über sein Einfrieren im Modell — ohne Sonderfall in der Aktion, was genau der
Sinn der Modell-Durchsetzung ist.

**Die Freigabebescheinigung lässt sich drucken** (`/freigabe/{id}`), als Papier
für die Bordunterlagen. Zwei Dinge sagt das Papier, die der Bildschirm nicht
sagen muss:

- Eine **ersetzte** Bescheinigung druckt mit unübersehbarem Banner samt
  Nachfolger und Grund. Das Papier im Ordner überlebt den, der es abgeheftet
  hat — und das eine, was ein veralteter Ausdruck nicht darf, ist aktuell
  aussehen.
- Eine **Korrektur** druckt mit dem Grund und dem Verweis auf das Original,
  denn die Papierspur muss sich ohne Datenbankzugriff selbst erklären.

---

## 12. Was der adversariale Review gefunden hat

Nach Fertigstellung lief ein Review-Workflow mit vier Blickwinkeln über das
Tagwerk. Drei liefen durch (27 Funde), die Gegenprüfer starben am Session-Limit
— die Verifikation geschah deshalb von Hand am Code, und `FreezeHardeningTest`
**ist** diese Verifikation: Jeder Test reproduziert ein behauptetes Loch und
beweist es geschlossen. Die wichtigsten:

**Die Einfrier-Kette hatte drei Umgehungen.** Löschen (ein soft-gelöschter
Vorgang ließ seine Karten auftauen, weil deren Guards den Eltern-Datensatz als
`null` auflösten), Umhängen (der Guard prüfte nur den *neuen* Vorgang — Karte
auf einen offenen Vorgang zeigen, und sie verlässt den eingefrorenen), und der
DB-Cascade (ein Hard-Delete hätte die **unterschriebenen Zertifikate** über die
Fremdschlüssel mitgerissen, an allen Eloquent-Guards vorbei). Jetzt: Lösch-Guard
am Vorgang, Umhängen kategorisch verboten (Karten wechseln nie den Vorgang,
Stunden nie die Karte), und die Zertifikat-Fremdschlüssel stehen auf RESTRICT —
die Datenbank selbst weigert sich.

**Das Freigabe-Tor hatte drei Löcher bei Befunden.** Ein *eingeplanter*
blockierender Befund blockierte nicht mehr — Einplanen braucht aber keine
Qualifikation, also konnte jeder das Tor per Klick räumen. Eine *abgelaufene*
Zurückstellung blockierte nicht, obwohl die Lufttüchtigkeitsprüfung desselben
Moduls sie als blockierend meldete. Und das dreifach im Code versprochene
„abgezeichnete Karte löst ihren Befund" existierte nicht — Befunde blieben ewig
„eingeplant". Alle drei geschlossen; Stornieren der Karte gibt den Befund auf
*offen* zurück, und Erledigen/Verwerfen sind jetzt Feststellungen mit
Qualifikationspflicht (E8).

**Zwei Races und ein Deadlock.** Doppelte Freigabe (Prüfung außerhalb der
Transaktion) und doppelte Korrektur — jetzt Lock + Re-Check in der Transaktion,
dazu ein Unique-Index auf `supersedes_release_id` als Backstop. Und: Eine
Freigabe auf einen offenen Vorgang fror ihn ein, *bevor* er geschlossen war —
für immer „offen", der Knopf warf ewig Fehler. **Die Freigabe schließt den
Vorgang jetzt selbst** — nach der dritten Unterschrift passiert nichts mehr.

**Der Korrektur-Pfad umging die Pilot-Owner-Grenze.** Eine Korrektur ist ein
neues Zertifikat über dieselbe Arbeit — der Pfad prüfte aber nur die
Qualifikation, nicht die Eigenarbeit. Ein Halter hätte die Freigabe eines
Part-66ers über Mechanikerarbeit „korrigieren" und seinen Namen daraufsetzen
können. Und `OwnWorkOnly` las eine *Assisted*-Zeile des Halters als
Eigenarbeit — „unterstützt" heißt aber, dass jemand anderes ausgeführt hat.

Dazu: Kartennummern max-geparst statt gezählt (Duplikate nach Löschung),
Nummerngeneratoren in echten Transaktionen (Lock war unter Autocommit wirkungslos),
Teile weder auf stornierte Karten noch in freigegebene Vorgänge, ersetzte
Freigaben zählen im Erfahrungslogbuch weiter (Erfahrungs-, nicht
Gültigkeitsnachweis).

Offen und bewusst nicht gefixt: Der AuthZ-Prüfer lief nie (Session-Limit) —
**der Blickwinkel fehlt noch** und wird nachgeholt.

---

## 13. Was noch fehlt

*Stand 2026-08-02. Beide Punkte, die hier standen, sind gebaut: das LTA/TM-Modul
mit zeilenweisem Abhaken, und das Part-66-Erfahrungslogbuch samt Auswertung
(`ExperienceLog`, `RecencyReport`) — die ursprüngliche Anforderung des ganzen
Projekts.*

- **Nichts modulintern offen.** Die Freigabe (CRS) sitzt bewusst hier und nicht
  in einem eigenen Modul, obwohl CLAUDE.md sie getrennt aufzählt: sie hängt an
  der Arbeitskarte, die sie freigibt, und eine Modulgrenze dazwischen wäre eine
  Naht ohne Schnitt. Falls ein Betrieb sie je einzeln abschalten können muss,
  ist das eine Entscheidung — keine Ableitung.
