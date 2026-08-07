<?php

declare(strict_types=1);

namespace App\Core\Filament\Resources\Users\Pages;

use App\Core\Filament\Resources\Users\UserResource;
use Filament\Resources\Pages\CreateRecord;

final class CreateUser extends CreateRecord
{
    protected static string $resource = UserResource::class;
}
