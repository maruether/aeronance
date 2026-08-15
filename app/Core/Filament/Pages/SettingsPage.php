<?php

declare(strict_types=1);

namespace App\Core\Filament\Pages;

use App\Core\Access\CorePermissions;
use App\Core\Mail\Postman;
use App\Core\Mail\TestMail;
use App\Core\Settings\SettingDefinition;
use App\Core\Settings\SettingOptions;
use App\Core\Settings\Settings;
use App\Core\Settings\SettingsCatalogue;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Die Einstellungen des Vereins -- alles, was sonst in Dateien stünde.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * Vorgabe: "Ziel muss es sein die Konsole nur für das Starten des fertig
 * runtergeladenen Dockers und für den Break-glass zu benötigen. Wir können den
 * Usern nicht zumuten alles mögliche in config files zu schreiben."
 *
 * Diese Seite ist die Antwort darauf. Was hier NICHT steht, sind die zwei
 * Werte, die hier nicht stehen können: der APP_KEY, der diese Tabelle
 * entschlüsselt, und der Datenbankzugang, über den sie erreicht wird.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * DREI DINGE, DIE DIE OBERFLÄCHE SAGEN MUSS, weil sie sonst lügt:
 *
 *  1. WOHER EIN WERT KOMMT. Ein Feld, das aus der Umgebung gefüllt ist, sieht
 *     aus wie ein gesetztes -- ist es aber nicht: Es kann sich beim nächsten
 *     Container-Start ändern. Sobald jemand speichert, gilt die Datenbank, und
 *     die Umgebung wird für diesen Schlüssel nie wieder gelesen.
 *
 *  2. GEHEIMNISSE WERDEN NICHT ZURÜCKGEZEIGT. Ein Passwortfeld, das den alten
 *     Wert enthält, wandert beim nächsten Speichern durch den Browser und steht
 *     im Zweifel im Verlauf. Hier steht "gesetzt" und ein leeres Feld: Wer
 *     nichts einträgt, ändert nichts.
 *
 *  3. WAS EINE EINSTELLUNG BEDEUTET. Zu jeder gehört ein Satz, der sagt, was
 *     sie bewirkt -- nicht, wie sie heisst. "Ohne erreichbaren Scanner keine
 *     Uploads" ist eine Entscheidung; "clamav.fail_closed" ist ein Bezeichner.
 * ─────────────────────────────────────────────────────────────────────────────
 */
class SettingsPage extends Page implements HasForms
{
    use InteractsWithForms;

    protected string $view = 'core.filament.pages.settings';

    protected static ?int $navigationSort = 90;

    protected static ?string $slug = 'einstellungen';

    /** @var array<string, mixed> */
    public array $data = [];

    public static function getNavigationGroup(): ?string
    {
        return __('nav.group.system');
    }

    public static function getNavigationLabel(): string
    {
        return __('settings.title');
    }

    public function getTitle(): string|Htmlable
    {
        return __('settings.title');
    }

    public function getSubheading(): string|Htmlable|null
    {
        return __('settings.subheading');
    }

    public static function getNavigationIcon(): string|BackedEnum|null
    {
        return Heroicon::OutlinedCog6Tooth;
    }

    public static function canAccess(): bool
    {
        return auth()->user()?->can(CorePermissions::SETTINGS_MANAGE) ?? false;
    }

    public function mount(): void
    {
        $settings = app(Settings::class);
        $werte = [];

        foreach (SettingsCatalogue::all() as $definition) {
            // Geheimnisse werden NIE vorgefüllt -- siehe Kopf. Ein Logo schon:
            // es ist kein Geheimnis, und ohne Vorbelegung wuerde jedes Speichern
            // einer anderen Einstellung es entfernen.
            $werte[$this->fieldName($definition->key)] = $definition->isSecret()
                ? null
                : $settings->get($definition->key);
        }

        $this->form->fill($werte);
    }

