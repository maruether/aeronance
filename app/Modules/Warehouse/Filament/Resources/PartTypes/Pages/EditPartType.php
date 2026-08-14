<?php

declare(strict_types=1);

namespace App\Modules\Warehouse\Filament\Resources\PartTypes\Pages;

use App\Modules\Warehouse\Actions\ApplyFormOneDutyToStock;
use App\Modules\Warehouse\Filament\Resources\PartTypes\PartTypeResource;
use Filament\Actions\DeleteAction;
use Filament\Actions\ForceDeleteAction;
use Filament\Actions\RestoreAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;

class EditPartType extends EditRecord
{
    protected static string $resource = PartTypeResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
            ForceDeleteAction::make(),
            RestoreAction::make(),
        ];
    }

    /**
     * Die Form-1-Pflicht einschalten meint auch den Bestand, der schon da ist.
     *
     * Sonst stünde nach dem Haken weiter „verwendbar" an Losen, die sich nicht
     * mehr ausgeben lassen -- ein Widerspruch, den man erst im Audit bemerkt.
     * Siehe ApplyFormOneDutyToStock; gesperrt, nicht gelöscht, und mit einem
     * Wort dazu, wie viele es traf.
     */
    protected function afterSave(): void
    {
        $gesperrt = app(ApplyFormOneDutyToStock::class)->handle($this->record, auth()->user());

        if ($gesperrt === []) {
            return;
        }

        Notification::make()
            ->warning()
            ->title(__('warehouse.part_type.form_one_duty_title', ['count' => count($gesperrt)]))
            ->body(__('warehouse.part_type.form_one_duty_body', ['lots' => implode(', ', $gesperrt)]))
            ->persistent()
            ->send();
    }
}
