<?php

namespace App\Filament\Resources\ControlCharts\Pages;

use App\Filament\Resources\ControlCharts\ControlChartResource;
use Filament\Actions\Action;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Icons\Heroicon;

class EditControlChart extends EditRecord
{
    protected static string $resource = ControlChartResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('view_control_chart')
                ->label(__('monitoring.actions.view_control_chart'))
                ->icon(Heroicon::OutlinedEye)
                ->url(fn (): string => static::getResource()::getUrl('view', ['record' => $this->getRecord()])),
            ControlChartResource::calculateControlChartsAction(),
        ];
    }
}
