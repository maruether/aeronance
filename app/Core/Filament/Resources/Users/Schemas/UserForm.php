<?php

declare(strict_types=1);

namespace App\Core\Filament\Resources\Users\Schemas;

use App\Core\Access\CorePermissions;
use App\Core\Identity\ExternalIdentity;
use App\Core\Identity\IdentityProviderRegistry;
use App\Models\User;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;

/**
 * Das Konto — und wem es gehört.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * Vorgabe: „die über einen provider kommen dürfen nur angezeigt, aber nicht
 * verändert werden."
 *
 * DAS BETRIFFT GENAU DIE FELDER, DIE DER ABGLEICH SCHREIBT: Name, Adresse und
 * „Aktiv". LinkExternalIdentity setzt sie bei JEDEM nächtlichen Lauf neu — eine
 * Änderung hier hielte bis 2 Uhr morgens und wäre dann still wieder weg. Ein
 * Eingabefeld, das ein Versprechen gibt, das um 2 Uhr gebrochen wird, ist
 * schlechter als gar keins.
 *
 * Gesperrt heißt hier NICHT „im Browser ausgegraut": Ein gesperrtes
 * Filament-Feld wird nicht mit abgeschickt. Ein manipuliertes Formular kann die
 * Werte also auch dann nicht setzen, wenn jemand das `disabled` im Browser
 * entfernt — die Sperre sitzt auf dem Server.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * WAS AUSDRÜCKLICH OFFEN BLEIBT, und warum das kein Widerspruch ist:
 *
 *  - ROLLEN. Der Provider nimmt nur zurück, was er selbst vergab (Regel 2 in
 *    LinkExternalIdentity) — lokal erteilte Rollen überlebt jeder Abgleich.
 *    Vor allem aber: `certifying_staff` kommt NIE von außen (Regel 4). Wäre die
 *    Auswahl gesperrt, könnte niemand eine Freigabeberechtigung erteilen,
 *    solange die Mitglieder aus Vereinsflieger kommen — also nie.
 *
 *  - QUALIFIKATIONEN (eigener Reiter). Part-66-Nachweise führt dieser Betrieb
 *    selbst; kein Mitgliederverwaltungssystem weiß davon.
 *
 * Der Provider besitzt die Identität. Was jemand hier tun darf, besitzt dieser
 * Betrieb — und das bleibt hier änderbar.
 * ─────────────────────────────────────────────────────────────────────────────
 */
