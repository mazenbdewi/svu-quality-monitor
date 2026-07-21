<?php

namespace App\Filament\Resources\MonitoredServices\Pages;

use App\Filament\Resources\MonitoredServices\MonitoredServiceResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListMonitoredServices extends ListRecords
{
    protected static string $resource = MonitoredServiceResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->label(__('monitoring.actions.create_monitored_service')),
        ];
    }
}
