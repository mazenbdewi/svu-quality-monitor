<?php

namespace App\Filament\Resources\MonitoredServices\Pages;

use App\Filament\Resources\MaintenanceWindows\MaintenanceWindowResource;
use App\Filament\Resources\MonitoredServices\MonitoredServiceResource;
use App\Filament\Resources\NotificationDeliveries\NotificationDeliveryResource;
use App\Filament\Resources\ServiceChecks\ServiceCheckResource;
use App\Filament\Resources\ServiceIncidents\ServiceIncidentResource;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
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
            ActionGroup::make([
                Action::make('checks')->label(ServiceCheckResource::getNavigationLabel())->url(ServiceCheckResource::getUrl())->visible(fn (): bool => ServiceCheckResource::canViewAny()),
                Action::make('incidents')->label(ServiceIncidentResource::getNavigationLabel())->url(ServiceIncidentResource::getUrl())->visible(fn (): bool => ServiceIncidentResource::canViewAny()),
                Action::make('maintenance')->label(MaintenanceWindowResource::getNavigationLabel())->url(MaintenanceWindowResource::getUrl())->visible(fn (): bool => MaintenanceWindowResource::canViewAny()),
                Action::make('deliveries')->label(NotificationDeliveryResource::getNavigationLabel())->url(NotificationDeliveryResource::getUrl())->visible(fn (): bool => NotificationDeliveryResource::canViewAny()),
            ])->label(__('monitoring.related_pages')),
        ];
    }
}
