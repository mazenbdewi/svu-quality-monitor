<?php

namespace App\Services\Baselines;

use App\Models\ControlChartBaseline;
use App\Models\MonitoredService;
use App\Models\User;
use App\Services\SpcResearchData;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/** UI orchestration only; R4A owns population, snapshots, statistics and lifecycle. */
class PhaseOneBaselineSubmission
{
    public function __construct(private PhaseOneBaselineService $engine, private SpcResearchData $research) {}

    public function availability(MonitoredService $service, Carbon $start, Carbon $cutoff, string $timezone): array
    {
        // Preview needs counts, not snapshots or hourly buckets across a partially typed date range.
        return ['latency' => $this->eligibleObservations($service, $start, $cutoff, true)->count(), 'problematic' => $this->eligibleObservations($service, $start, $cutoff)->count()];
    }

    /** Available historical P population as of now, for form guidance only. */
    public function availableRange(MonitoredService $service): ?array
    {
        $cutoff = now()->utc()->startOfSecond();
        $first = $service->serviceChecks()->researchChecks()->where('checked_at', '<', $cutoff)->min('checked_at');
        if ($first === null) {
            return null;
        }
        $checks = $this->eligibleObservations($service, Carbon::parse($first, 'UTC'), $cutoff);
        if ($checks->isEmpty()) {
            return null;
        }

        return ['first' => $checks->first()->checked_at->copy()->utc(), 'last' => $checks->last()->checked_at->copy()->utc(), 'end' => $cutoff];
    }

    private function eligibleObservations(MonitoredService $service, Carbon $start, Carbon $cutoff, bool $latency = false): Collection
    {
        // Same R1/R3 scopes/maintenance exclusions and R4A record-time guard as BaselinePopulation.
        // Creation still delegates to the unmodified R4A capture and calculation.
        return $this->research->checks($service, $start, $cutoff, $latency)
            ->filter(fn ($check) => $check->created_at !== null && $check->created_at->lte($cutoff) && ($check->updated_at === null || $check->updated_at->lte($cutoff)));
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
