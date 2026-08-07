<?php

declare(strict_types=1);

/*
|------------------------------------------------------------------------------
| Inline-Skripte, die ausgeführt werden dürfen
|------------------------------------------------------------------------------
|
| ERZEUGT, NICHT VON HAND GEPFLEGT. Neu erzeugen mit:
|
|     CSP_PIN=1 ./vendor/bin/phpunit --filter CspScriptHashesTest
|
| Danach `git diff config/csp.php` ansehen -- die Prüfung durch einen Menschen
| ist der Punkt, an dem sich diese Liste von einer Automatik unterscheidet, die
| alles durchwinkt.
|
|------------------------------------------------------------------------------
| WARUM ÜBERHAUPT HASHES
|
| Filament liefert vier Inline-Skripte aus (Dunkelmodus, eingeklappte
| Menügruppen, `window.filamentData`, der Aufruf des Dunkelmodus). Unter
| `script-src 'self'` führt der Browser keines davon aus -- die Oberfläche baut
| sich, ist aber halb tot, und kein Rendering-Test sieht das.
|
| Signieren über eine Nonce geht nicht: Livewire beherrscht CSP-Nonces,
| FILAMENT NICHT. Bleibt der Hash über den Inhalt.
|
|------------------------------------------------------------------------------
| WARUM DIE LISTE IM REPO LIEGT UND NICHT ZUR LAUFZEIT ENTSTEHT
|
| Zur Laufzeit zu hashen wäre die naheliegende Automatisierung und wäre fatal:
| Die Middleware sähe die fertige Seite und würde ein EINGESCHLEUSTES Skript
| genauso brav hashen und erlauben. Das wäre `unsafe-inline` mit Extraschritten.
|
| Driften kann die Liste nicht: Alle drei Auslieferungskanäle installieren mit
| `composer install`, nie `composer update` -- Filament ändert sich also nur
| mit einem neuen Aeronance-Release. Diese Datei ist damit ein Release-Artefakt
| wie `composer.lock` und wird im selben Vorgang gepflegt. `CspScriptHashesTest`
| schlägt an, sobald die ausgelieferten Skripte nicht mehr dazu passen.
|
*/

return [

    'script_hashes' => [
        // Filament 5 — erzeugt, siehe Kopf
        'sha256-0mqBbIWwNzdgIFPFU9BM5l9RnzSn/EbrlFBGLZHGf5Y=',
        'sha256-CUO614iJ1yQTie8j253djmvmNcad8Y2m0MoYGZs+JQo=',
        'sha256-U1bkbkwEe1mD+tUU83n0bTj/yfxW/ZR1XA7I/KciRwU=',
        'sha256-WX5lAdf8niz6n+76OT4AN25MZGGAcHm6ofSPP/WrrqQ=',
    ],

];
