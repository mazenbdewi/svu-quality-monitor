<?php

namespace App\Filament\Resources\MonitoredServices\Pages;

use App\Filament\Resources\MonitoredServices\MonitoredServiceResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditMonitoredService extends EditRecord
{
    protected static string $resource = MonitoredServiceResource::class;

    protected function getHeaderActions(): array
    {
        return [
            MonitoredServiceResource::checkNowAction(),
            DeleteAction::make()
                ->label(__('monitoring.actions.delete')),
        ];
    }
}
