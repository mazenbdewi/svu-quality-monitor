<?php

namespace App\Filament\Resources\MonitoredServices\Pages;

use App\Filament\Resources\MonitoredServices\MonitoredServiceResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditMonitoredService extends EditRecord
{
    private const MASKED_SECRET = '••••••••';

    protected static string $resource = MonitoredServiceResource::class;

    protected function getHeaderActions(): array
    {
        return [
            MonitoredServiceResource::checkNowAction(),
            DeleteAction::make()
                ->label(__('monitoring.actions.delete')),
        ];
    }

    protected function mutateFormDataBeforeFill(array $data): array
    {
        foreach (($data['check_config']['headers'] ?? []) as $name => $value) {
            $data['check_config']['headers'][$name] = self::MASKED_SECRET;
        }

        return $data;
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        if (! auth()->user()?->can('sla.manage')) {
            unset($data['sla_enabled'], $data['sla_target_percent']);
        }

        $existingHeaders = $this->record->check_config['headers'] ?? [];

        foreach (($data['check_config']['headers'] ?? []) as $name => $value) {
            if ($value === self::MASKED_SECRET && array_key_exists($name, $existingHeaders)) {
                $data['check_config']['headers'][$name] = $existingHeaders[$name];
            }
        }

        return $data;
    }
}
