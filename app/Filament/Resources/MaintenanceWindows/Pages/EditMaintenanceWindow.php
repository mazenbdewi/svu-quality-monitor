<?php

namespace App\Filament\Resources\MaintenanceWindows\Pages;

use App\Filament\Resources\Concerns\AuditsAdministrativeChanges;
use App\Filament\Resources\MaintenanceWindows\MaintenanceWindowResource;
use App\Models\MaintenanceWindow;
use App\Services\AdministrativeAudit;
use App\Services\MaintenanceWindowService;
use Carbon\Carbon;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

class EditMaintenanceWindow extends EditRecord
{
    use AuditsAdministrativeChanges;

    protected static string $resource = MaintenanceWindowResource::class;

    protected function mutateFormDataBeforeSave(array $data): array
    {
        /** @var MaintenanceWindow $record */
        $record = $this->record;
        if ($record->statusAt() === 'completed') {
            throw ValidationException::withMessages(['starts_at' => __('monitoring.maintenance.validation.completed_immutable')]);
        }
        if ($record->statusAt() === 'active' && Carbon::parse($data['starts_at'])->ne($record->starts_at)) {
            throw ValidationException::withMessages(['starts_at' => __('monitoring.maintenance.validation.active_start_immutable')]);
        }
        if ($record->statusAt() === 'active' && Carbon::parse($data['ends_at'])->lte(now())) {
            throw ValidationException::withMessages(['ends_at' => __('monitoring.maintenance.validation.active_end_must_be_future')]);
        }
        app(MaintenanceWindowService::class)->assertValidWindow(
            Carbon::parse($data['starts_at']), Carbon::parse($data['ends_at']), (bool) ($data['applies_to_all_services'] ?? false), $this->data['monitoredServices'] ?? [], $record->id,
        );

        return $data;
    }

    protected function getHeaderActions(): array
    {
        return [DeleteAction::make()->using(fn (Model $record): bool => app(AdministrativeAudit::class)->delete($record))->label(__('monitoring.actions.delete'))->visible(fn (): bool => $this->record->statusAt() === 'scheduled')];
    }
}
