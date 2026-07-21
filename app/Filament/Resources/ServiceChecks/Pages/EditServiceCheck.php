<?php

namespace App\Filament\Resources\ServiceChecks\Pages;

use App\Filament\Resources\ServiceChecks\ServiceCheckResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditServiceCheck extends EditRecord
{
    protected static string $resource = ServiceCheckResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make()
                ->label(__('monitoring.actions.delete')),
        ];
    }
}
