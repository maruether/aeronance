<?php

declare(strict_types=1);

namespace App\Modules\Warehouse\Filament\Resources\StorageLocations\Schemas;

use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

final class StorageLocationForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')
                ->label(__('warehouse.location.field.name'))
                ->required()
                ->maxLength(128)
                ->unique(ignoreRecord: true),

            Textarea::make('description')
                ->label(__('warehouse.location.field.description'))
                ->rows(2),

            Checkbox::make('is_quarantine')
                ->label(__('warehouse.location.field.is_quarantine'))
                ->helperText(__('warehouse.location.help.is_quarantine')),

            // Compartments are edited inline, which is how the legacy interface
            // did it and the one bit of it worth keeping: a cupboard and its
            // shelves are one thought, not two screens.
            Repeater::make('compartments')
                ->label(__('warehouse.compartment.plural'))
                ->relationship()
                ->schema([
                    TextInput::make('name')
                        ->label(__('warehouse.compartment.field.name'))
                        ->required()
                        ->maxLength(128),

                    TextInput::make('description')
                        ->label(__('warehouse.compartment.field.description'))
                        ->maxLength(255),
                ])
                ->columns(2)
                ->itemLabel(fn (array $state): ?string => $state['name'] ?? null)
                ->addActionLabel(__('warehouse.compartment.add'))
                ->collapsible()
                ->defaultItems(0),
        ]);
    }
}
