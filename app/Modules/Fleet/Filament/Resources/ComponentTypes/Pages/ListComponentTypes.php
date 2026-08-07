<?php

declare(strict_types=1);

namespace App\Modules\Fleet\Filament\Resources\ComponentTypes\Pages;

use App\Modules\Fleet\Enums\ComponentKind;
use App\Modules\Fleet\Filament\Resources\AircraftTypes\Pages\ListAircraftTypes;
use App\Modules\Fleet\Filament\Resources\ComponentTypes\ComponentTypeResource;
use App\Modules\Fleet\Models\AircraftType;
use App\Modules\Fleet\Models\ComponentType;
use App\Modules\Fleet\Permissions;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Component;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

/**
 * The component catalogue.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * THE KENNBLATT CAN BE LOOKED UP HERE TOO -- which for a long time it could not.
 *
 * The earlier note in this place said the LBA's component volumes were
 * unreadable: the engine and propeller PDFs came out as
 * "Piston Engines4502/ENPorsche 678Dr. Ing. H.c. F. Porsche KG678/1", and three
 * Tost couplings all arrived as "Sicherheitskupplung". That was true of the
 * extractor, not of the documents. Those volumes align their columns by space
 * padding, and a parser that reads text objects in content order has no way to
 * see it.
 *
 * With pdftotext -layout the geometry survives, wrapped cells can be stitched
 * back to their own column, and all three volumes read completely: 157 engines,
 * 130 propellers, 10 couplings -- including "Sicherheitskupplung Europa G 72",
 * "G 73" and "G 88" as three distinguishable entries.
 *
 * Typing one in by hand is still perfectly fine, and is the answer when a
 * component was never certified in Germany. The lookup is an offer, not a
 * gate.
 * ─────────────────────────────────────────────────────────────────────────────
 */
final class ListComponentTypes extends ListRecords
{
    protected static string $resource = ComponentTypeResource::class;