    /**
     * Der Testversand — mit dem, was gerade im Formular steht.
     *
     * ─────────────────────────────────────────────────────────────────────────
     * DER FALL, DEN DAS VERHINDERT: Ein Verein trägt SMTP-Daten ein, jemand
     * vertippt sich beim Passwort, und niemand merkt es. Wochen später braucht
     * ein Mitglied ein neues Passwort, drückt „vergessen", bekommt eine
     * Bestätigung — und wartet. Der Fehler steht im Log, das keiner liest.
     *
     * Denselben Dienst leistet `aeronance:mail-test`. Der Knopf ist trotzdem
     * kein Doppel: Wer Einstellungen in der Oberfläche pflegt, hat in dem
     * Moment keine Konsole — und ein Prüfschritt, für den man erst auf den
     * Server muss, wird nicht gemacht.
     *
     * ─────────────────────────────────────────────────────────────────────────
     * GEPRÜFT WIRD, WAS IM FORMULAR STEHT, NICHT WAS GESPEICHERT IST.
     *
     * Sonst müsste man erst speichern, um zu erfahren, ob der Zugang stimmt --
     * und hätte im Fehlerfall einen kaputten Zugang in der Datenbank. Die
     * Werte werden dafür nur für diesen einen Versand über die Konfiguration
     * gelegt; gespeichert wird nichts.
     *
     * EIN LEERES GEHEIMFELD HEISST AUCH HIER „NICHT ÄNDERN". Passwörter werden
     * nie zurückgezeigt (siehe mount()), das Feld ist also im Regelfall leer.
     * Es als leeres Passwort zu senden hiesse, einen Fehlschlag zu melden, den
     * es nicht gibt -- deshalb tritt dann der gespeicherte Wert ein, genau wie
     * beim Speichern.
     * ─────────────────────────────────────────────────────────────────────────
     */
    public function testMailAction(): Action
    {
        return Action::make('testMail')
            ->label(__('settings.mail_test.action'))
            ->icon('heroicon-o-paper-airplane')
            ->color('gray')
            ->modalHeading(__('settings.mail_test.heading'))
            ->modalDescription(__('settings.mail_test.description'))
            ->schema([
                TextInput::make('empfaenger')
                    ->label(__('settings.mail_test.recipient'))
                    ->email()
                    ->required()
                    ->default(fn (): ?string => auth()->user()?->email),
            ])
            ->action(function (array $data): void {
                abort_unless(
                    auth()->user()?->can(CorePermissions::SETTINGS_MANAGE) ?? false,
                    403,
                );

                $this->applyMailSettingsFromForm();

                if (! Postman::configured()) {
                    Notification::make()
                        ->warning()
                        ->title(__('settings.mail_test.not_configured'))
                        ->send();

                    return;
                }

                try {
                    Mail::to($data['empfaenger'])->send(new TestMail);
                } catch (Throwable $e) {
                    /*
                     * Die Begruendung des Servers wird DURCHGEREICHT.
                     * „Versand fehlgeschlagen" allein zwingt denjenigen, der es
                     * liest, in die Logdatei -- und genau das war der Zustand,
                     * den dieser Knopf abschaffen soll.
                     */
                    Notification::make()
                        ->danger()
                        ->title(__('settings.mail_test.failed'))
                        ->body($e->getMessage())
                        ->persistent()
                        ->send();

                    return;
                }

                Notification::make()
                    ->success()
                    ->title(__('settings.mail_test.sent', ['empfaenger' => $data['empfaenger']]))
                    ->body(__('settings.mail_test.sent_hint'))
                    ->persistent()
                    ->send();
            });
    }

    /**
     * Die Mail-Einstellungen aus dem Formular in die Konfiguration legen.
     *
     * Nur für diesen Aufruf. Der Katalog kennt die Zuordnung Schlüssel →
     * Konfigurationspfad bereits; sie hier zu wiederholen hiesse, sie beim
     * nächsten neuen Feld an zwei Stellen pflegen zu müssen.
     */
    private function applyMailSettingsFromForm(): void
    {
        $eingaben = $this->form->getState();
        $settings = app(Settings::class);

        foreach (SettingsCatalogue::all() as $definition) {
            if ($definition->group !== SettingsCatalogue::GROUP_MAIL) {
                continue;
            }

            $wert = $eingaben[$this->fieldName($definition->key)] ?? null;

            if ($definition->isSecret() && ($wert === null || $wert === '')) {
                $wert = $settings->get($definition->key);
            }

            config()->set($definition->configPath, $wert);
        }

        /*
         * Ohne das bliebe `mail.default` auf „log", und der Testversand landete
         * in einer Datei statt beim Empfaenger -- mit Erfolgsmeldung. Genau der
         * stille Fehlschlag, gegen den dieser Knopf gebaut ist.
         */
        if (Postman::configured()) {
            config()->set('mail.default', 'smtp');
            config()->set('mail.from.name', Postman::fromName());
        }
    }

