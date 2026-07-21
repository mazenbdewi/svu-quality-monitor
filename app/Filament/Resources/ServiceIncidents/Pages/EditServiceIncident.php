<?php

namespace App\Filament\Resources\ServiceIncidents\Pages;

use App\Filament\Resources\ServiceIncidents\ServiceIncidentResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditServiceIncident extends EditRecord
{
    protected static string $resource = ServiceIncidentResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ServiceIncidentResource::markClosedAction(),
            ServiceIncidentResource::reopenAction(),
            DeleteAction::make()
                ->label(__('monitoring.actions.delete')),
        ];
    }
}
