<?php

declare(strict_types=1);

namespace App\Modules\Warehouse\Filament\Resources\Suppliers\Schemas;

use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

final class SupplierForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')
                ->label(__('warehouse.supplier.field.name'))
                ->required()
                ->maxLength(128)
                ->unique(ignoreRecord: true),

            Section::make(__('warehouse.supplier.approval.heading'))
                ->description(__('warehouse.supplier.approval.hint'))
                ->columns(2)
                ->schema([
                    TextInput::make('approval_number')
                        ->label(__('warehouse.supplier.field.approval_number'))
                        ->helperText(__('warehouse.supplier.help.approval_number'))
                        ->maxLength(64)
                        ->live(onBlur: true),

                    TextInput::make('approval_scope')
                        ->label(__('warehouse.supplier.field.approval_scope'))
                        ->helperText(__('warehouse.supplier.help.approval_scope'))
                        ->maxLength(128)
                        ->visible(fn (Get $get): bool => trim((string) $get('approval_number')) !== ''),

                    DatePicker::make('approval_expires_at')
                        ->label(__('warehouse.supplier.field.approval_expires_at'))
                        ->helperText(__('warehouse.supplier.help.approval_expires_at'))
                        ->visible(fn (Get $get): bool => trim((string) $get('approval_number')) !== ''),
                ]),

            Textarea::make('address')
                ->label(__('warehouse.supplier.field.address'))
                ->rows(3),

            Textarea::make('contact')
                ->label(__('warehouse.supplier.field.contact'))
                ->rows(2),

            Textarea::make('description')
                ->label(__('warehouse.supplier.field.description'))
                ->rows(3),
        ]);
    }
}
