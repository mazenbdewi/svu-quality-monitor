<?php

namespace App\Exports\Baselines;

use App\Models\ControlChartBaseline;
use App\Services\Baselines\PhaseOneBaselineService;
use Illuminate\Support\Arr;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;

class PhaseOneBaselineExport implements WithMultipleSheets
{
    public function __construct(private ControlChartBaseline $baseline) {}

    public function sheets(): array
    {
        $service = app(PhaseOneBaselineService::class);
        $baseline = $this->baseline->fresh();
        $result = $service->reproduce($baseline);
        $samples = $service->samples($baseline);
        $summary = $baseline->only(['id', 'monitored_service_id', 'chart_type', 'metric_name', 'version', 'status', 'baseline_start', 'baseline_end', 'analysis_timezone', 'aggregation_interval', 'data_cutoff', 'calculation_version', 'method_identifier', 'eligibility_policy_version', 'sample_counts', 'parameters', 'configuration_snapshot', 'compatibility_fingerprint', 'membership_digest', 'review_context', 'created_by', 'created_at', 'reviewed_by', 'reviewed_at', 'approved_by', 'approved_at', 'effective_from', 'effective_to', 'review_notes', 'decision_reason']);
        $summary['coverage_context'] = Arr::except($baseline->coverage_context, ['buckets']);
        $review = $summary['review_context'];
        $summary['review_context'] = Arr::except($review, ['configuration_changes']);
        $rows = [];
        foreach ($summary as $key => $value) {
            $rows[] = [$key, is_array($value) ? json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE) : ($value instanceof \DateTimeInterface ? $value->format(DATE_ATOM) : $value)];
        }
        $members = array_map(fn ($s) => [
            $baseline->id, $baseline->version, $s['service_check_id'], $s['sequence'], (int) $s['included'], implode('|', $s['exclusion_reasons']),
            $s['measurement']['checked_at'], $s['measurement']['recorded_at'], $s['measurement']['source'], $s['measurement']['check_type'],
            $s['measurement']['response_time_ms'], (int) $s['measurement']['is_success'], (int) $s['measurement']['is_slow'], (int) $s['measurement']['problematic'], $s['measurement']['performance_status'], $s['measurement']['modified_at'], (int) $s['measurement']['is_synthetic'], (int) $s['measurement']['is_diagnostic'],
        ], $samples);
        $bucketRows = array_map(fn ($b) => [$b['bucket_start'], $b['bucket_end'], $b['expected_count'], $b['observed_count'], $b['missing_count'], $b['coverage'], $b['problematic_count'], $b['problematic_proportion'], $b['status']], $baseline->coverage_context['buckets']);
        $pointRows = array_map(fn ($p) => [$p['point_time'], $p['value'], $p['cl'], $p['ucl'], $p['lcl'], (int) $p['signal'], $p['sample_size'] ?? null, $p['check_id'] ?? null, $p['previous_check_id'] ?? null], $result['points']);

        return [
            new BaselineDataSheet('Baseline', ['field', 'value'], $rows),
            new BaselineDataSheet('Membership', ['baseline_id', 'version', 'check_id', 'sequence', 'included', 'exclusion_reasons', 'checked_at_utc', 'recorded_at_utc', 'source', 'check_type', 'response_time_ms', 'is_success', 'is_slow', 'problematic', 'performance_status', 'modified_at_utc', 'is_synthetic', 'is_diagnostic'], $members),
            new BaselineDataSheet('Phase I Buckets', ['bucket_start', 'bucket_end', 'expected_count', 'observed_count', 'missing_count', 'coverage', 'problematic_count', 'problematic_proportion', 'status'], $bucketRows),
            new BaselineDataSheet('Phase I Points', ['point_time_utc', 'value', 'cl', 'ucl', 'lcl', 'exploratory_signal', 'n_i', 'check_id', 'previous_check_id'], $pointRows),
            new BaselineDataSheet('Configuration Warnings', ['audit_id', 'at', 'fields', 'endpoint_changed', 'other_measurement_configuration_changed'], array_map(fn ($change) => [$change['audit_id'], $change['at'], implode('|', $change['fields']), (int) $change['endpoint_changed'], (int) $change['other_measurement_configuration_changed']], $review['configuration_changes'])),
        ];
    }
}
