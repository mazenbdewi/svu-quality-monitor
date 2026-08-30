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
    $statistics = [
        [__('monitoring.control_charts.summary.points_count'), number_format((int) $record->points_count), 'gray'],
        ['CL — '.__('monitoring.control_charts.summary.center_line'), $formatNumber($record->center_line), 'info'],
        ['UCL — '.__('monitoring.control_charts.summary.ucl'), $formatNumber($record->ucl), 'danger'],
        ['LCL — '.__('monitoring.control_charts.summary.lcl'), $formatNumber($record->lcl), 'warning'],
        [__('monitoring.control_charts.summary.out_of_control_points'), number_format((int) $record->out_of_control_count), (int) $record->out_of_control_count > 0 ? 'danger' : 'success'],
    ];
@endphp

<div class="space-y-4" dir="rtl">
    <div class="grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-4">
        <div class="rounded-xl border border-gray-200 bg-white p-4 shadow-sm transition-colors hover:bg-gray-50 dark:border-gray-700 dark:bg-gray-900 dark:hover:bg-gray-800">
            <p class="text-xs font-semibold text-gray-500 dark:text-gray-400">{{ __('monitoring.control_charts.summary.service') }}</p>
            <p class="mt-3 text-lg font-bold text-gray-950 dark:text-white">{{ $record->monitoredService?->name ?? $empty }}</p>
        </div>
        <div class="rounded-xl border border-gray-200 bg-white p-4 shadow-sm transition-colors hover:bg-gray-50 dark:border-gray-700 dark:bg-gray-900 dark:hover:bg-gray-800">
            <p class="text-xs font-semibold text-gray-500 dark:text-gray-400">{{ __('monitoring.control_charts.summary.chart_type') }}</p>
            <p class="mt-3 text-lg font-bold text-gray-950 dark:text-white">{{ ControlChartResource::chartTypeOptions()[$record->chart_type] ?? $record->chart_type }}</p>
        </div>
        <div class="rounded-xl border border-gray-200 bg-gray-50 p-4 shadow-sm transition-colors hover:bg-gray-100 md:col-span-2 dark:border-gray-700 dark:bg-gray-800/60 dark:hover:bg-gray-800">
            <p class="text-xs font-semibold text-gray-500 dark:text-gray-400">{{ __('monitoring.control_charts.summary.period') }}</p>
            <p class="mt-3 text-lg font-bold text-gray-950 dark:text-white"><span dir="ltr" class="inline-block [unicode-bidi:isolate]">{{ $formatDateTime($record->period_start).' — '.$formatDateTime($record->period_end) }}</span></p>
        </div>
    </div>

    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-5">
        @foreach ($statistics as [$label, $value, $tone])
            <div @class([
                'rounded-xl border bg-white p-4 text-center shadow-sm transition-colors hover:bg-gray-50 dark:bg-gray-900 dark:hover:bg-gray-800',
                'border-gray-200 dark:border-gray-700' => $tone === 'gray',
                'border-info-200 dark:border-info-800' => $tone === 'info',
                'border-danger-200 dark:border-danger-800' => $tone === 'danger',
                'border-warning-200 dark:border-warning-800' => $tone === 'warning',
                'border-success-200 dark:border-success-800' => $tone === 'success',
            ])>
                <p class="text-xs font-semibold text-gray-500 dark:text-gray-400">{{ $label }}</p>
                <p class="mt-3 text-2xl font-bold tracking-tight text-gray-950 dark:text-white">
                    <span dir="ltr" class="inline-block [unicode-bidi:isolate]">{{ $value }}</span>
                </p>
            </div>
        @endforeach
    </div>

    <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
        <div class="rounded-xl border border-gray-200 bg-white p-4 shadow-sm dark:border-gray-700 dark:bg-gray-900">
            <p class="text-xs font-semibold text-gray-500 dark:text-gray-400">{{ __('monitoring.control_charts.summary.process_status') }}</p>
            <div class="mt-3"><x-filament::badge :color="$processColor">{{ __("monitoring.control_charts.statuses.{$processStatus}") }}</x-filament::badge></div>
        </div>
        <div class="rounded-xl border border-gray-200 bg-white p-4 shadow-sm dark:border-gray-700 dark:bg-gray-900">
            <p class="text-xs font-semibold text-gray-500 dark:text-gray-400">{{ __('monitoring.control_charts.summary.calculated_at') }}</p>
            <p class="mt-3 text-lg font-bold text-gray-950 dark:text-white"><span dir="ltr" class="inline-block [unicode-bidi:isolate]">{{ $formatDateTime($record->calculated_at) }}</span></p>
        </div>
    </div>
</div>