    public function form(Schema $schema): Schema
    {
        $settings = app(Settings::class);
        $abschnitte = [];

        foreach (SettingsCatalogue::byGroup() as $gruppe => $definitionen) {
            $felder = [];

            foreach ($definitionen as $definition) {
                $herkunft = $settings->sourceOf($definition->key);
                $name = $this->fieldName($definition->key);

                $hinweis = trim(implode(' ', array_filter([
                    $definition->help(),
                    $definition->isSecret() && $settings->get($definition->key) !== null
                        ? __('settings.secret_set')
                        : null,
                    $herkunft === 'umgebung' ? __('settings.from_environment') : null,
                ])));

                /*
                 * Eine vom Modul gemeldete Auswahlliste schlaegt den Feldtyp:
                 * Wo eine Liste existiert (z. B. die Arbeitsstunden-Kategorien
                 * aus Vereinsflieger), fragt niemand nach einer nackten
                 * Nummer. Ist die Liste leer oder das Modul aus, gilt wieder
                 * der Katalog -- siehe SettingOptions.
                 */
                $dynamisch = app(SettingOptions::class)->for($definition->key);

                if ($dynamisch !== null && $dynamisch !== []) {
                    $gespeichert = $settings->get($definition->key);

                    if (is_string($gespeichert) && $gespeichert !== '' && ! array_key_exists($gespeichert, $dynamisch)) {
                        // Der konfigurierte Wert steht nicht (mehr) in der
                        // Liste. Ihn stumm zu verschlucken hiesse, dass das
                        // Formular etwas anderes zeigt, als gilt.
                        $dynamisch[$gespeichert] = $gespeichert.' '.__('settings.catalogue.'.$definition->key.'.unknown_suffix');
                    }

                    // Nativ, nicht searchable: Die Listen sind kurz (gemessen
                    // sechs Kategorien), und ein natives Select traegt seine
                    // Optionen im HTML -- sichtbar auch fuer den Test.
                    $feld = Select::make($name)->options($dynamisch);
                } else {
                    $feld = $this->fieldByType($definition, $name);
                }

                /*
                 * ─────────────────────────────────────────────────────────────
                 * ZURUECKSETZEN, und zwar je Feld.
                 *
                 * Ohne das gaebe es keinen Weg zurueck: Ein einmal gesetzter
                 * Wert gewinnt fuer immer gegen die Umgebung, und wer ihn wieder
                 * aus der docker-compose.yml beziehen will, muesste die Zeile in
                 * der Tabelle von Hand loeschen -- also doch wieder auf die
                 * Konsole.
                 *
                 * Angeboten nur, wo tatsaechlich ein gespeicherter Wert liegt.
                 * Ein Knopf, der nichts tut, ist eine Frage, die niemand
                 * beantworten kann.
                 * ─────────────────────────────────────────────────────────────
                 */
                $feld = $this->applyOffsiteDynamics($definition, $feld);

                if ($herkunft === 'datenbank') {
                    $feld = $feld->hintAction(
                        Action::make('reset__'.$name)
                            ->label(__('settings.reset'))
                            ->requiresConfirmation()
                            ->modalDescription(__('settings.reset_confirm'))
                            ->action(fn () => $this->resetSetting($definition->key)),
                    );
                }

                $felder[] = $feld
                    ->label($definition->label())
                    ->helperText($hinweis !== '' ? $hinweis : null);
            }

            $abschnitt = Section::make(__('settings.group.'.$gruppe))
                ->description(__('settings.group_help.'.$gruppe))
                ->schema($felder)
                ->columns(2);

            /*
             * Der Testversand steht IM Mail-Abschnitt, nicht oben auf der
             * Seite: Man drueckt ihn direkt nachdem man den Zugang eingetippt
             * hat, und dort sucht man ihn auch. Ein Knopf am Seitenkopf gehoert
             * zu allem und damit zu nichts.
             */
            if ($gruppe === SettingsCatalogue::GROUP_MAIL) {
                $abschnitt = $abschnitt->footerActions([$this->testMailAction()]);
            }

            $abschnitte[] = $abschnitt;
        }

        return $schema->components($abschnitte)->statePath('data');
    }

