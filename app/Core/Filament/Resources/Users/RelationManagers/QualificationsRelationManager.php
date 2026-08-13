<?php

declare(strict_types=1);

namespace App\Core\Filament\Resources\Users\RelationManagers;

use App\Core\Access\CorePermissions;
use App\Core\Contracts\AircraftDirectory;
use App\Core\Models\Qualification;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\SpatieMediaLibraryFileUpload;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * Qualifications held by a person.
 *
 * A tab rather than a section of the account form, because a qualification is
 * not a property of the account: it comes from outside, it expires, and the
 * same person may hold several at once with different scopes.
 *
 * Recording one is an assertion that the credential exists -- which is why it
 * has its own permission and is not part of ordinary account administration.
 */
final class QualificationsRelationManager extends RelationManager
{
    protected static string $relationship = 'qualifications';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('qualifications.plural');
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('type')
                ->label(__('qualifications.field.type'))
                ->options([
                    Qualification::TYPE_PART66 => __('qualifications.type.part66_licence'),
                    Qualification::TYPE_PILOT_OWNER => __('qualifications.type.pilot_owner_authorisation'),
                    Qualification::TYPE_TRAINING => __('qualifications.type.training_certificate'),
                ])
                ->required()
                ->live(),

            /*
             * WORUM es ging. Nur beim Schulungsnachweis: Bei einer Lizenz ist
             * die Nummer zugleich die Bezeichnung, ein zweites Feld waere dort
             * eine Frage ohne Antwort.
             */
            TextInput::make('subject')
                ->label(__('qualifications.field.subject'))
                ->maxLength(200)
                ->placeholder(__('qualifications.placeholder.subject'))
                ->helperText(__('qualifications.help.subject'))
                ->required(fn (callable $get): bool => $get('type') === Qualification::TYPE_TRAINING)
                ->visible(fn (callable $get): bool => $get('type') === Qualification::TYPE_TRAINING),

            TextInput::make('reference')
                ->label(__('qualifications.field.reference'))
                ->maxLength(128)
                ->helperText(__('qualifications.help.reference')),

            /*
             * BEI WEM. Bei einer Schulung die halbe Aussage -- ohne Aussteller
             * ist ein Zertifikat eine Behauptung ohne Absender. Bei Lizenzen
             * ebenfalls sinnvoll (die Behoerde), deshalb immer sichtbar.
             */
            TextInput::make('issuer')
                ->label(__('qualifications.field.issuer'))
                ->maxLength(160)
                ->placeholder(__('qualifications.placeholder.issuer'))
                ->helperText(__('qualifications.help.issuer')),

            TextInput::make('category')
                ->label(__('qualifications.field.category'))
                ->maxLength(64)
                ->placeholder('B1.2')
                ->visible(fn (callable $get): bool => $get('type') === Qualification::TYPE_PART66),

            // The heart of E8: a pilot-owner authorisation is valid for the one
            // aircraft the person is entered against in its maintenance
            // programme, not in general.
            //
            // ALS AUSWAHL, wenn die Flotte eine Liste liefert (Feldtest: "wäre
            // es schön wenn ich in dem LFZ Feld eine Auswahlliste hätte") --
            // ein Freitext produziert "d-kabc" und "D-KABC ", und die
            // Rechteprüfung erkennt davon nur eine wieder. Über die
            // AircraftDirectory-Naht, nie über Flottentabellen; ohne
            // Flottenmodul bleibt es Freitext, und der Kern läuft allein.
            ...self::scopeField(),

            /*
             * Die Urkunde selbst, auf der privaten Ablage -- sie enthaelt
             * personenbezogene Daten und hat im Webroot nichts verloren.
             */
            SpatieMediaLibraryFileUpload::make('certificate')
                ->label(__('qualifications.field.certificate'))
                ->collection(Qualification::DOCUMENTS)
                ->disk('documents')
                ->acceptedFileTypes(['application/pdf', 'image/jpeg', 'image/png'])
                ->maxSize(10240)
                ->helperText(__('qualifications.help.certificate')),

            DatePicker::make('valid_from')
                ->label(__('qualifications.field.valid_from'))
                ->required()
                ->default(now()),

            DatePicker::make('valid_until')
                ->label(__('qualifications.field.valid_until'))
                ->after('valid_from')
                ->helperText(__('qualifications.help.valid_until')),

            Textarea::make('note')
                ->label(__('qualifications.field.note'))
                ->rows(2)
                ->columnSpanFull(),
        ])->columns(2);
    }

    /**
     * Das Kennzeichen-Feld -- Auswahl, wenn die Flotte liefert, sonst Freitext.
     *
     * @return list<Select|TextInput>
     */
    private static function scopeField(): array
    {
        $kennzeichen = app(AircraftDirectory::class)->registrations();

        $sichtbar = fn (callable $get): bool => $get('type') === Qualification::TYPE_PILOT_OWNER;

        if ($kennzeichen === []) {
            return [
                TextInput::make('scope')
                    ->label(__('qualifications.field.scope'))
                    ->maxLength(64)
                    ->placeholder('D-KABC')
                    ->required($sichtbar)
                    ->visible($sichtbar)
                    ->helperText(__('qualifications.hint.pilot_owner')),
            ];
        }

        return [
            Select::make('scope')
                ->label(__('qualifications.field.scope'))
                ->options(array_combine($kennzeichen, $kennzeichen))
                ->searchable()
                ->required($sichtbar)
                ->visible($sichtbar)
                ->helperText(__('qualifications.hint.pilot_owner')),
        ];
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('type')
                    ->label(__('qualifications.field.type'))
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => __('qualifications.type.'.$state)),

                TextColumn::make('reference')
                    ->label(__('qualifications.field.reference'))
                    ->placeholder('—'),

                TextColumn::make('category')
                    ->label(__('qualifications.field.category'))
                    ->placeholder('—'),

                TextColumn::make('scope')
                    ->label(__('qualifications.field.scope'))
                    ->formatStateUsing(fn (?string $state): string => $state ?? __('qualifications.scope.general'))
                    ->badge()
                    ->color(fn (?string $state): string => $state === null ? 'gray' : 'info'),

                TextColumn::make('valid_until')
                    ->label(__('qualifications.field.valid_until'))
                    ->date('d.m.Y')
                    ->placeholder(__('qualifications.no_end_date'))
                    // An expired licence covers nothing, and that is easy to
                    // miss in a list.
                    ->color(fn (Qualification $record): ?string => $record->isValidOn() ? null : 'danger')
                    ->description(fn (Qualification $record): ?string => $record->isValidOn()
                        ? null
                        : __('qualifications.expired')),
            ])
            ->headerActions([
                CreateAction::make()
                    ->label(__('qualifications.add'))
                    ->visible(fn (): bool => auth()->user()?->can(CorePermissions::QUALIFICATIONS_MANAGE) ?? false),
            ])
            ->recordActions([
                EditAction::make()
                    ->visible(fn (): bool => auth()->user()?->can(CorePermissions::QUALIFICATIONS_MANAGE) ?? false),
                DeleteAction::make()
                    ->visible(fn (): bool => auth()->user()?->can(CorePermissions::QUALIFICATIONS_MANAGE) ?? false),
            ]);
    }

    public function isReadOnly(): bool
    {
        return ! (auth()->user()?->can(CorePermissions::QUALIFICATIONS_MANAGE) ?? false);
    }
}
