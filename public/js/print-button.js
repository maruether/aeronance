/*
 * Der Drucken-Knopf der Druckansichten.
 *
 * Als eigene Datei und nicht als onclick-Attribut, denn das ist keine
 * Stilfrage: Die CSP erlaubt nur Skripte eigener Herkunft, und ein
 * Inline-Handler ist unter script-src ohne unsafe-inline wirkungslos --
 * der Knopf sah aus wie einer und tat nichts (Feldtest: "der drucken
 * button ist tot"). Eine Datei aus public/ ist 'self' und braucht weder
 * Hash noch Lockerung.
 */
document.querySelectorAll('[data-print]').forEach(function (knopf) {
    knopf.addEventListener('click', function () {
        window.print();
    });
});