    /**
     * Welches Auslagerungs-Feld zu welchem Ziel gehoert.
     *
     * ─────────────────────────────────────────────────────────────────────────
     * Vorgabe aus dem Betrieb: Die Auslagerung "sollte dynamisch sein und nur
     * die felder abfragen die für den entsprechenden typen gewünscht sind".
     * Vorher standen dreizehn Felder nebeneinander -- SFTP-Zugang, S3-Schluessel
     * und Verzeichnispfad gleichzeitig, obwohl immer nur eines davon gilt.
     * ─────────────────────────────────────────────────────────────────────────
     */
    private const OFFSITE_FIELD_DISK = [
        'backup.offsite.path' => 'offsite_local',
        'backup.sftp.host' => 'offsite_sftp',
        'backup.sftp.port' => 'offsite_sftp',
        'backup.sftp.username' => 'offsite_sftp',
        'backup.sftp.password' => 'offsite_sftp',
        'backup.sftp.private_key' => 'offsite_sftp',
        'backup.sftp.root' => 'offsite_sftp',
        'backup.s3.key' => 'offsite_s3',
        'backup.s3.secret' => 'offsite_s3',
        'backup.s3.region' => 'offsite_s3',
        'backup.s3.bucket' => 'offsite_s3',
        'backup.s3.endpoint' => 'offsite_s3',
    ];

    /**
     * Die Auslagerungs-Sektion reagiert auf ihre eigenen Antworten.
     *
     * Und sie ist GESPERRT, solange keine Backup-Verschluesselung eingestellt
     * ist: Der Lauf scheitert in dem Fall ohnehin (siehe Katalogtext der
     * Verschluesselung) -- ein Ziel-Feld, das sich trotzdem ausfuellen laesst,
     * verspraeche etwas, das nicht passieren wird. Die Sperre hier ist also
     * Anzeige der bestehenden Regel, nicht die Regel selbst; durchgesetzt
     * wird sie vom Backup-Lauf, den kein Formular umgehen kann.
     */
    private function applyOffsiteDynamics(
        SettingDefinition $definition,
        Toggle|TextInput|Select|Textarea|FileUpload $feld,
    ): Toggle|TextInput|Select|Textarea|FileUpload {
        $zielFeld = $this->fieldName('backup.offsite.disk');
        $verschluesselungsFeld = $this->fieldName('backup.encryption.mode');

        if ($definition->key === 'backup.encryption.mode') {
            // live(), damit die Sperre der Auslagerung sofort aufgeht, wenn
            // jemand die Verschluesselung einstellt -- nicht erst nach Speichern.
            return $feld->live();
        }

        if ($definition->key === 'backup.offsite.disk') {
            return $feld
                ->live()
                ->disabled(fn (callable $get): bool => ($get($verschluesselungsFeld) ?? 'none') === 'none'
                    && blank($get($zielFeld)))
                // Auch ausgegraut mitschicken, sonst laese das Speichern einer
                // beliebigen anderen Einstellung dieses Feld als "leeren".
                ->dehydrated()
                ->hint(fn (callable $get): ?string => ($get($verschluesselungsFeld) ?? 'none') === 'none'
                    ? __('settings.offsite_locked')
                    : null);
        }

        $ziel = self::OFFSITE_FIELD_DISK[$definition->key] ?? null;

        if ($ziel !== null) {
            return $feld->visible(fn (callable $get): bool => $get($zielFeld) === $ziel);
        }

        if (in_array($definition->key, ['backup.offsite.prefix', 'backup.offsite.keep'], true)) {
            // Die beiden gemeinsamen Felder brauchen ein Ziel, egal welches.
            return $feld->visible(fn (callable $get): bool => filled($get($zielFeld)));
        }

        return $feld;
    }

