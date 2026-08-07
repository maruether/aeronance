<?php

declare(strict_types=1);

namespace App\Core\Filament\Resources\Roles\Tables;

use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Spatie\Permission\Models\Role;

final class RolesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label(__('roles.field.name'))
                    ->formatStateUsing(fn (string $state): string => __('roles.'.$state))
                    ->description(fn (Role $record): string => $record->name)
                    ->searchable()
                    ->sortable(),

                TextColumn::make('permissions_count')
                    ->label(__('roles.field.permissions'))
                    ->counts('permissions')
                    ->badge(),

                TextColumn::make('users_count')
                    ->label(__('roles.field.users'))
                    ->counts('users')
                    ->badge()
                    ->color('gray'),
            ])
            ->defaultSort('name')
            ->recordActions([EditAction::make()]);
    }
}