    public function table(Table $table): Table
    {
        return $table
            ->defaultSort('designation')
            ->columns([
                TextColumn::make('designation')
                    ->label(__('fleet.component_type.field.designation'))
                    ->searchable()
                    ->sortable()
                    ->wrap()
                    ->weight('medium'),

                TextColumn::make('kind')
                    ->label(__('fleet.component_type.field.kind'))
                    ->badge()
                    ->formatStateUsing(fn (ComponentKind $state): string => $state->label()),

                TextColumn::make('manufacturer')
                    ->label(__('fleet.component_type.field.manufacturer'))
                    ->searchable()
                    ->placeholder('—'),

                TextColumn::make('part_number')
                    ->label(__('fleet.component_type.field.part_number'))
                    ->searchable()
                    ->placeholder('—')
                    ->toggleable(),

                TextColumn::make('type_certificate')
                    ->label(__('fleet.component_type.field.type_certificate'))
                    ->searchable()
                    ->badge()
                    ->color(fn (?string $state): string => $state === null ? 'warning' : 'success')
                    ->placeholder(__('fleet.component_type.no_certificate')),

                IconColumn::make('has_data_sheet')
                    ->label(__('fleet.component_type.field.data_sheet'))
                    ->boolean()
                    ->state(fn (ComponentType $r): bool => $r->hasDataSheet())
                    ->toggleable(),

                TextColumn::make('fitted')
                    ->label(__('fleet.component_type.field.fitted'))
                    ->state(fn (ComponentType $r): int => $r->fittedCount())
                    ->badge()
                    ->color('gray'),
            ])
            ->filters([
                SelectFilter::make('kind')
                    ->label(__('fleet.component_type.field.kind'))
                    ->options(fn (): array => collect(ComponentKind::cases())
                        ->mapWithKeys(fn (ComponentKind $k): array => [$k->value => $k->label()])
                        ->all()),
            ])
            ->recordActions([
                EditAction::make()->schema(self::formSchema()),
                self::lookupAction(),
            ]);
    }

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->schema(self::formSchema()),
        ];
    }

    /**
     * Ask the LBA which Kennblatt this component has.
     *
     * Only the component volumes are searched -- a club looking up an engine has
     * no use for 157 gliders in the list. EASA is asked too and answers nothing
     * for components on purpose: that adapter reads the aircraft library, and
     * aircraft whose names happen to match would be worse than an honest silence
     * while the Blaues Buch beside it has the answer.
     */
    private static function lookupAction(): Action
    {
        return Action::make('lookup')
            ->label(__('fleet.type.action.lookup'))
            ->icon('heroicon-o-magnifying-glass')
            ->visible(fn (): bool => auth()->user()?->can(Permissions::FLEET_MANAGE) ?? false)
            ->modalDescription(__('fleet.component_type.help.lookup'))
            ->schema(fn (ComponentType $record): array => [
                TextInput::make('term')
                    ->label(__('fleet.type.field.search_term'))
                    ->default($record->designation)
                    ->required()
                    ->live(debounce: 600),

                Select::make('candidate')
                    ->label(__('fleet.type.field.candidate'))
                    ->options(fn (Get $get): array => ListAircraftTypes::componentOptions((string) $get('term')))
                    ->required()
                    ->helperText(__('fleet.component_type.help.candidates'))
                    ->visible(fn (Get $get): bool => filled($get('term'))),
            ])
            ->action(function (array $data, ComponentType $record): void {
                $candidate = ListAircraftTypes::componentFromKey(
                    (string) $data['term'],
                    (string) $data['candidate'],
                );

                if ($candidate === null) {
                    Notification::make()->danger()->title(__('fleet.type.notification.gone'))->send();

                    return;
                }

                /*
                 * Written straight onto the record rather than through
                 * AdoptTypeCertificate: that action stores a data sheet, and the
                 * Blaues Buch has none to store -- it is a list, and for
                 * components it does not even carry an EASA reference to follow.
                 * Number and authority are the whole answer here.
                 */
                $record->forceFill([
                    'type_certificate' => $candidate->certificate,
                    'certificate_authority' => $candidate->authority,
                    'manufacturer' => filled($record->manufacturer)
                        ? $record->manufacturer
                        : $candidate->manufacturer,
                ])->save();

                Notification::make()
                    ->success()
                    ->title(__('fleet.type.notification.adopted', ['certificate' => $candidate->certificate]))
                    ->send();
            });
    }

    /** @return list<Component> */
    public static function formSchema(): array
    {
        return [
            TextInput::make('designation')
                ->label(__('fleet.component_type.field.designation'))
                ->required()
                ->maxLength(160)
                ->helperText(__('fleet.component_type.help.designation')),

            Select::make('kind')
                ->label(__('fleet.component_type.field.kind'))
                ->options(collect(ComponentKind::cases())
                    ->mapWithKeys(fn (ComponentKind $k): array => [$k->value => $k->label()])->all())
                ->default(ComponentKind::Other->value)
                ->required(),

            TextInput::make('manufacturer')
                ->label(__('fleet.component_type.field.manufacturer'))
                ->maxLength(160),

            TextInput::make('part_number')
                ->label(__('fleet.component_type.field.part_number'))
                ->maxLength(96)
                ->helperText(__('fleet.component_type.help.part_number')),

            TextInput::make('type_certificate')
                ->label(__('fleet.component_type.field.type_certificate'))
                ->maxLength(64)
                ->helperText(__('fleet.component_type.help.certificate')),

            Select::make('certificate_authority')
                ->label(__('fleet.type.field.authority'))
                ->options([
                    AircraftType::AUTHORITY_LBA => __('fleet.type.authority.lba'),
                    AircraftType::AUTHORITY_EASA => __('fleet.type.authority.easa'),
                    AircraftType::AUTHORITY_FAA => __('fleet.type.authority.faa'),
                    AircraftType::AUTHORITY_OTHER => __('fleet.type.authority.other'),
                ]),

            TextInput::make('data_sheet_url')
                ->label(__('fleet.type.field.data_sheet_url'))
                ->url()
                ->maxLength(500)
                ->columnSpanFull(),

            TextInput::make('directive_overview_url')
                ->label(__('fleet.component_type.field.overview_url'))
                ->url()
                ->maxLength(500)
                ->helperText(__('fleet.component_type.help.overview'))
                ->columnSpanFull(),

            Textarea::make('note')
                ->label(__('fleet.type.field.note'))
                ->rows(2)
                ->columnSpanFull(),
        ];
    }
}
