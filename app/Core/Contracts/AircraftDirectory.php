<?php

declare(strict_types=1);

namespace App\Core\Contracts;

/**
 * Die Kennzeichen, die diese Instanz kennt -- als Naht, nicht als Zugriff.
 *
 * Der Kern braucht sie an genau einer Stelle: Der Geltungsbereich einer
 * Qualifikation (P/O-Berechtigung) IST ein Kennzeichen, und ein Freitextfeld
 * dafür produziert "D-KABC ", "d-kabc" und "D KABC" -- drei Schreibweisen,
 * von denen die Rechteprüfung zwei nicht wiedererkennt. Feldtest: "wäre es
 * schön wenn ich in dem LFZ Feld eine Auswahlliste hätte."
 *
 * Der Kern darf die Flottentabellen trotzdem nicht anfassen (Modulgrenze).
 * Deshalb dieser Vertrag: Das Flottenmodul liefert die Liste, wenn es aktiv
 * ist -- und meldet LEER, wenn nicht. Der Kern fällt dann auf Freitext
 * zurück und bleibt ohne jedes Modul lauffähig, wie es die Leitplanke will.
 */
interface AircraftDirectory
{
    /**
     * Alle Kennzeichen, alphabetisch -- oder leer, wenn keine Flotte da ist.
     *
     * @return list<string>
     */
    public function registrations(): array;
}
