{{--
    Der Ausdruck des Wägeblatts -- ab hier nur noch ein Verteiler.

    ─────────────────────────────────────────────────────────────────────────────
    Feldtest: „Ich will das BWLV Formular quasi 1:1 haben zum digital ausfüllen
    nur ohne das logo." Vorher stand hier EIN Blatt, das beide Rechenwege mit
    Bedingungen abbildete: eine Tabelle nach der anderen, die Zeichnung ganz
    unten, und die Anordnung des Papiers nirgends. Genau das war der Befund --
    „der jetztige istzustand ist für den täglichen betrieb nicht brauchbar".

    Zwei Blätter statt eines, weil es zwei verschiedene Blätter SIND: Das
    Segelflugblatt stellt Wägung und Massengrenzen nebeneinander, das
    Motorflugblatt führt Kennblattdaten, Auflagen und Abzüge in einer Rechnung
    untereinander. Beides in ein Template zu zwingen hiess, keins von beiden
    richtig zu haben.

    Diese Datei bringt selbst KEINEN Kopf mehr mit: Jedes Blatt ist ein
    vollständiges Dokument samt eigenem Seitenformat. Ein Rahmen hier drum
    herum ergäbe ein doctype im body.
    ─────────────────────────────────────────────────────────────────────────────
--}}
@include($weighing->kind->usesComponents()
    ? 'fleet.print._weighing_glider'
    : 'fleet.print._weighing_powered')
