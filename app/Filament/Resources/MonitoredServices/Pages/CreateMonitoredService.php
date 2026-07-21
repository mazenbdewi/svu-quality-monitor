<?php

namespace App\Filament\Resources\MonitoredServices\Pages;

use App\Filament\Resources\MonitoredServices\MonitoredServiceResource;
use Filament\Resources\Pages\CreateRecord;

class CreateMonitoredService extends CreateRecord
{
    protected static string $resource = MonitoredServiceResource::class;
}
