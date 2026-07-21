<?php

namespace App\Filament\Resources\ReliabilityMetrics\Pages;

use App\Filament\Resources\ReliabilityMetrics\ReliabilityMetricResource;
use App\Models\ReliabilityMetric;
use Filament\Actions\Action;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;

class ViewReliabilityMetric extends ViewRecord
{
    protected static string $resource = ReliabilityMetricResource::class;

    public function getTitle(): string|Htmlable
    {
        /** @var ReliabilityMetric $record */
        $record = $this->getRecord();
        $serviceName = $record->monitoredService?->name;
        $periodType = ReliabilityMetricResource::periodTypeOptions()[$record->period_type] ?? $record->period_type;

        return collect([$serviceName, $periodType, $record->period_start?->format('Y-m-d')])
            ->filter()
            ->join(' - ');
    }

    protected function getHeaderActions(): array
    {
        return [
            ReliabilityMetricResource::calculateTodayAction(),
            Action::make('edit')
                ->label(__('monitoring.actions.edit'))
                ->icon(Heroicon::OutlinedPencilSquare)
                ->url(fn (): string => static::getResource()::getUrl('edit', ['record' => $this->getRecord()])),
        ];
    }
}
