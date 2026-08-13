<?php

declare(strict_types=1);

namespace App\Modules\Fleet\Filament\Resources\MaintenanceManuals\Schemas;

use App\Modules\Fleet\Enums\ManualKind;
use App\Modules\Fleet\Models\Aircraft;
use App\Modules\Fleet\Models\AircraftType;
use App\Modules\Fleet\Models\MaintenanceManual;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\SpatieMediaLibraryFileUpload;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Illuminate\Support\Str;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;

/**
 * Eine Unterlage aufnehmen.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * DER REVISIONSSTAND IST HIER NUR BEIM ANLEGEN ZU SEHEN. Eine spätere Revision
 * entsteht über die Aktion „Neue Revision" und nicht durch Ändern dieses Feldes
 * — sonst wäre die Frage, nach welchem Stand im Mai gearbeitet wurde, mit einem
 * Tastendruck vernichtet.
 * ─────────────────────────────────────────────────────────────────────────────
 */
final class MaintenanceManualForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(__('fleet.manual.field.scope'))
                ->description(__('fleet.manual.help.scope'))
                ->columns(2)
                ->schema([
                    Select::make('aircraft_type_id')
                        ->label(__('fleet.type.singular'))
                        ->options(fn (): array => AircraftType::query()
                            ->orderBy('designation')
                            ->pluck('designation', 'id')
                            ->all())
                        ->searchable()
                        ->live()
                        ->requiredWithout('aircraft_id')
                        ->disabled(fn (Get $get): bool => filled($get('aircraft_id'))),

                    Select::make('aircraft_id')
                        ->label(__('fleet.aircraft.singular'))
                        ->options(fn (): array => Aircraft::query()
                            ->orderBy('registration')
                            ->pluck('registration', 'id')
                            ->all())
                        ->searchable()
                        ->live()
                        ->requiredWithout('aircraft_type_id')
                        ->disabled(fn (Get $get): bool => filled($get('aircraft_type_id'))),
                ]),

            Section::make(__('fleet.manual.singular'))
                ->columns(2)
                ->schema([
                    Select::make('kind')
                        ->label(__('fleet.manual.field.kind'))
                        ->options(collect(ManualKind::cases())
                            ->mapWithKeys(fn (ManualKind $k): array => [$k->value => $k->label()])
                            ->all())
                        ->default(ManualKind::Maintenance->value)
                        ->selectablePlaceholder(false)
                        ->required(),

                    TextInput::make('title')
                        ->label(__('fleet.manual.field.title'))
                        ->required()
                        ->maxLength(200),

                    TextInput::make('reference')
                        ->label(__('fleet.manual.field.reference'))
                        ->maxLength(128),

                    TextInput::make('revision')
                        ->label(__('fleet.manual.field.revision'))
                        ->helperText(__('fleet.manual.help.revision'))
                        ->required()
                        ->maxLength(64)
                        // Nur beim Anlegen: spaeter geht es ueber "Neue Revision".
                        ->disabled(fn (?object $record): bool => $record !== null)
                        ->dehydrated(fn (?object $record): bool => $record === null),

                    DatePicker::make('revision_date')
                        ->label(__('fleet.manual.field.revision_date')),

                    DatePicker::make('effective_from')
                        ->label(__('fleet.manual.field.effective_from')),

                    Textarea::make('note')
                        ->label(__('fleet.manual.field.note'))
                        ->rows(3)
                        ->columnSpanFull(),

                    /*
                     * Die Datei selbst. Feldtest: "wie bekomm ich die da von
                     * hand rein?" -- die Ablage existierte am Modell, aber kein
                     * Formular bot sie an; eine Geisterfunktion. Erzeugter
                     * Dateiname (Fremdeingabe-Härtung), die Endung kommt aus
                     * dem geprüften MIME-Typ, nicht vom Client.
                     */
                    SpatieMediaLibraryFileUpload::make('document')
                        ->label(__('fleet.manual.field.file'))
                        ->collection(MaintenanceManual::DOCUMENTS)
                        ->acceptedFileTypes(['application/pdf', 'image/jpeg', 'image/png'])
                        ->maxSize((int) config('aeronance.documents.max_size_mb', 20) * 1024)
                        ->getUploadedFileNameForStorageUsing(
                            fn (TemporaryUploadedFile $file): string => Str::uuid().'.'.($file->guessExtension() ?: 'pdf'),
                        )
                        ->helperText(__('fleet.manual.help.file'))
                        ->columnSpanFull(),
                ]),
        ]);
    }
}
