<?php

namespace App\Filament\Resources\ControlCharts\Pages;

use App\Filament\Resources\ControlCharts\ControlChartResource;
use Filament\Resources\Pages\ListRecords;

class ListControlCharts extends ListRecords
{
    protected static string $resource = ControlChartResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ControlChartResource::calculateControlChartsAction(),
        ];
    }
}
