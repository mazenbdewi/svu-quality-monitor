<?php

namespace App\Filament\Resources\Users\Pages;

use App\Filament\Resources\Users\UserResource;
use App\Services\UserAdministrationService;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

class EditUser extends EditRecord
{
    protected static string $resource = UserResource::class;

    protected function mutateFormDataBeforeFill(array $data): array
    {
        $data['password'] = '';
        $data['role'] = $this->getRecord()->getRoleNames()->first();

        return $data;
    }

    protected function afterSave(): void
    {
        $this->data['password'] = '';
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        try {
            return app(UserAdministrationService::class)->update(auth()->user(), $record, $data);
        } catch (\DomainException $exception) {
            throw ValidationException::withMessages(['data.role' => $exception->getMessage()]);
        }
    }
}
