<?php

namespace App\Filament\Resources\Concerns;

use App\Services\AdministrativeAudit;

trait AuditsAdministrativeChanges
{
    protected array $auditBefore = [];

    public function hasDatabaseTransactions(): bool
    {
        return true;
    }

    protected function beforeSave(): void
    {
        $this->auditBefore = app(AdministrativeAudit::class)->snapshot($this->getRecord()->fresh());
    }

    protected function afterSave(): void
    {
        app(AdministrativeAudit::class)->record($this->getRecord()->fresh(), $this->auditBefore);
    }

    protected function afterCreate(): void
    {
        app(AdministrativeAudit::class)->record($this->getRecord()->fresh(), [], 'created');
    }
}
