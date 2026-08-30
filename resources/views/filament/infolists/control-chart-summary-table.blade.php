@php
    use App\Filament\Resources\ControlCharts\ControlChartResource;
    use Carbon\Carbon;

    $empty = __('monitoring.dashboard.empty.value');
    $formatNumber = static fn (mixed $value): string => $value === null ? $empty : number_format((float) $value, 2);
    $formatDateTime = static function (mixed $value) use ($empty): string {
        if (! $value) {
            return $empty;
        }

        return ($value instanceof Carbon ? $value : Carbon::parse($value))->format('d/m/Y H:i');
    };
    $processStatus = (int) $record->out_of_control_count > 0 ? 'out_of_control' : 'normal';
    $processColor = $processStatus === 'normal' ? 'success' : 'danger';
    $rows = [
        [__('monitoring.control_charts.summary.service'), $record->monitoredService?->name ?? $empty, false],
        [__('monitoring.control_charts.summary.chart_type'), ControlChartResource::chartTypeOptions()[$record->chart_type] ?? $record->chart_type, false],
        [__('monitoring.control_charts.summary.metric_name'), ControlChartResource::metricNameOptions()[$record->metric_name] ?? $record->metric_name, false],
        [__('monitoring.control_charts.summary.period'), $formatDateTime($record->period_start).' – '.$formatDateTime($record->period_end), true],
        [__('monitoring.control_charts.summary.points_count'), number_format((int) $record->points_count), true],
        [__('monitoring.control_charts.summary.center_line').' CL', $formatNumber($record->center_line), true],
        [__('monitoring.control_charts.summary.ucl').' UCL', $formatNumber($record->ucl), true],
        [__('monitoring.control_charts.summary.lcl').' LCL', $formatNumber($record->lcl), true],
        [__('monitoring.control_charts.summary.out_of_control_points'), number_format((int) $record->out_of_control_count), true],
        [__('monitoring.control_charts.summary.calculated_at'), $formatDateTime($record->calculated_at), true],
    ];
@endphp

<div class="overflow-x-auto rounded-xl border border-gray-200 dark:border-gray-700" dir="rtl">
    <table class="min-w-full divide-y divide-gray-200 text-sm dark:divide-gray-700">
        <thead class="bg-gray-50 text-xs font-semibold text-gray-600 dark:bg-gray-800 dark:text-gray-300">
            <tr>
                <th scope="col" class="w-1/3 px-4 py-3 text-start">{{ __('monitoring.control_charts.summary.label') }}</th>
                <th scope="col" class="px-4 py-3 text-start">{{ __('monitoring.control_charts.summary.value') }}</th>
            </tr>
        </thead>
        <tbody class="divide-y divide-gray-100 bg-white dark:divide-gray-800 dark:bg-gray-900">
            @foreach ($rows as [$label, $value, $isLtr])
                <tr class="transition-colors hover:bg-gray-50 dark:hover:bg-white/5">
                    <th scope="row" class="whitespace-nowrap bg-gray-50/70 px-4 py-3 text-start font-medium text-gray-700 dark:bg-gray-800/40 dark:text-gray-300">{{ $label }}</th>
                    <td class="px-4 py-3 font-semibold text-gray-950 dark:text-white">
                        <span @if ($isLtr) dir="ltr" class="inline-block text-start" @endif>{{ $value }}</span>
                    </td>
                </tr>
            @endforeach
            <tr class="transition-colors hover:bg-gray-50 dark:hover:bg-white/5">
                <th scope="row" class="whitespace-nowrap bg-gray-50/70 px-4 py-3 text-start font-medium text-gray-700 dark:bg-gray-800/40 dark:text-gray-300">{{ __('monitoring.control_charts.summary.process_status') }}</th>
                <td class="px-4 py-3"><x-filament::badge :color="$processColor">{{ __("monitoring.control_charts.statuses.{$processStatus}") }}</x-filament::badge></td>
            </tr>
        </tbody>
    </table>
</div>
