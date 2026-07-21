<?php

namespace App\Filament\Resources\ControlCharts\Pages;

use App\Filament\Resources\ControlCharts\ControlChartResource;
use App\Models\ControlChart;
use Filament\Actions\Action;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;

class ViewControlChart extends ViewRecord
{
    protected static string $resource = ControlChartResource::class;

    public function getTitle(): string|Htmlable
    {
        /** @var ControlChart $record */
        $record = $this->getRecord();
        $chartType = ControlChartResource::chartTypeOptions()[$record->chart_type] ?? $record->chart_type;
        $metricName = ControlChartResource::metricNameOptions()[$record->metric_name] ?? $record->metric_name;
        $serviceName = $record->monitoredService?->name;

        return collect([$chartType, $metricName, $serviceName])
            ->filter()
            ->join(' - ');
    }

    protected function getHeaderActions(): array
    {
        return [
            ControlChartResource::calculateControlChartsAction(),
            Action::make('edit_notes')
                ->label(__('monitoring.actions.edit'))
                ->icon(Heroicon::OutlinedPencilSquare)
                ->url(fn (): string => static::getResource()::getUrl('edit', ['record' => $this->getRecord()])),
        ];
    }
}