final class UserForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(__('users.section.account'))
                ->schema([
                    TextInput::make('name')
                        ->label(__('users.field.name'))
                        ->required()
                        ->maxLength(255)
                        ->disabled(fn (?User $record): bool => self::fromProvider($record))
                        ->helperText(fn (?User $record): ?string => self::providerHint($record)),

                    /*
                     * Die Adresse gehört dem Provider — bei Vereinsflieger auf
                     * die ausdrückliche Ansage: „bei VF usern einfach den
                     * wert aus dem VF nehmen und automatisch aktualisieren. Wer
                     * seine mail nicht eingibt kommt halt nicht ins system."
                     *
                     * Damit hat auch der Fall der 26 Mitglieder ohne Adresse
                     * eine Antwort, und zwar keine hier: Sie tragen ihre Adresse
                     * in Vereinsflieger nach, dann ist ihr Konto in derselben
                     * Nacht einladbar. Sie hier von Hand einzutragen hieße, sie
                     * bis 2 Uhr morgens einzutragen.
                     */
                    TextInput::make('email')
                        ->label(__('users.field.email'))
                        ->email()
                        ->required()
                        ->maxLength(255)
                        ->unique(ignoreRecord: true)
                        ->disabled(fn (?User $record): bool => self::fromProvider($record))
                        ->helperText(fn (?User $record): ?string => self::providerHint($record)),

                    TextInput::make('password')
                        ->label(__('users.field.password'))
                        ->password()
                        ->revealable()
                        ->rule(Password::min(12)->letters()->numbers())
                        ->dehydrateStateUsing(fn (?string $state): ?string => filled($state)
                            ? Hash::make($state)
                            : null)
                        // Leaving it blank on an existing account keeps the
                        // current password rather than clearing it.
                        ->dehydrated(fn (?string $state): bool => filled($state))
                        ->required(fn (string $operation): bool => $operation === 'create')
                        /*
                         * Bei einem Konto aus einem Provider steht hier nichts.
                         *
                         * Nicht weil der Provider das Passwort besäße — er hat
                         * keins, Vereinsflieger ist kein Identitätsanbieter.
                         * Sondern weil es laut Vorgabe „erst durch einen aktiven
                         * passwort reset durch den user" entsteht. Ein
                         * Administrator, der hier eines setzt, kennt es danach —
                         * und der Weg dafür ist die Einladung, nicht dieses
                         * Feld.
                         */
                        ->visible(fn (?User $record): bool => ! self::fromProvider($record))
                        ->helperText(__('users.help.password')),

                    /*
                     * „Aktiv" führt bei Provider-Konten der Abgleich — die Vorgabe
                     * zu F38: „wer fehlt ist weg." Wer hier jemanden von Hand
                     * sperrt, hat ihn bis 2 Uhr gesperrt.
                     *
                     * Der Schalter dafür sitzt deshalb im Mitgliederverwaltungs-
                     * system: Wer dort ausscheidet oder auf einen ignorierten
                     * Status wechselt, ist in derselben Nacht auch hier draußen.
                     */
                    Toggle::make('is_active')
                        ->label(__('users.field.is_active'))
                        ->default(true)
                        ->disabled(fn (?User $record): bool => self::fromProvider($record))
                        ->helperText(fn (?User $record): string => self::fromProvider($record)
                            ? __('users.help.is_active_from_provider', [
                                'provider' => (string) self::providerLabel($record),
                            ])
                            : __('users.help.is_active')),

                    /*
                     * Woher das Konto kommt — sichtbar, sonst wirken die
                     * gesperrten Felder wie ein Fehler.
                     */
                    TextEntry::make('herkunft')
                        ->label(__('users.field.origin'))
                        ->state(fn (?User $record): string => self::providerLabel($record)
                            ?? __('users.origin.local'))
                        ->visible(fn (string $operation): bool => $operation !== 'create'),

                    /*
                     * Eine bestehende Sperre gehört hierher, weil dies die
                     * Seite ist, die jemand aufschlägt, wenn er wissen will,
                     * warum eine Person nicht hineinkommt. Gesperrt wird aus
                     * der Liste heraus -- hier steht nur, was gilt.
                     */
                    TextEntry::make('sperre')
                        ->label(__('users.field.locked'))
                        ->badge()
                        ->color('danger')
                        ->state(fn (?User $record): string => self::lockLine($record))
                        ->helperText(__('users.help.locked'))
                        ->visible(fn (?User $record): bool => $record?->isLocked() ?? false),
                ])
                ->columns(2),

            Section::make(__('users.section.roles'))
                ->description(__('users.help.roles'))
                ->schema([
                    Select::make('roles')
                        ->label(__('users.field.roles'))
                        ->relationship('roles', 'name')
                        ->multiple()
                        ->preload()
                        ->getOptionLabelFromRecordUsing(fn ($record): string => __('roles.'.$record->name))
                        ->visible(fn (): bool => auth()->user()?->can(CorePermissions::ROLES_MANAGE) ?? false),
                ]),
        ]);
    }

    /**
     * Wer wann gesperrt hat — und warum.
     */
    private static function lockLine(?User $record): string
    {
        if ($record === null || ! $record->isLocked()) {
            return '';
        }

        $datum = $record->locked_at?->format('d.m.Y H:i') ?? '';
        $wer = $record->lockedBy?->name;

        $kopf = $wer === null
            ? __('users.lock.by_unknown', ['date' => $datum])
            : __('users.lock.by', ['date' => $datum, 'name' => $wer]);

        return $record->lock_reason === null
            ? $kopf
            : $kopf.' — '.__('users.lock.reason', ['reason' => $record->lock_reason]);
    }

    /**
     * Stammt dieses Konto aus einem Provider?
     *
     * Beim Anlegen (kein $record) nie — ein Konto, das es noch nicht gibt,
     * kann keinem Provider gehören.
     */
    private static function fromProvider(?User $record): bool
    {
        return self::providerOf($record) !== null;
    }

    /**
     * Der Hinweis am gesperrten Feld — er nennt den Provider beim Namen.
     *
     * Ohne ihn steht dort ein graues Feld ohne Begründung, und der nächste
     * Schritt wäre ein Anruf.
     */
    private static function providerHint(?User $record): ?string
    {
        $label = self::providerLabel($record);

        return $label === null ? null : __('users.help.from_provider', ['provider' => $label]);
    }

    /**
     * Der lesbare Name des Providers, oder null bei einem lokalen Konto.
     */
    private static function providerLabel(?User $record): ?string
    {
        $provider = self::providerOf($record);

        if ($provider === null) {
            return null;
        }

        $registry = app(IdentityProviderRegistry::class);

        return $registry->has($provider) ? $registry->get($provider)->label() : $provider;
    }

    /**
     * Die Kennung des Providers, aus dem dieses Konto stammt.
     */
    private static function providerOf(?User $record): ?string
    {
        if ($record === null || $record->id === null) {
            return null;
        }

        return ExternalIdentity::query()
            ->where('user_id', $record->id)
            ->value('provider');
    }
}
