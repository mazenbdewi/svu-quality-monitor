<?php

namespace App\Filament\Resources\ControlChartBaselines\Pages;

use App\Filament\Resources\ControlChartBaselines\ControlChartBaselineResource;
use Filament\Resources\Pages\ListRecords;

class ListControlChartBaselines extends ListRecords
{
    protected static string $resource = ControlChartBaselineResource::class;

    protected string $view = 'filament.baselines.list';

    protected function getHeaderActions(): array
    {
        return [];
    }
}
