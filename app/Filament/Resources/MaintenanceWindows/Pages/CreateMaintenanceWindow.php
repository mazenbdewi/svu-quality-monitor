<?php

namespace App\Filament\Resources\MaintenanceWindows\Pages;

use App\Filament\Resources\Concerns\AuditsAdministrativeChanges;
use App\Filament\Resources\MaintenanceWindows\MaintenanceWindowResource;
use App\Services\MaintenanceWindowService;
use Carbon\Carbon;
use Filament\Resources\Pages\CreateRecord;

class CreateMaintenanceWindow extends CreateRecord
{
    use AuditsAdministrativeChanges;

    protected static string $resource = MaintenanceWindowResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        app(MaintenanceWindowService::class)->assertValidWindow(
            Carbon::parse($data['starts_at']), Carbon::parse($data['ends_at']), (bool) ($data['applies_to_all_services'] ?? false), $this->data['monitoredServices'] ?? [],
        );
        $data['created_by'] = auth()->id();

        return $data;
    }
}
