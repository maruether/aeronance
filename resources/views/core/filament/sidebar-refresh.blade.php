{{--
    Der Puls der Seitenleiste.

    Feldtest: "Sidebar aktualisiert nur bei klick, sollte automatisch
    geschehen." Die Zähler an den Menüpunkten (offene Vorgänge, fällige
    Prüfungen, Mindestbestände) stammen aus dem Seitenaufbau -- wer die Seite
    offen liegen lässt, sieht den Stand von vorhin.

    WARUM DAS EINE ZEILE IST UND KEIN SKRIPT: Filament rendert die
    Seitenleiste als eigene Livewire-Komponente, und dieses Element wird per
    Render-Hook IN diese Komponente eingehängt. `wire:poll` fragt damit alle
    dreißig Sekunden die Sidebar-Komponente neu an -- Navigation samt aller
    Badges wird serverseitig frisch gebaut und per Morph eingesetzt. Kein
    eigenes JavaScript, kein Inline-Skript, die CSP bleibt unberührt.

    Livewire drosselt das Polling von sich aus, sobald der Reiter im
    Hintergrund liegt. Bewusste Nebenwirkung: Ein offener Reiter hält die
    Sitzung wach -- jede Abfrage zählt als Aktivität.
--}}
<div wire:poll.30s hidden></div>
