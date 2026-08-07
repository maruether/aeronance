<?php

declare(strict_types=1);

namespace App\Modules\Inspection\Filament\Resources\IncomingInspections\Pages;

use App\Modules\Inspection\Filament\Resources\IncomingInspections\IncomingInspectionResource;
use Filament\Resources\Pages\ListRecords;

/**
 * Kein „Neu"-Knopf, und das ist Absicht: Eingangsprüfungen entstehen beim
 * Wareneingang. Eine von Hand angelegte wäre ein Nachweis über nichts.
 */
final class ListIncomingInspections extends ListRecords
{
    protected static string $resource = IncomingInspectionResource::class;
}
