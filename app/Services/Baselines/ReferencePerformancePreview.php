<?php

namespace App\Services\Baselines;

use App\Models\ControlChartBaseline;
use App\Models\MonitoredService;
use Carbon\Carbon;

/** Read-only guidance. PhaseOneBaselineSubmission remains the authority at creation. */
class ReferencePerformancePreview
{
    public function __construct(private BaselinePopulation $population, private BaselineStatistics $statistics, private BaselineConfiguration $configuration) {}

    public function analyze(MonitoredService $service, Carbon $start, Carbon $end, Carbon $cutoff, string $timezone, array $metrics, string $aggregation): array
    {
        if (! in_array($service->check_type->value, ['http', 'api'], true) || $start->gte($cutoff) || $cutoff->gt($end) || $end->gt(now()->utc()) ||
            $metrics === [] || array_diff($metrics, ['latency', 'problematic']) || ! in_array($aggregation, ['hourly', 'daily'], true)) {
            return ['status' => 'ineligible', 'reason' => 'invalid_selection', 'charts' => []];
        }

        $types = array_merge(in_array('latency', $metrics, true) ? ['i_chart', 'mr_chart'] : [], in_array('problematic', $metrics, true) ? ['p_chart'] : []);
        $charts = [];
        $status = 'eligible';
        $changes = $this->configuration->changes($service, $start, $end);
        $latencyCapture = null;

        foreach ($types as $type) {
            // I and MR have the same frozen response-time population, with different point counts.
            $capture = $type === 'p_chart'
                ? $this->population->capture($service, $type, $start, $cutoff, $aggregation, $timezone)
                : ($latencyCapture ??= $this->population->capture($service, 'i_chart', $start, $cutoff, $aggregation, $timezone));
            $statistics = $this->statistics->calculate($type, $capture['samples'], $capture['coverage']['buckets']);
            $points = count($statistics['points']);
            $minimum = max(2, (int) config('monitoring.spc.exploratory_min_points', 20));
            $sufficiency = $capture['counts']['included'] === 0 ? 'no_data' : ($points < 2 ? 'insufficient' : ($points < $minimum || $capture['coverage']['missing_count'] > 0 ? 'preliminary' : 'analyzable'));
            $outOfControl = count(array_filter($statistics['points'], fn (array $point): bool => $point['signal']));
            $active = ControlChartBaseline::where('monitored_service_id', $service->id)->where('chart_type', $type)->where('status', 'approved')->exists();
            $duplicate = ControlChartBaseline::where('monitored_service_id', $service->id)->where('chart_type', $type)
                ->where('baseline_start', $start)->where('baseline_end', $end)->where('data_cutoff', $cutoff)
                ->where('analysis_timezone', $timezone)->where('aggregation_interval', $type === 'p_chart' ? $aggregation : 'raw')
                ->whereIn('status', ['draft', 'reviewed', 'approved'])->exists();

            $charts[$type] = [
                'included' => $capture['counts']['included'], 'point_count' => $points,
                'exclusions' => $capture['counts']['exclusion_counts'], 'coverage' => $capture['coverage']['coverage_percent'],
                'expected' => $capture['coverage']['expected_count'], 'missing' => $capture['coverage']['missing_count'],
                'nonempty_subgroups' => $type === 'p_chart' ? $points : null,
                'out_of_control' => $outOfControl, 'sufficiency' => $sufficiency,
                'active_reference' => $active, 'duplicate' => $duplicate,
            ];

            if ($duplicate || $sufficiency === 'no_data' || $capture['coverage']['coverage_percent'] === null) {
                $status = 'ineligible';
            } elseif ($status !== 'ineligible' && ($sufficiency !== 'analyzable' || $outOfControl > 0 || $active || $changes !== [] || $cutoff->lt($end))) {
                $status = 'needs_review';
            }
        }

        return ['status' => $status, 'reason' => null, 'charts' => $charts, 'configuration_changes' => count($changes),
            'start' => $start->toIso8601String(), 'end' => $end->toIso8601String(), 'cutoff' => $cutoff->toIso8601String(),
            'timezone' => $timezone, 'service_name' => $service->name, 'aggregation' => $aggregation];
    }
}
