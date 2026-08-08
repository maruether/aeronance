<?php

declare(strict_types=1);

namespace App\Core\Filament\Pages;

use App\Core\Access\CorePermissions;
use App\Core\Mail\Postman;
use App\Core\Mail\TestMail;
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

                $feld = match ($definition->type) {
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

        // Neu aufbauen, sonst steht der alte Wert weiter im Formular und der
        // Knopf behauptet, es sei nichts passiert.
        $this->mount();

        Notification::make()
            ->title(__('settings.reset_done'))
            ->success()
            ->send();
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
