<?php

declare(strict_types=1);

namespace App\Core\Filament\Resources\Users\Pages;

use App\Core\Filament\Resources\Users\UserResource;
use Filament\Resources\Pages\EditRecord;

final class EditUser extends EditRecord
{
    protected static string $resource = UserResource::class;
}
