<?php

declare(strict_types=1);

namespace App\Modules\Fleet\Filament\Resources\Holders;

use App\Models\User;
use App\Modules\Fleet\Filament\Resources\Holders\Pages\ListHolders;
use App\Modules\Fleet\Models\Holder;
use App\Modules\Fleet\Permissions;
use BackedEnum;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * Who holds the aircraft.
 *
 * An entity rather than a name field because Part-ML pins the continuing
 * airworthiness duty on the holder: a privately held aircraft in the club's care
 * answers to its owner and not to the committee.
 *
 * Small enough to keep in one file -- a name, a kind and an optional link to a
 * member. Splitting it across four would be ceremony.
 */
final class HolderResource extends Resource
{
    protected static ?string $model = Holder::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUserCircle;

    protected static ?int $navigationSort = 20;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(__('fleet.holder.singular'))
                ->description(__('fleet.holder.help.why'))
                ->schema([
                    TextInput::make('name')
                        ->label(__('fleet.aircraft.field.holder'))
                        ->required()
                        ->maxLength(160),

                    Select::make('type')
                        ->label(__('fleet.holder.field.type'))
                        ->options([
                            Holder::TYPE_CLUB => __('fleet.holder.type.club'),
                            Holder::TYPE_PRIVATE => __('fleet.holder.type.private'),
                        ])
                        ->default(Holder::TYPE_PRIVATE)
                        ->selectablePlaceholder(false)
                        ->required(),

                    Select::make('user_id')
                        ->label(__('fleet.holder.field.user'))
                        ->options(fn (): array => User::orderBy('name')->pluck('name', 'id')->all())
                        ->searchable()
                        ->helperText(__('fleet.holder.help.user')),

                    TextInput::make('contact')
                        ->label(__('fleet.holder.field.contact'))
                        ->maxLength(255),

                    Textarea::make('note')
                        ->label(__('fleet.aircraft.field.note'))
                        ->rows(2)
                        ->columnSpanFull(),
                ])
                ->columns(2),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('name')
            ->columns([
                TextColumn::make('name')
                    ->label(__('fleet.aircraft.field.holder'))
                    ->searchable()
                    ->sortable(),

                TextColumn::make('type')
                    ->label(__('fleet.holder.field.type'))
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => __('fleet.holder.type.'.$state))
                    ->color(fn (string $state): string => $state === Holder::TYPE_CLUB ? 'success' : 'gray'),

                TextColumn::make('user.name')
                    ->label(__('fleet.holder.field.user'))
                    ->placeholder('—')
                    ->toggleable(),

                TextColumn::make('aircraft_count')
                    ->label(__('fleet.aircraft.plural'))
                    ->counts('aircraft')
                    ->alignEnd(),

                TextColumn::make('contact')
                    ->label(__('fleet.holder.field.contact'))
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->recordActions([EditAction::make()])
            ->toolbarActions([BulkActionGroup::make([])]);
    }

    public static function getNavigationGroup(): ?string
    {
        return __('nav.group.fleet');
    }

    public static function getNavigationLabel(): string
    {
        return __('fleet.holder.plural');
    }

    public static function getModelLabel(): string
    {
        return __('fleet.holder.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('fleet.holder.plural');
    }

    public static function canViewAny(): bool
    {
        return auth()->user()?->can(Permissions::FLEET_VIEW) ?? false;
    }

    public static function canCreate(): bool
    {
        return auth()->user()?->can(Permissions::FLEET_MANAGE) ?? false;
    }

    /** @param  Holder  $record */
    public static function canEdit($record): bool
    {
        return auth()->user()?->can(Permissions::FLEET_MANAGE) ?? false;
    }

    /** @param  Holder  $record */
    public static function canDelete($record): bool
    {
        return false;
    }

    public static function getPages(): array
    {
        return ['index' => ListHolders::route('/')];
    }
}
