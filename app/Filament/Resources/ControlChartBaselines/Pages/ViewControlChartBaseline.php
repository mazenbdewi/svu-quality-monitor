<?php

namespace App\Filament\Resources\ControlChartBaselines\Pages;

use App\Filament\Resources\ControlChartBaselines\ControlChartBaselineResource;
use Filament\Resources\Pages\ViewRecord;

class ViewControlChartBaseline extends ViewRecord
{
    protected static string $resource = ControlChartBaselineResource::class;

    protected function getHeaderActions(): array
    {
        return ControlChartBaselineResource::lifecycleActions();
    }
}
