<?php

declare(strict_types=1);

namespace App\Core\Settings;

use App\Core\Mail\Postman;
use App\Core\Models\Setting;
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
 * ─────────────────────────────────────────────────────────────────────────────
 */
final class Settings
{
    /** @var array<string, string|null>|null */
    private ?array $gespeichert = null;

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

        try {
            if (! Schema::hasTable('settings')) {
                return $this->gespeichert = [];
            }

            $this->gespeichert = Setting::query()
                ->get(['key', 'value', 'is_secret'])
                ->mapWithKeys(static fn (Setting $s): array => [$s->key => $s->value])
                ->all();
        } catch (Throwable) {
            /*
             * Keine Datenbank, keine Tabelle, kaputter APP_KEY -- in allen drei
             * Fällen ist die richtige Antwort dieselbe: so tun, als gäbe es
             * keine gespeicherten Werte. Die Anwendung läuft dann mit Umgebung
             * und Vorgabe weiter, statt beim Start zu sterben.
             */
            $this->gespeichert = [];
        }

        return $this->gespeichert;
    }
}
