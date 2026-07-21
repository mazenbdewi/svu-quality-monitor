<?php

namespace App\Filament\Resources\ServiceIncidents\Pages;

use App\Filament\Resources\ServiceIncidents\ServiceIncidentResource;
use Filament\Resources\Pages\CreateRecord;

class CreateServiceIncident extends CreateRecord
{
    protected static string $resource = ServiceIncidentResource::class;
}
