@php
    $baseline = $getRecord();
    $engine = app(\App\Services\Baselines\PhaseOneBaselineService::class);
    $analysis = $engine->reproduce($baseline);
    $points = collect($analysis['points']);
    $range = $points->flatMap(fn ($p) => [$p['value'], $p['ucl'], $p['lcl']])->filter(fn ($v) => $v !== null);
    $min = $range->isEmpty() ? 0 : min(0, $range->min());
    $max = $range->isEmpty() ? 1 : max(1, $range->max());
    $span = max(1, $baseline->baseline_start->diffInSeconds($baseline->data_cutoff));
    $x = fn ($p) => 20 + 960 * $baseline->baseline_start->diffInSeconds(\Carbon\Carbon::parse($p['point_time'])) / $span;
    $y = fn ($v) => 240 - 220 * ($v - $min) / max(1, $max - $min);
@endphp
<div class="space-y-6">
    <p>{{ __('baselines.scope') }}</p>
    <p dir="ltr">{{ $baseline->baseline_start->copy()->setTimezone($baseline->analysis_timezone)->toDateTimeString() }} → {{ $baseline->baseline_end->copy()->setTimezone($baseline->analysis_timezone)->toDateTimeString() }} [{{ $baseline->analysis_timezone }}]</p>
    <p>{{ __('baselines.cutoff') }}: {{ $baseline->data_cutoff->toIso8601String() }}</p>
    <p>{{ __('baselines.warnings') }}: {{ $engine->compatible($baseline) ? __('baselines.compatible') : __('baselines.incompatible') }}</p>
    <p>{{ __('baselines.history_limit') }}</p>
    <h3>{{ __('baselines.graph') }}</h3>
    @if ($points->isEmpty())
        <p>{{ __('baselines.no_points') }}</p>
    @else
        <svg viewBox="0 0 1000 280" role="img" aria-label="{{ __('baselines.graph') }}" style="width:100%;min-height:220px">
            <line x1="20" y1="240" x2="980" y2="240" stroke="gray" />
            @foreach ($points as $point)
                @foreach (['ucl', 'lcl'] as $limit)
                    @if ($point[$limit] !== null)
                        <circle cx="{{ $x($point) }}" cy="{{ $y($point[$limit]) }}" r="2" fill="gray" />
                    @endif
                @endforeach
                <circle cx="{{ $x($point) }}" cy="{{ $y($point['value']) }}" r="3" fill="{{ $point['signal'] ? '#dc2626' : '#2563eb' }}">
                    <title>{{ $point['point_time'] }}: {{ $point['value'] }}</title>
                </circle>
            @endforeach
            <text x="20" y="265" fill="currentColor">{{ $baseline->baseline_start->toIso8601String() }}</text>
        </svg>
    @endif
    @foreach (['counts' => $baseline->sample_counts, 'parameters' => $baseline->parameters, 'review_context' => $baseline->review_context, 'configuration' => $baseline->configuration_snapshot, 'lifecycle' => $baseline->only(['status', 'created_by', 'reviewed_by', 'reviewed_at', 'review_notes', 'approved_by', 'approved_at', 'effective_from', 'effective_to', 'decision_reason'])] as $label => $values)
        <details open>
            <summary>{{ __('baselines.'.$label) }}</summary>
            <pre dir="ltr" style="white-space:pre-wrap;overflow-wrap:anywhere">{{ json_encode($values, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) }}</pre>
        </details>
    @endforeach
    <h3>{{ __('baselines.coverage') }}: {{ $baseline->coverage_context['coverage_percent'] ?? '—' }}%</h3>
    <div style="max-height:300px;overflow:auto">
        <table style="width:100%">
            <thead><tr><th>{{ __('baselines.bucket_start') }}</th><th>{{ __('baselines.observed') }}</th><th>{{ __('baselines.missing') }}</th><th>nᵢ</th><th>dᵢ</th><th>pᵢ</th></tr></thead>
            <tbody>
            @foreach ($baseline->coverage_context['buckets'] as $bucket)
                <tr><td>{{ $bucket['bucket_start'] }}</td><td>{{ $bucket['status'] }}</td><td>{{ $bucket['missing_count'] }}</td><td>{{ $bucket['observed_count'] }}</td><td>{{ $bucket['problematic_count'] }}</td><td>{{ $bucket['problematic_proportion'] ?? '—' }}</td></tr>
            @endforeach
            </tbody>
        </table>
    </div>
</div>
