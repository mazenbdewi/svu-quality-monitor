<?php

namespace App\Filament\Resources\ControlChartBaselines\Pages;

use App\Filament\Resources\ControlChartBaselines\ControlChartBaselineResource;
use App\Models\MonitoredService;
use App\Services\Baselines\PhaseOneBaselineSubmission;
use App\Services\InstitutionTimezone;
use Carbon\Carbon;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Utilities\Get;

class ListControlChartBaselines extends ListRecords
{
    protected static string $resource = ControlChartBaselineResource::class;

    protected string $view = 'filament.baselines.list';

    private array $availabilityCache = [];

    public function baselineErrorPath(): string
    {
        return $this->getMountedActionSchema()->getStatePath();
    }

    public function baselineAvailability(Get $get): array
    {
        $values = [$get('service_id'), $get('baseline_start'), $get('baseline_end'), $get('data_cutoff')];
        $key = json_encode($values);
        if (isset($this->availabilityCache[$key])) {
            return $this->availabilityCache[$key];
        }
        $result = ['latency' => 0, 'problematic' => 0, 'message' => __('baseline_creation.select_period')];
        if (! $values[0] || ! $values[1] || ! $values[2]) {
            return $result;
        }
        try {
            $zone = app(InstitutionTimezone::class)->name();
            // Get invokes DateTimePicker's state cast: values are already converted to UTC.
            $start = Carbon::parse($values[1], 'UTC')->utc();
            $end = Carbon::parse($values[2], 'UTC')->utc();
            $cutoff = empty($values[3]) ? $end : Carbon::parse($values[3], 'UTC')->utc();
            if ($start->gte($cutoff) || $cutoff->gt($end) || $end->gt(now()->utc())) {
                return array_replace($result, ['message' => __('baseline_creation.invalid_period')]);
            }
            $service = MonitoredService::find($values[0]);
            if (! $service) {
                return array_replace($result, ['message' => __('baseline_creation.missing_service')]);
            }
            $result = app(PhaseOneBaselineSubmission::class)->availability($service, $start, $cutoff, $zone);
            $result['message'] = __('baseline_creation.availability', $result);
            foreach (['latency' => 'no_latency', 'problematic' => 'no_binary'] as $metric => $reason) {
                if ($result[$metric] === 0) {
                    $result['message'] .= ' '.__('baseline_creation.'.$reason);
                }
            }
        } catch (\Throwable $e) {
            report($e);
            $result = ['latency' => 0, 'problematic' => 0, 'message' => __('baseline_creation.unavailable')];
        }

        return $this->availabilityCache[$key] = $result;
    }

    protected function getHeaderActions(): array
    {
        return [ControlChartBaselineResource::generateAction()];
    }
}
