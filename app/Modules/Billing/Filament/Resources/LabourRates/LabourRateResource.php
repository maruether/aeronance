<?php

declare(strict_types=1);

namespace App\Modules\Billing\Filament\Resources\LabourRates;

use App\Modules\Billing\Filament\Resources\LabourRates\Pages\CreateLabourRate;
use App\Modules\Billing\Filament\Resources\LabourRates\Pages\EditLabourRate;
use App\Modules\Billing\Filament\Resources\LabourRates\Pages\ListLabourRates;
use App\Modules\Billing\Models\LabourRate;
use App\Modules\Billing\Permissions;
use App\Modules\TaskCards\Enums\ActivityKind;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
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
 * Stundensätze je Tätigkeitsart, gültig ab einem Datum.
 */
final class LabourRateResource extends Resource
{
    protected static ?string $model = LabourRate::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClock;

    protected static ?int $navigationSort = 40;

    protected static ?string $slug = 'stundensaetze';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(__('billing.rate.singular'))
                ->columns(2)
                ->schema([
                    Select::make('activity_kind')
                        ->label(__('billing.rate.field.activity_kind'))
                        ->helperText(__('billing.rate.help.activity_kind'))
                        ->options(collect(ActivityKind::cases())
                            ->mapWithKeys(fn (ActivityKind $k): array => [$k->value => $k->label()])
                            ->all())
                        ->required()
                        ->native(false),
                    TextInput::make('net_rate_per_hour')
                        ->label(__('billing.rate.field.net_rate_per_hour'))
                        ->required()
                        ->numeric()
                        ->minValue(0)
                        ->step(0.01)
                        ->suffix('€/h'),
                    DatePicker::make('valid_from')
                        ->label(__('billing.rate.field.valid_from'))
                        ->default(now()->toDateString())
                        ->required(),
                    Textarea::make('note')
                        ->label(__('billing.rate.field.note'))
                        ->rows(2),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('activity_kind')
                    ->label(__('billing.rate.field.activity_kind'))
                    ->formatStateUsing(fn (ActivityKind $state): string => $state->label())
                    ->sortable(),
                TextColumn::make('net_rate_per_hour')
                    ->label(__('billing.rate.field.net_rate_per_hour'))
                    ->money('EUR', locale: 'de')
                    ->sortable(),
                TextColumn::make('valid_from')
                    ->label(__('billing.rate.field.valid_from'))
                    ->date('d.m.Y')
                    ->sortable(),
                TextColumn::make('note')
                    ->label(__('billing.rate.field.note'))
                    ->limit(50)
                    ->toggleable(),
            ])
            ->defaultSort('valid_from', 'desc')
            ->recordActions([EditAction::make()]);
    }

    public static function getNavigationGroup(): ?string
    {
        return __('nav.group.billing');
    }

    public static function getNavigationLabel(): string
    {
        return __('billing.rate.plural');
    }

    public static function getModelLabel(): string
    {
        return __('billing.rate.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('billing.rate.plural');
    }

    public static function canViewAny(): bool
    {
        return auth()->user()?->can(Permissions::VIEW) ?? false;
    }

    public static function canCreate(): bool
    {
        return auth()->user()?->can(Permissions::MANAGE) ?? false;
    }

    /** @param  LabourRate  $record */
    public static function canEdit($record): bool
    {
        return self::canCreate();
    }

    /** @param  LabourRate  $record */
    public static function canDelete($record): bool
    {
        return self::canCreate();
    }

    public static function getPages(): array
    {
        return [
            'index' => ListLabourRates::route('/'),
            'create' => CreateLabourRate::route('/neu'),
            'edit' => EditLabourRate::route('/{record}/bearbeiten'),
        ];
    }
}
