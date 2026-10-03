<?php

namespace App\Services\Baselines;

use App\Models\ControlChartBaseline;
use App\Models\MonitoredService;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/** UI orchestration only; R4A owns population, snapshots, statistics and lifecycle. */
class PhaseOneBaselineSubmission
{
    public function __construct(private PhaseOneBaselineService $engine, private BaselinePopulation $population) {}

    public function availability(MonitoredService $service, Carbon $start, Carbon $cutoff, string $timezone): array
    {
        // P capture also records latency exclusion evidence; use the same R4A populations.
        $latency = $this->population->capture($service, 'i_chart', $start, $cutoff, 'hourly', $timezone);
        $binary = $this->population->capture($service, 'p_chart', $start, $cutoff, 'hourly', $timezone);

        return ['latency' => $latency['counts']['included'], 'problematic' => $binary['counts']['included']];
    }

    public function create(array $data, string $timezone, User $actor): array
    {
        abort_unless($actor->fresh()?->is_active, 403);
        Gate::forUser($actor->fresh())->authorize(PhaseOneBaselineService::PERMISSION);
        $metrics = $data['metrics'] ?? [];
        if (! is_array($metrics) || $metrics === [] || array_diff($metrics, ['latency', 'problematic'])) {
            $this->invalid('metrics', 'choose_metric');
        }
        try {
            // Filament DateTimePicker dehydrates institution-local input to UTC.
            $start = Carbon::parse($data['baseline_start'], 'UTC')->utc()->startOfSecond();
            $end = Carbon::parse($data['baseline_end'], 'UTC')->utc()->startOfSecond();
            $cutoff = empty($data['data_cutoff']) ? $end->copy() : Carbon::parse($data['data_cutoff'], 'UTC')->utc()->startOfSecond();
        } catch (\Exception) {
            $this->invalid('baseline_start', 'invalid_period');
        }
        if ($start->gte($cutoff) || $cutoff->gt($end) || $end->gt(now()->utc())) {
            $this->invalid('baseline_end', 'invalid_period');
        }
        $aggregation = $data['aggregation_interval'] ?? 'hourly';
        if (! in_array($aggregation, ['hourly', 'daily'], true)) {
            $this->invalid('aggregation_interval', 'invalid_aggregation');
        }

        return DB::transaction(function () use ($data, $metrics, $timezone, $actor, $start, $end, $cutoff, $aggregation) {
            $service = MonitoredService::query()->lockForUpdate()->find($data['service_id'] ?? null);
            if (! $service) {
                $this->invalid('service_id', 'missing_service');
            }
            $types = array_merge(in_array('latency', $metrics, true) ? ['i_chart', 'mr_chart'] : [], in_array('problematic', $metrics, true) ? ['p_chart'] : []);
            foreach ($types as $type) {
                if (ControlChartBaseline::where('monitored_service_id', $service->id)->where('chart_type', $type)
                    ->where('baseline_start', $start)->where('baseline_end', $end)->where('data_cutoff', $cutoff)
                    ->where('analysis_timezone', $timezone)->where('aggregation_interval', $type === 'p_chart' ? $aggregation : 'raw')
                    ->whereIn('status', ['draft', 'reviewed', 'approved'])->exists()) {
                    $this->invalid('metrics', 'duplicate');
                }
            }
            $created = [];
            foreach ($types as $type) {
                $baseline = $this->engine->create($service, $type, $start->toIso8601String(), $end->toIso8601String(), $timezone, $actor, $aggregation, $cutoff->toIso8601String());
                // Empty submissions get a visible explanation; the underlying R4A method is unchanged.
                if ($baseline->sample_counts['included'] === 0) {
                    $this->invalid('metrics', $type === 'p_chart' ? 'no_binary' : 'no_latency');
                }
                $created[] = $baseline;
            }

            return $created;
        }, 3);
    }

    private function invalid(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => __('baseline_creation.'.$message)]);
    }
}
