<?php

namespace App\Filament\Resources\ServiceChecks\Pages;

use App\Filament\Resources\ServiceChecks\ServiceCheckResource;
use Filament\Resources\Pages\CreateRecord;

class CreateServiceCheck extends CreateRecord
{
    protected static string $resource = ServiceCheckResource::class;
}
