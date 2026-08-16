<?php

declare(strict_types=1);

namespace App\Core\Settings;

use App\Core\Mail\Postman;
use App\Core\Models\Setting;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use SensitiveParameter;
use Throwable;

/**
 * Die Einstellungen des Vereins -- lesen, schreiben, und über die Konfiguration
 * legen.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * DIE RANGFOLGE, und sie ist die Entscheidung:
 *
 *     Datenbank  →  Umgebung  →  Vorgabe
 *
 * „db gewinnt, env nur initial". Praktisch heisst das: Solange in der Tabelle
 * nichts steht, gilt die Umgebungsvariable -- eine docker-compose.yml wirkt
 * also wie erwartet. Sobald jemand den Wert EINMAL in der Oberfläche gesetzt
 * hat, gilt die Tabelle, und die Umgebung wird für diesen Schlüssel nie wieder
 * gelesen.
 *
 * Das muss die Oberfläche sagen, und sie tut es: Sonst ändert später jemand die
 * compose-Datei, nichts passiert, und kein Fehler erscheint. Diese Woche hat
 * uns diese stille Sorte schon zweimal gekostet.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * VOR DER MIGRATION GIBT ES DIE TABELLE NOCH NICHT.
 *
 * Der Einrichtungsassistent läuft, bevor sie existiert, und `php artisan` muss
 * auch dann etwas tun können. Fehlt sie, wird sie stillschweigend übersprungen
 * -- das Ergebnis ist dann genau das Verhalten von vorher, nämlich Umgebung
 * und Vorgabe. Ein Startfehler an dieser Stelle würde eine frische Installation
 * unbedienbar machen, bevor sie eingerichtet ist.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * GELESEN WIRD OHNE ELOQUENT, und das ist kein Geschmack.
 *
 * Die Werte werden gebraucht, BEVOR Laravel Eloquent mit der Datenbank
 * verbunden hat: Filament baut sein Panel im register() seines Providers, und
 * die Verbindung setzt der Datenbank-Provider erst in seinem boot(). Ein
 * Model-Zugriff dort endet in „Call to a member function connection() on null"
 * -- gefangen, verschluckt, und die Einstellungen wirkten still nicht.
 *
 * Der Query Builder kann das, weil er den Verbindungsverwalter direkt nimmt.
 * Preis ist das Entschlüsseln von Hand statt über den Cast des Models; es steht
 * genau einmal weiter unten und ist derselbe Algorithmus.
 * ─────────────────────────────────────────────────────────────────────────────
 */
final class Settings
{
    /** @var array<string, string|null>|null */
    private ?array $gespeichert = null;

    /**
     * Das Lesen ist in DIESER Anfrage gescheitert.
     *
     * Getrennt von $gespeichert, und darin liegt die ganze Korrektur: Der
     * Fehlschlag wird gemerkt (sonst kostet er bei jedem der 44 Katalogschlüssel
     * einen neuen Verbindungsversuch -- ohne gesetzten Verbindungs-Zeitablauf
     * haengt damit eine frische Installation ohne Datenbank), aber er ist eben
     * NICHT dasselbe wie „es gibt keine Einstellungen". Frueher war beides
     * derselbe leere Array, und genau daran ist der Vereinsname gestorben.
     */
    private bool $fehlgeschlagen = false;

    /**
     * Der wirksame Wert eines Schlüssels.
     */
    public function get(string $key): mixed
    {
        $definition = SettingsCatalogue::byKey()[$key] ?? null;

        if ($definition === null) {
            throw new RuntimeException(sprintf('Unbekannte Einstellung "%s".', $key));
        }

        $ausDerTabelle = $this->stored()[$key] ?? null;

        if ($ausDerTabelle !== null) {
            return $definition->cast($ausDerTabelle);
        }

        if ($definition->envVar !== null) {
            $ausDerUmgebung = env($definition->envVar);

            if ($ausDerUmgebung !== null && $ausDerUmgebung !== '') {
                return $definition->cast((string) $ausDerUmgebung);
            }
        }

        return $definition->default;
    }

