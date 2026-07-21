<?php

namespace App\Filament\Resources\ServiceIncidents\Pages;

use App\Filament\Resources\ServiceIncidents\ServiceIncidentResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListServiceIncidents extends ListRecords
{
    protected static string $resource = ServiceIncidentResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->label(__('monitoring.actions.create_service_incident')),
        ];
    }
}
