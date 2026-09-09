<?php

namespace App\Filament\Resources\MonitoredServices\Pages;

use App\Filament\Resources\Concerns\AuditsAdministrativeChanges;
use App\Filament\Resources\MonitoredServices\MonitoredServiceResource;
use Filament\Resources\Pages\CreateRecord;

class CreateMonitoredService extends CreateRecord
{
    use AuditsAdministrativeChanges;

    protected static string $resource = MonitoredServiceResource::class;
}