    /**
     * Woher der wirksame Wert kommt -- für die Anzeige in der Oberfläche.
     *
     * Ein Feld, dessen Wert aus der Umgebung stammt, sieht sonst aus wie ein
     * gesetzter -- und der Unterschied ist wichtig: Der eine wird beim nächsten
     * Speichern festgeschrieben, der andere kann sich beim nächsten
     * Container-Start ändern.
     */
    public function sourceOf(string $key): string
    {
        $definition = SettingsCatalogue::byKey()[$key] ?? null;

        if ($definition === null) {
            return 'unbekannt';
        }

        if (($this->stored()[$key] ?? null) !== null) {
            return 'datenbank';
        }

        if ($definition->envVar !== null && filled(env($definition->envVar))) {
            return 'umgebung';
        }

        return 'vorgabe';
    }

    public function set(string $key, #[SensitiveParameter] mixed $value): void
    {
        $definition = SettingsCatalogue::byKey()[$key] ?? null;

        if ($definition === null) {
            throw new RuntimeException(sprintf('Unbekannte Einstellung "%s".', $key));
        }

        Setting::updateOrCreate(
            ['key' => $key],
            [
                'value' => $value === null ? null : (string) (is_bool($value) ? ($value ? '1' : '0') : $value),
                'is_secret' => $definition->isSecret(),
            ],
        );

        $this->gespeichert = null;
    }

    /**
     * Einen Wert wieder auf Umgebung bzw. Vorgabe zurückfallen lassen.
     */
    public function forget(string $key): void
    {
        Setting::where('key', $key)->delete();

        $this->gespeichert = null;
    }

    /**
     * Legt alle wirksamen Werte über die Konfiguration.
     *
     * Damit bleibt config() die einzige Leseschnittstelle im übrigen Code --
     * BackupCommand, RetentionCommand und die Disks müssen nichts von dieser
     * Klasse wissen. Das war die Bedingung dafür, den Umbau überhaupt
     * minimal-invasiv machen zu können.
     */
    public function applyToConfig(): void
    {
        foreach (SettingsCatalogue::all() as $definition) {
            $wert = $this->get($definition->key);

            // Nur setzen, was etwas ist: Ein leerer Wert würde sonst eine
            // sinnvolle Vorgabe in der config-Datei überschreiben.
            if ($wert === null || $wert === '') {
                continue;
            }

            config()->set($definition->configPath, $wert);
        }

        $this->activateMailer();
    }

    /**
     * Den SMTP-Versand einschalten, sobald ein Zugang hinterlegt ist.
     *
     * ─────────────────────────────────────────────────────────────────────────
     * Ohne das bliebe `mail.default` auf „log", und die Einstellungen daneben
     * waeren wirkungslos: Wer Server, Benutzer und Passwort eintraegt, meint
     * damit, dass verschickt werden soll -- und wuerde stattdessen Mails in
     * einer Logdatei sammeln, ohne dass etwas darauf hindeutet.
     *
     * Umgekehrt bleibt es bei „log", solange kein Zugang da ist. Ein Mailer,
     * der ins Nichts sendet, wirft Ausnahmen an Stellen, die mit Mail nichts
     * zu tun haben.
     *
     * NICHT ueber eine eigene Einstellung, sondern abgeleitet: Ein Schalter
     * „Mail aktiv" liesse sich anschalten, ohne einen Server einzutragen --
     * und dann steht wieder ein Link in der Anmeldemaske, der nichts tut.
     * ─────────────────────────────────────────────────────────────────────────
     */
    private function activateMailer(): void
    {
        if (! Postman::configured()) {
            return;
        }

        config()->set('mail.default', 'smtp');

        // Der Absendername faellt auf den Namen der Organisation zurueck --
        // sonst steht „Laravel" im Postfach der Mitglieder.
        config()->set('mail.from.name', Postman::fromName());
    }

    /**
     * @return array<string, string|null>
     */
    private function stored(): array
    {
        if ($this->gespeichert !== null) {
            return $this->gespeichert;
        }

        if ($this->fehlgeschlagen) {
            return [];
        }

        try {
            if (! Schema::hasTable('settings')) {
                /*
                 * Der Normalfall vor der ersten Migration -- und das EINZIGE
                 * Leer, das gemerkt werden darf: Es ist eine Antwort, kein
                 * Fehlschlag.
                 */
                return $this->gespeichert = [];
            }

            $werte = [];

            foreach (DB::table('settings')->get(['key', 'value']) as $zeile) {
                $werte[$zeile->key] = $this->entschluesselt($zeile->value);
            }

            return $this->gespeichert = $werte;
        } catch (Throwable $e) {
            /*
             * ─────────────────────────────────────────────────────────────────
             * KEINE DATENBANK, KAPUTTER APP_KEY -- weiterlaufen mit Umgebung und
             * Vorgabe, statt beim Start zu sterben. Ein Fehler hier machte eine
             * frische Installation unbedienbar, bevor sie eingerichtet ist.
             *
             * GEMERKT WIRD ER, ABER NICHT ALS ERGEBNIS. Das ist die Korrektur,
             * und sie kostete einen Feldtest: Frueher landete der Fehlschlag im
             * selben leeren Array wie die Antwort „es gibt nichts". Wer danach
             * fragte, bekam „keine Einstellungen" -- fuer den Rest der Anfrage,
             * ohne Fehler, ohne Zeile im Protokoll. Der Vereinsname stand
             * deshalb auf der Vorgabe, obwohl er in der Tabelle stand.
             *
             * Und deshalb steht er jetzt im Protokoll: „nicht lesbar" ist nie
             * normal, sobald die Tabelle da ist. Genau einmal je Anfrage --
             * applyToConfig fragt 44 Schluessel einzeln ab, und 44 gleiche
             * Meldungen verstecken die eine, die zaehlt.
             *
             * UEBER DEN LOGGER STATT UEBER report(): report() sammelt Kontext
             * ein, fragt dabei den angemeldeten Benutzer ab -- also die
             * Datenbank, die hier gerade nicht antwortet -- und kann selbst
             * werfen. Ein Fehler beim Melden eines Fehlers darf den Start nicht
             * kosten; genau davor schuetzt dieser catch ja.
             * ─────────────────────────────────────────────────────────────────
             */
            $this->fehlgeschlagen = true;

            try {
                Log::warning('Die Einstellungen sind nicht lesbar; es gelten Umgebung und Vorgabe.', [
                    'exception' => $e::class,
                    'message' => $e->getMessage(),
                ]);
            } catch (Throwable) {
                // Kein Protokoll erreichbar. Dann eben ohne -- die Anwendung
                // laeuft weiter, und das ist hier das Wichtigere.
            }

            return [];
        }
    }

    /**
     * Der Wert aus der Zeile -- entschlüsselt wie der Cast des Models.
     *
     * EINZELN gefangen: Ein unlesbarer Wert (nachträglich von Hand eingetragen,
     * mit altem Schlüssel verschlüsselt) darf nicht alle anderen Einstellungen
     * mitnehmen. Er verhält sich dann wie „nicht gesetzt", also greifen
     * Umgebung und Vorgabe.
     */
    private function entschluesselt(?string $roh): ?string
    {
        if ($roh === null || $roh === '') {
            return null;
        }

        try {
            return Crypt::decryptString($roh);
        } catch (Throwable $e) {
            try {
                // Ohne den Wert: Was hier nicht aufgeht, ist immer noch ein
                // Geheimnis. Die Meldung des Entschluesselers nennt ihn nicht.
                Log::warning('Eine Einstellung ist nicht entschluesselbar.', [
                    'exception' => $e::class,
                    'message' => $e->getMessage(),
                ]);
            } catch (Throwable) {
                // siehe oben
            }

            return null;
        }
    }
}
