<?php

declare(strict_types=1);

namespace App\Modules\Vereinsflieger\Filament\Resources\AircraftLinks;

use App\Core\Access\CorePermissions;
use App\Core\Modules\ModuleManager;
use App\Modules\Vereinsflieger\Filament\Resources\AircraftLinks\Pages\ListAircraftLinks;
use App\Modules\Vereinsflieger\Models\AircraftLink;
use App\Modules\Vereinsflieger\Models\Connection;
use BackedEnum;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Facades\DB;

/**
 * Welches Luftfahrzeug zu welcher Anbindung gehoert.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * Vorgabe: „dazu brauchen wir an den luftfahrzeugen (fleet modul) die auswahl
 * ob, und zu welcher VF API kopplung sie gehören."
 *
 * DIE SEITE LIEGT IM VF-MODUL UND NICHT IN DER FLOTTE, obwohl es um
 * Luftfahrzeuge geht. Grund ist die Modulgrenze: Die Flotte muss ohne dieses
 * Modul laufen -- ein Feld „VF-Anbindung" an ihrem Formular waere eine
 * Abhaengigkeit in die falsche Richtung. Der Verweis geht deshalb von hier nach
 * dort.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * DIE LUFTFAHRZEUGE KOMMEN UEBER DEN QUERY BUILDER, nicht ueber das Model der
 * Flotte. Die Tabellen bestehen immer (D1), auch wenn das Modul aus ist; die
 * KLASSE zu benutzen waere die Abhaengigkeit, die die Grenzpruefung verbietet.
 * Ist die Flotte aus, verschwindet die Seite ganz -- ohne Luftfahrzeuge gibt es
 * nichts zu koppeln.
 * ─────────────────────────────────────────────────────────────────────────────
 */
final class AircraftLinkResource extends Resource
{
    protected static ?string $model = AircraftLink::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedPaperAirplane;

    protected static ?int $navigationSort = 42;

    protected static ?string $slug = 'vereinsflieger-luftfahrzeuge';

    public static function getNavigationGroup(): ?string
    {
        return __('nav.group.system');
    }

    public static function getNavigationLabel(): string
    {
        return __('vereinsflieger.link.plural');
    }

    public static function getModelLabel(): string
    {
        return __('vereinsflieger.link.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('vereinsflieger.link.plural');
    }

    public static function shouldRegisterNavigation(): bool
    {
        return app(ModuleManager::class)->isEnabled('fleet');
    }

    public static function canViewAny(): bool
    {
        return (auth()->user()?->can(CorePermissions::SETTINGS_MANAGE) ?? false)
            && app(ModuleManager::class)->isEnabled('fleet');
    }

    public static function canCreate(): bool
    {
        return self::canViewAny();
    }

    /** @param  AircraftLink  $record */
    public static function canEdit($record): bool
    {
        return self::canViewAny();
    }

    /** @param  AircraftLink  $record */
    public static function canDelete($record): bool
    {
        return self::canViewAny();
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('connection_id')
                ->label(__('vereinsflieger.link.field.connection'))
                ->options(fn (): array => Connection::query()->orderBy('name')->pluck('name', 'id')->all())
                ->required()
                ->native(false),

            Select::make('aircraft_id')
                ->label(__('vereinsflieger.link.field.aircraft'))
                ->options(fn (): array => self::aircraftOptions())
                ->required()
                ->searchable()
                ->native(false)
                ->live()
                // Ein Luftfahrzeug haengt an genau einer Anbindung.
                ->unique(ignoreRecord: true)
                /*
                 * Das Kennzeichen wird VORBELEGT, nicht erzwungen. Vorgabe: * „geht nach kennzeichen, da ist einfach davon auszugehen das
                 * es eingetragen wird wie es am lfz steht." Aenderbar bleibt es
                 * trotzdem -- falls Vereinsflieger es anders schreibt, ist eine
                 * Korrektur hier besser als ein Abgleich, der stumm nichts
                 * findet.
                 */
                ->afterStateUpdated(function (Set $set, Get $get, ?string $state): void {
                    if ($state === null || filled($get('callsign'))) {
                        return;
                    }

                    $set('callsign', self::registrationOf((int) $state));
                }),

            TextInput::make('callsign')
                ->label(__('vereinsflieger.link.field.callsign'))
                ->required()
                ->maxLength(32)
                ->helperText(__('vereinsflieger.link.help.callsign')),

            Toggle::make('is_active')
                ->label(__('vereinsflieger.link.field.is_active'))
                ->default(true)
                ->helperText(__('vereinsflieger.link.help.is_active')),
        ])->columns(2);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('callsign')
                    ->label(__('vereinsflieger.link.field.callsign'))
                    ->searchable()
                    ->sortable()
                    ->description(fn (AircraftLink $record): ?string => self::registrationOf((int) $record->aircraft_id)),

                TextColumn::make('connection.name')
                    ->label(__('vereinsflieger.link.field.connection'))
                    ->badge()
                    ->color('gray')
                    ->sortable(),

                TextColumn::make('last_read_at')
                    ->label(__('vereinsflieger.link.field.last_read'))
                    ->dateTime('d.m.Y H:i')
                    ->placeholder(__('vereinsflieger.connection.never_run'))
                    ->description(fn (AircraftLink $record): ?string => $record->last_error)
                    ->color(fn (AircraftLink $record): string => $record->last_error !== null ? 'danger' : 'gray'),

                IconColumn::make('is_active')
                    ->label(__('vereinsflieger.link.field.is_active'))
                    ->boolean(),
            ])
            ->defaultSort('callsign')
            ->emptyStateHeading(__('vereinsflieger.link.empty'))
            ->headerActions([CreateAction::make()])
            ->recordActions([EditAction::make(), DeleteAction::make()]);
    }

    /**
     * Die Luftfahrzeuge der Flotte -- ueber die Tabelle, nicht ueber das Model.
     *
     * @return array<int, string>
     */
    private static function aircraftOptions(): array
    {
        return DB::table('aircraft')
            ->whereNull('deleted_at')
            ->orderBy('registration')
            ->pluck('registration', 'id')
            ->all();
    }

    private static function registrationOf(int $aircraftId): ?string
    {
        $kennzeichen = DB::table('aircraft')->where('id', $aircraftId)->value('registration');

        return $kennzeichen !== null ? (string) $kennzeichen : null;
    }

    public static function getPages(): array
    {
        return [
            'index' => ListAircraftLinks::route('/'),
        ];
    }
}
