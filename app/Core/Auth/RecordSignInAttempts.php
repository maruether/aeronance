<?php

declare(strict_types=1);

namespace App\Core\Auth;

use App\Models\User;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Login;
use Illuminate\Support\Str;

/**
 * Anmeldeversuche im Protokoll — die gescheiterten zuerst.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * DIE LEITPLANKE: „Auth härten: […] fehlgeschlagene Logins ins Audit-Log."
 *
 * Warum die gescheiterten die wichtigeren sind: Ein Angriff auf ein Passwort
 * besteht fast nur aus Fehlversuchen. Ohne sie sieht ein Betrieb entweder gar
 * nichts — oder erst den einen erfolgreichen Versuch, der wie jede andere
 * Anmeldung aussieht.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * DAS PASSWORT WIRD NIEMALS PROTOKOLLIERT, und das ist der einzige Grund, warum
 * diese Klasse mehr tut als `$event->credentials` weiterzureichen.
 *
 * Das `Failed`-Ereignis trägt die vollständigen Anmeldedaten — E-MAIL UND
 * PASSWORT IM KLARTEXT. Wer sie unbesehen ins Protokoll schreibt, hat eine
 * Tabelle gebaut, in der die Passwörter aller Menschen stehen, die sich einmal
 * vertippt haben: unverschlüsselt, in jeder Sicherung, lesbar für jeden mit
 * Protokollrecht — und ausgerechnet in dem Verzeichnis, das niemand löschen
 * darf (siehe E3, der Audit-Trail ist append-only).
 *
 * Deshalb wird nicht ausgeschlossen, sondern AUSGEWÄHLT: Nur Feldnamen aus
 * einer festen Liste werden übernommen. Eine Ausschlussliste („alles außer
 * password") wäre eine Wette darauf, dass kein künftiges Feld anders heißt.
 * Diese Richtung ist sicher, ohne dass man an sie denken muss.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * WAS EIN FEHLVERSUCH BEDEUTET, ist mehrdeutig, und das steht im Eintrag:
 *
 *  - Kein Konto zur Kennung → jemand hat eine Adresse geraten oder sich vertippt.
 *  - Konto vorhanden → falsches Passwort ODER kein Zugang zum Panel. Filament
 *    prüft `canAccessPanel()` innerhalb des Anmeldeversuchs, ein GESPERRTES
 *    oder deaktiviertes Konto scheitert also hier und nicht später. Das ist
 *    richtig so: Wer gesperrt ist, hat sich nicht angemeldet.
 *
 * Der Not-Aus wird damit sichtbar: Versucht es ein gesperrter Mensch weiter,
 * steht das im Protokoll.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * KEINE EIGENE ANMELDESEITE FÜR DIE DROSSELUNG. Filament wirft bei zu vielen
 * Versuchen eine Ausnahme, ohne ein Laravel-Ereignis auszulösen — man müsste
 * die Seite ableiten, um das zu protokollieren. Der Nutzen wäre gering: Fünf
 * `login_failed` in einer Minute sagen dasselbe wie eine Drosselungsmeldung,
 * und sie stehen ohnehin schon da.
 * ─────────────────────────────────────────────────────────────────────────────
 */
final class RecordSignInAttempts
{
    /**
     * Feldnamen, die übernommen werden dürfen.
     *
     * Alles andere fällt weg — siehe Kopf. Diese Liste zu erweitern ist eine
     * bewusste Handlung; sie zu vergessen ist harmlos.
     *
     * @var list<string>
     */
    private const KENNUNGSFELDER = ['email', 'username', 'name'];

    /**
     * Bereits erfasste Fehlversuche dieses Aufrufs.
     *
     * ─────────────────────────────────────────────────────────────────────────
     * EIN VERSUCH KANN ZWEI EREIGNISSE AUSLOESEN, und das ist gemessen und
     * nicht vermutet: Stimmt das Passwort, scheitert aber `canAccessPanel()` --
     * bei einem GESPERRTEN oder deaktivierten Konto --, dann feuert erst der
     * Guard aus `attemptWhen()` ein `Failed` und Filament anschliessend noch
     * eines von Hand. Das Protokoll zeigte daraufhin zwei Fehlversuche fuer
     * einen einzigen Klick.
     *
     * Das ist kein kosmetisches Problem: Wer Fehlversuche zaehlt, um einen
     * Angriff zu erkennen, zaehlt bei gesperrten Konten doppelt -- und ein
     * Protokoll, das die Zahl der Versuche falsch angibt, ist an der Stelle
     * wertlos, an der man es braucht.
     *
     * Deshalb gilt: eine Anmeldehandlung, ein Eintrag. Der Speicher lebt nur
     * fuer diesen Aufruf (der Zuhoerer ist als Singleton gebunden); der
     * naechste Versuch ist ein neuer Aufruf und damit ein neuer Eintrag.
     * ─────────────────────────────────────────────────────────────────────────
     *
     * @var list<string>
     */
    private array $erfasst = [];

    public function failed(Failed $event): void
    {
        $user = $event->user instanceof User ? $event->user : null;
        $identifier = $this->identifierFrom($event->credentials);

        $marke = ($identifier ?? '?').'|'.$event->guard;

        if (in_array($marke, $this->erfasst, true)) {
            return;
        }

        $this->erfasst[] = $marke;

        $eintrag = activity('auth')->withProperties([
            'identifier' => $identifier,
            'ip' => request()->ip(),

            /*
             * Ob es das Konto gibt, steht ausdruecklich dabei: Der
             * Unterschied zwischen "Adresse geraten" und "Passwort falsch"
             * ist genau die Frage, die jemand stellt, der wissen will, ob
             * hier ein Angriff laeuft oder sich jemand vertippt hat.
             */
            'account_exists' => $user !== null,
        ]);

        /*
         * `performedOn()` nimmt kein null -- bei einer unbekannten Adresse gibt
         * es schlicht keinen Datensatz, auf den sich der Eintrag bezieht. Die
         * eingegebene Kennung steht dann in den Eigenschaften, und mehr weiss
         * das System auch nicht.
         */
        if ($user !== null) {
            $eintrag->performedOn($user);
        }

        $eintrag->log('login_failed');
    }

    public function succeeded(Login $event): void
    {
        $user = $event->user instanceof User ? $event->user : null;

        $eintrag = activity('auth')
            ->causedBy($user)
            ->withProperties(['ip' => request()->ip()]);

        if ($user !== null) {
            $eintrag->performedOn($user);
        }

        $eintrag->log('login_succeeded');
    }

    /**
     * Die Kennung aus den Anmeldedaten — und nur sie.
     *
     * Gekürzt, weil das Feld aus einem Formular kommt und niemand hindert,
     * ein Megabyte hineinzuschreiben. Ein Protokolleintrag darf keine
     * Angriffsfläche für die Datenbank sein.
     *
     * @param  array<string, mixed>  $credentials
     */
    private function identifierFrom(array $credentials): ?string
    {
        foreach (self::KENNUNGSFELDER as $feld) {
            $wert = $credentials[$feld] ?? null;

            if (is_string($wert) && $wert !== '') {
                return Str::limit($wert, 190);
            }
        }

        return null;
    }
}