    /**
     * Das gewoehnliche Feld einer Einstellung -- der Typ kommt aus dem Katalog.
     */
    private function fieldByType(SettingDefinition $definition, string $name): Toggle|TextInput|Select|Textarea|FileUpload
    {
        return match ($definition->type) {
            'bool' => Toggle::make($name),
            'int' => TextInput::make($name)->numeric(),
            'select' => Select::make($name)->options($definition->options()),
            'secret' => TextInput::make($name)->password()->revealable(),
            'file' => Textarea::make($name)->rows(4),
            'image' => FileUpload::make($name)
                ->image()
                // Whitelist statt "alles was nach Bild aussieht": ein
                // SVG darf Skript enthalten, und das Logo wird ohne
                // Anmeldung ausgeliefert.
                ->acceptedFileTypes(['image/png', 'image/jpeg', 'image/webp'])
                ->maxSize(1024)
                ->disk('local')
                ->directory('branding')
                // Erzeugter Name: ein hochgeladener Dateiname ist
                // Fremdeingabe und hat im Dateisystem nichts verloren.
                ->visibility('private'),
            'text' => Textarea::make($name)->rows(3),
            default => TextInput::make($name),
        };
    }

    /**
     * Einen Wert wieder auf Umgebung bzw. Vorgabe zurueckfallen lassen.
     */
    public function resetSetting(string $key): void
    {
        abort_unless(
            auth()->user()?->can(CorePermissions::SETTINGS_MANAGE) ?? false,
            403,
        );

        $settings = app(Settings::class);
        $settings->forget($key);
        $settings->applyToConfig();

        Notification::make()
            ->title(__('settings.reset_done'))
            ->success()
            ->send();

        // Wie beim Speichern: Der Panel-Rahmen trägt den Wert ebenfalls.
        $this->redirect(static::getUrl());
    }

    public function save(): void
    {
        abort_unless(
            auth()->user()?->can(CorePermissions::SETTINGS_MANAGE) ?? false,
            403,
        );

        $settings = app(Settings::class);
        $eingaben = $this->form->getState();

        foreach (SettingsCatalogue::all() as $definition) {
            $wert = $eingaben[$this->fieldName($definition->key)] ?? null;

            /*
             * EIN LEERES GEHEIMFELD HEISST "NICHT ÄNDERN", nicht "löschen".
             *
             * Das Feld ist absichtlich leer -- der alte Wert wird nie
             * zurückgezeigt. Ein leeres Feld als Löschbefehl zu lesen, würde bei
             * jedem Speichern einer beliebigen anderen Einstellung das
             * Backup-Passwort entfernen. Wer löschen will, nimmt den Weg über
             * "zurücksetzen".
             */
            if ($definition->isSecret() && ($wert === null || $wert === '')) {
                continue;
            }

            $settings->set($definition->key, $wert);
        }

        $settings->applyToConfig();

        Notification::make()
            ->title(__('settings.saved'))
            ->success()
            ->send();

        /*
         * VOLLSTÄNDIG NEU LADEN, nicht nur das Formular.
         *
         * Feldtest: "der namen in der Kopfzeile aktualisiert sich nicht bei
         * änderung in den einstellungen." Livewire tauscht nur den Bereich
         * aus, den diese Komponente rendert -- Kopfzeile, Seitenleiste und
         * Logo gehören zum Panel-Rahmen und stehen weiter mit dem alten Wert
         * da, bis jemand die Seite neu lädt. Wer den Vereinsnamen ändert,
         * will ihn oben stehen sehen, nicht raten, ob es geklappt hat.
         *
         * Die Meldung überlebt den Sprung: Filament reicht Benachrichtigungen
         * über die Sitzung weiter.
         */
        $this->redirect(static::getUrl());
    }

    /**
     * Punkte sind in Formularnamen Pfadtrenner -- "backup.sftp.host" würde ein
     * verschachteltes Feld erzeugen, das nie ankommt.
     */
    private function fieldName(string $key): string
    {
        return str_replace('.', '__', $key);
    }
}
