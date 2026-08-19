<?php

declare(strict_types=1);

namespace App\Modules\Vereinsflieger\Filament\Resources\Connections;

use App\Core\Access\CorePermissions;
use App\Core\Demo\DemoMode;
use App\Modules\Vereinsflieger\Filament\Resources\Connections\Pages\ListConnections;
use App\Modules\Vereinsflieger\Jobs\SyncConnectionJob;
use App\Modules\Vereinsflieger\Models\Connection;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\HtmlString;

/**
 * Die Vereinsflieger-Anbindungen.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * Vorgabe: „ich möchte optional mehrere vereine koppeln können. Hintergrund ist
 * da das cao umfeld."
 *
 * Eine CAO betreut Luftfahrzeuge mehrerer Vereine, und jeder Verein hat seinen
 * eigenen Vereinsflieger. Deshalb sind die Zugaenge Datensaetze und keine
 * Einstellung -- eine Einstellung kann genau einen halten.
 *
 * Recht ist core.settings.manage: Wer hier einen Zugang hinterlegt, konfiguriert
 * die Installation. Das ist dieselbe Sorte Eingriff wie in den Einstellungen,
 * also derselbe Schluessel.
 * ─────────────────────────────────────────────────────────────────────────────
 */
final class ConnectionResource extends Resource
{
    protected static ?string $model = Connection::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedLink;

    protected static ?int $navigationSort = 41;

    protected static ?string $slug = 'vereinsflieger-anbindungen';

    public static function getNavigationGroup(): ?string
    {
        return __('nav.group.system');
    }

    public static function getNavigationLabel(): string
    {
        return __('vereinsflieger.connection.plural');
    }

