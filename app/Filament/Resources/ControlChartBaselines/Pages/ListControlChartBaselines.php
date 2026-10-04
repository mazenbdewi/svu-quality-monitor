<?php

namespace App\Filament\Resources\ControlChartBaselines\Pages;

use App\Filament\Resources\ControlChartBaselines\ControlChartBaselineResource;
use App\Models\MonitoredService;
use App\Services\Baselines\PhaseOneBaselineSubmission;
use App\Services\InstitutionTimezone;
use Carbon\Carbon;
use Carbon\Exceptions\InvalidFormatException;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Support\Exceptions\Halt;

class ListControlChartBaselines extends ListRecords
{
    protected static string $resource = ControlChartBaselineResource::class;

    protected string $view = 'filament.baselines.list';

    private array $availabilityCache = [];

    private array $rangeCache = [];

    public function baselineServiceChanged(Get $get, Set $set): void
    {
        $set('metrics', []);
        $set('data_cutoff', null);
        $this->resetErrorBag();
        try {
            $range = $this->availableBaselineRange($get('service_id'));
        } catch (\Throwable $e) {
            report($e);
            $range = null;
            Notification::make()->danger()->title(__('baseline_creation.unavailable'))->send();
        }
        // Set takes UTC state and DateTimePicker converts it to institution-local display.
        $set('baseline_start', $range ? $range['last']->copy()->subDays(7)->max($range['first'])->copy()->startOfMinute()->toDateTimeString() : null);
        $set('baseline_end', $range ? $range['end']->toDateTimeString() : null);
    }

    public function baselinePeriodChanged(Set $set): void
    {
        $set('metrics', []);
        $this->availabilityCache = [];
        $this->resetErrorBag();
    }

    private function availableBaselineRange($serviceId): ?array
    {
        if (! $serviceId) {
            return null;
        }
        if (! array_key_exists($serviceId, $this->rangeCache)) {
            $service = MonitoredService::find($serviceId);
            $this->rangeCache[$serviceId] = $service ? app(PhaseOneBaselineSubmission::class)->availableRange($service) : null;
        }

        return $this->rangeCache[$serviceId];
    }

    public function baselineRangeMessage(Get $get): string
    {
        if (! $get('service_id')) {
            return __('baseline_creation.select_period');
        }
        try {
            $range = $this->availableBaselineRange($get('service_id'));
            if (! $range) {
                return __('baseline_creation.no_available_range');
            }
            $clock = app(InstitutionTimezone::class);

            return __('baseline_creation.available_range', ['first' => $clock->local($range['first'])->format('Y-m-d H:i:s'), 'last' => $clock->local($range['last'])->format('Y-m-d H:i:s'), 'timezone' => $clock->name()]);
        } catch (\Throwable $e) {
            report($e);

            return __('baseline_creation.unavailable');
        }
    }

    public function currentBaselineAvailability(): array
    {
        $field = $this->getMountedActionSchema()?->getComponentByStatePath('service_id');

        return $field ? $this->baselineAvailability(new Get($field)) : ['latency' => 0, 'problematic' => 0, 'message' => __('baseline_creation.select_period')];
    }

    public function canSubmitBaseline(): bool
    {
        $availability = $this->currentBaselineAvailability();

        return $availability['latency'] > 0 || $availability['problematic'] > 0;
    }

    public function guardBaselineAvailability(): void
    {
        // Also guard Enter/forged submit, before the required checkbox validation runs.
        abort_unless(ControlChartBaselineResource::canManage(), 403);
        if (! $this->canSubmitBaseline()) {
            Notification::make()->warning()->title(__('baseline_creation.not_ready'))->body($this->currentBaselineAvailability()['message'])->send();
            throw new Halt;
        }
    }

    public function baselineErrorPath(): string
    {
        return $this->getMountedActionSchema()->getStatePath();
    }

    public function baselineAvailability(Get $get): array
    {
        try {
            $values = [$get('service_id'), $get('baseline_start'), $get('baseline_end'), $get('data_cutoff')];
        } catch (InvalidFormatException) {
            return ['latency' => 0, 'problematic' => 0, 'message' => __('baseline_creation.invalid_period')];
        }
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
            $result['message'] = ($result['latency'] === 0 && $result['problematic'] === 0 ? __('baseline_creation.no_eligible').' ' : '').__('baseline_creation.availability', $result);
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
