<?php

namespace App\Filament\Resources\ServiceChecks\Pages;

use App\Filament\Resources\ServiceChecks\ServiceCheckResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListServiceChecks extends ListRecords
{
    protected static string $resource = ServiceCheckResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->label(__('monitoring.actions.create_service_check')),
        ];
    }
}
