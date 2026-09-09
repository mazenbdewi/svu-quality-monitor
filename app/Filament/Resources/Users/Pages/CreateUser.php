<?php

namespace App\Filament\Resources\Users\Pages;

use App\Filament\Resources\Users\UserResource;
use App\Services\UserAdministrationService;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateUser extends CreateRecord
{
    protected static string $resource = UserResource::class;

    protected function afterCreate(): void
    {
        $this->data['password'] = '';
    }

    protected function handleRecordCreation(array $data): Model
    {
        return app(UserAdministrationService::class)->create(auth()->user(), $data);
    }
}
