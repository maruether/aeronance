<?php

declare(strict_types=1);

namespace App\Core\Settings;

/**
 * Was eine Einstellung ist, an einer Stelle beschrieben.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * EIN KATALOG STATT VERSTREUTER KENNTNIS. Aus dieser Beschreibung entstehen
 * gleich vier Dinge, die sonst auseinanderlaufen würden: das Formular in der
 * Oberfläche, die Prüfung der Eingabe, der Vorrang gegenüber der Umgebung und
 * die Überlagerung der Konfiguration beim Start.
 *
 * Vorher lag dieselbe Kenntnis dreifach: als env()-Aufruf in der config, als
 * Erwartung im Code, der sie liest, und als Satz in der Doku. Drei Orte für
 * eine Wahrheit sind zwei zu viel.
 * ─────────────────────────────────────────────────────────────────────────────
 */
final readonly class SettingDefinition
{
    /**
     * @param  array<string, string>  $options
     */
    public function __construct(
        /** Der Schlüssel in der Tabelle, z. B. "backup.encryption.mode". */
        public string $key,

        /**
         * Wohin der Wert beim Start überlagert wird, z. B.
         * "aeronance.backup.encryption.mode".
         *
         * Damit bleibt config() die einzige Leseschnittstelle -- kein Aufrufer
         * muss wissen, ob ein Wert aus der Datenbank, der Umgebung oder der
         * Vorgabe kommt.
         */
        public string $configPath,

        /**
         * Die Umgebungsvariable, die diesen Wert VOR der ersten Änderung setzt.
         *
         * „db gewinnt, env nur initial": Solange in der Tabelle nichts steht,
         * gilt die Umgebung -- damit funktioniert eine docker-compose.yml wie
         * erwartet. Sobald jemand den Wert in der Oberfläche anfasst, gilt die
         * Tabelle, und die Umgebung wird für diesen Schlüssel nie wieder
         * gelesen.
         */
        public ?string $envVar,

        public string $group,

        public string $label,

        /** string | text | bool | int | select | secret | file */
        public string $type = 'string',

        public mixed $default = null,

        /** @var array<string, string> */
        public array $options = [],

        /**
         * Ob der Wert nach dem Speichern nie wieder angezeigt wird.
         *
         * Passwörter und Schlüssel. Die Oberfläche zeigt dann „gesetzt" und ein
         * leeres Feld: Wer nichts einträgt, ändert nichts.
         */
        public bool $secret = false,

        public ?string $help = null,
    ) {}

    public function isSecret(): bool
    {
        /*
         * "file" ist hier der eingefuegte INHALT einer Schluesseldatei und
         * damit ein Geheimnis. "image" ist ein Logo -- es haengt am Hangar und
         * steht auf der Anmeldeseite. Die beiden auseinanderzuhalten ist der
         * Unterschied zwischen einem Feld, das man wiedersehen darf, und einem,
         * das man nicht wiedersehen darf.
         */
        return $this->secret || $this->type === 'secret' || $this->type === 'file';
    }

    /**
     * Den gespeicherten Text in den Typ bringen, den die Konfiguration erwartet.
     *
     * Ohne das käme aus der Tabelle für einen Schalter die Zeichenkette "0",
     * und die ist in PHP wahr genug, um Ärger zu machen.
     */
    public function cast(?string $wert): mixed
    {
        if ($wert === null) {
            return null;
        }

        return match ($this->type) {
            'bool' => filter_var($wert, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? false,
            'int' => (int) $wert,
            default => $wert,
        };
    }
}
