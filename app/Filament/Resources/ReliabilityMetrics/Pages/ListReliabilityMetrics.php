<?php

namespace App\Filament\Resources\ReliabilityMetrics\Pages;

use App\Filament\Resources\ReliabilityMetrics\ReliabilityMetricResource;
use Filament\Resources\Pages\ListRecords;

class ListReliabilityMetrics extends ListRecords
{
    protected static string $resource = ReliabilityMetricResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ReliabilityMetricResource::calculateTodayAction(),
        ];
    }
}