    public static function getModelLabel(): string
    {
        return __('vereinsflieger.connection.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('vereinsflieger.connection.plural');
    }

    public static function canViewAny(): bool
    {
        return auth()->user()?->can(CorePermissions::SETTINGS_MANAGE) ?? false;
    }

    public static function canCreate(): bool
    {
        return self::canViewAny();
    }

    /** @param  Connection  $record */
    public static function canEdit($record): bool
    {
        return self::canViewAny();
    }

    /** @param  Connection  $record */
    public static function canDelete($record): bool
    {
        return self::canViewAny();
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(__('vereinsflieger.connection.singular'))
                ->schema([
                    TextInput::make('name')
                        ->label(__('vereinsflieger.connection.field.name'))
                        ->required()
                        ->maxLength(128)
                        ->unique(ignoreRecord: true)
                        ->helperText(__('vereinsflieger.connection.help.name')),

                    TextInput::make('username')
                        ->label(__('vereinsflieger.connection.field.username'))
                        ->required()
                        ->maxLength(191)
                        ->helperText(__('vereinsflieger.connection.help.username')),

                    /*
                     * dehydrated(filled(...)): Ein leeres Passwortfeld heisst
                     * "nicht aendern", nicht "loeschen". Der alte Wert wird nie
                     * zurueckgezeigt -- ein Feld, das ihn enthaelt, wandert beim
                     * naechsten Speichern durch den Browser.
                     */
                    /*
                     * IN DER DEMO GESPERRT. Vorgabe: „zugangsdaten zu vf und co
                     * werden nicht gespeichert." Die Felder bleiben sichtbar --
                     * wer sich das Programm ansieht, soll erkennen, was eine
                     * Anbindung braucht -- aber nichts davon geht in die
                     * Datenbank; das Model weist es zusaetzlich ab.
                     */
                    TextInput::make('password')
                        ->label(__('vereinsflieger.connection.field.password'))
                        ->password()
                        ->revealable()
                        ->disabled(fn (): bool => app(DemoMode::class)->isActive())
                        ->helperText(fn (): ?string => app(DemoMode::class)->isActive()
                            ? __('demo.credentials_disabled')
                            : null)
                        ->required(fn (?Connection $record): bool => $record === null
                            && ! app(DemoMode::class)->isActive())
                        ->dehydrated(fn (?string $state): bool => filled($state)),

                    Toggle::make('password_is_hash')
                        ->label(__('vereinsflieger.connection.field.password_is_hash'))
                        ->helperText(__('vereinsflieger.connection.help.password_is_hash')),

                    TextInput::make('app_key')
                        ->label(__('vereinsflieger.connection.field.app_key'))
                        ->password()
                        ->revealable()
                        ->disabled(fn (): bool => app(DemoMode::class)->isActive())
                        ->required(fn (?Connection $record): bool => $record === null
                            && ! app(DemoMode::class)->isActive())
                        ->dehydrated(fn (?string $state): bool => filled($state)),

                    TextInput::make('auth_secret')
                        ->label(__('vereinsflieger.connection.field.auth_secret'))
                        ->password()
                        ->revealable()
                        ->disabled(fn (): bool => app(DemoMode::class)->isActive())
                        ->dehydrated(fn (?string $state): bool => filled($state))
                        ->helperText(__('vereinsflieger.connection.help.auth_secret')),

                    TextInput::make('cid')
                        ->label(__('vereinsflieger.connection.field.cid'))
                        ->default('0')
                        ->required()
                        ->maxLength(32)
                        ->helperText(__('vereinsflieger.connection.help.cid')),

                    /*
                     * ─────────────────────────────────────────────────────────
                     * DER GEFAEHRLICHSTE HAKEN AUF DIESER SEITE.
                     *
                     * Vorgabe: „mit mehreren anbindungen geben wir ggf leuten
                     * zugriff auf ein cao system."
                     *
                     * Genau das ist der Fall: Eine CAO betreut Flugzeuge
                     * mehrerer Vereine. Setzt jemand den Haken bei einem dieser
                     * Vereine, bekommen DESSEN Mitglieder Konten im System der
                     * CAO -- Zugriff auf fremde Daten, mit einem Klick und ohne
                     * dass es jemandem auffaellt.
                     *
                     * Deshalb: ab Werk AUS, in Rot beschriftet, und die
                     * Warnung steht am Feld statt in einer Doku.
                     * ─────────────────────────────────────────────────────────
                     */
                    Toggle::make('provides_identities')
                        ->label(__('vereinsflieger.connection.field.provides_identities'))
                        ->default(false)
                        ->onColor('danger')
                        ->helperText(new HtmlString(
                            '<span class="text-danger-600 dark:text-danger-400">'
                            .e(__('vereinsflieger.connection.help.provides_identities'))
                            .'</span>'
                        )),

                    Toggle::make('is_active')
                        ->label(__('vereinsflieger.connection.field.is_active'))
                        ->default(true)
                        ->helperText(__('vereinsflieger.connection.help.is_active')),
                ])
                ->columns(2),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label(__('vereinsflieger.connection.field.name'))
                    ->searchable()
                    ->sortable(),

                IconColumn::make('provides_identities')
                    ->label(__('vereinsflieger.connection.field.provides_identities'))
                    ->boolean(),

                TextColumn::make('aircraft_links_count')
                    ->label(__('vereinsflieger.connection.field.aircraft'))
                    ->counts('aircraftLinks')
                    ->badge()
                    ->color('gray'),

                /*
                 * Der letzte Lauf steht in der LISTE und nicht in einem Log:
                 * Eine Anbindung, die seit drei Wochen scheitert, soll man
                 * sehen, ohne danach zu suchen.
                 */
                TextColumn::make('last_run_at')
                    ->label(__('vereinsflieger.connection.field.last_run'))
                    ->dateTime('d.m.Y H:i')
                    ->placeholder(__('vereinsflieger.connection.never_run'))
                    ->description(fn (Connection $record): ?string => $record->last_error)
                    ->color(fn (Connection $record): string => $record->last_error !== null ? 'danger' : 'gray'),

                IconColumn::make('is_active')
                    ->label(__('vereinsflieger.connection.field.is_active'))
                    ->boolean(),
            ])
            ->defaultSort('name')
            ->emptyStateHeading(__('vereinsflieger.connection.empty'))
            ->emptyStateDescription(__('vereinsflieger.connection.empty_help'))
            ->headerActions([CreateAction::make()])
            ->recordActions([
                /*
                 * Der volle Abgleich am Knopf -- derselbe Ablauf wie nachts um
                 * zwei (RunConnectionSync), als Job im Worker: Gemessen dauert
                 * er eine gute halbe Minute, und die Rueckmeldung aus dem
                 * Betrieb war woertlich "es dauert und es wird nicht darauf
                 * hingewiesen". Deshalb sagt die Bestaetigung BEIDES: dass es
                 * dauert, und wo das Ergebnis erscheint.
                 */
                Action::make('sync')
                    ->label(__('vereinsflieger.connection.sync'))
                    ->icon(Heroicon::OutlinedArrowPath)
                    ->requiresConfirmation()
                    ->modalHeading(__('vereinsflieger.connection.sync_heading'))
                    ->modalDescription(__('vereinsflieger.connection.sync_confirm'))
                    ->visible(fn (Connection $record): bool => $record->is_active)
                    ->action(function (Connection $record): void {
                        SyncConnectionJob::dispatch($record->getKey());

                        Notification::make()
                            ->success()
                            ->title(__('vereinsflieger.connection.sync_started'))
                            ->body(__('vereinsflieger.connection.sync_started_hint'))
                            ->persistent()
                            ->send();
                    }),
                EditAction::make(),
                DeleteAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListConnections::route('/'),
        ];
    }
}
