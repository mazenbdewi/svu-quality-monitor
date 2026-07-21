@php
    use App\Filament\Resources\ControlCharts\ControlChartResource;
    use Carbon\Carbon;

    $points = $record->points()
        ->orderBy('point_time')
        ->get();

    $formatNumber = static fn (mixed $value): string => $value === null
        ? __('monitoring.dashboard.empty.value')
        : number_format((float) $value, 4);

    $formatDateTime = static function (mixed $value): string {
        if (! $value) {
            return __('monitoring.dashboard.empty.value');
        }

        $date = $value instanceof Carbon ? $value : Carbon::parse($value);
        $format = app()->getLocale() === 'ar' ? 'd-m-Y H:i' : 'Y-m-d H:i';

        return $date->format($format);
    };
@endphp

@if ($points->isEmpty())
    <div class="rounded-xl border border-dashed border-gray-300 px-4 py-8 text-center text-sm text-gray-500 dark:border-gray-700 dark:text-gray-400">
        {{ __('monitoring.control_charts.empty.not_enough_points') }}
    </div>
@else
    <div class="overflow-x-auto rounded-xl border border-gray-200 dark:border-gray-700">
        <table class="min-w-full divide-y divide-gray-200 text-sm dark:divide-gray-700">
            <thead class="bg-gray-50 text-xs font-semibold uppercase tracking-wide text-gray-500 dark:bg-gray-800 dark:text-gray-400">
                <tr>
                    <th class="px-4 py-3 text-start">{{ __('monitoring.control_charts.points.table.point_time') }}</th>
                    <th class="px-4 py-3 text-start">{{ __('monitoring.control_charts.points.table.value') }}</th>
                    <th class="px-4 py-3 text-start">{{ __('monitoring.control_charts.points.table.center_line') }}</th>
                    <th class="px-4 py-3 text-start">{{ __('monitoring.control_charts.points.table.ucl') }}</th>
                    <th class="px-4 py-3 text-start">{{ __('monitoring.control_charts.points.table.lcl') }}</th>
                    <th class="px-4 py-3 text-start">{{ __('monitoring.control_charts.points.table.is_out_of_control') }}</th>
                    <th class="px-4 py-3 text-start">{{ __('monitoring.control_charts.points.table.signal_type') }}</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100 bg-white dark:divide-gray-800 dark:bg-gray-900">
                @foreach ($points as $point)
                    <tr>
                        <td class="whitespace-nowrap px-4 py-3 text-gray-700 dark:text-gray-300">{{ $formatDateTime($point->point_time) }}</td>
                        <td class="whitespace-nowrap px-4 py-3 font-medium text-gray-950 dark:text-white">{{ $formatNumber($point->value) }}</td>
                        <td class="whitespace-nowrap px-4 py-3 text-gray-700 dark:text-gray-300">{{ $formatNumber($point->center_line) }}</td>
                        <td class="whitespace-nowrap px-4 py-3 text-gray-700 dark:text-gray-300">{{ $formatNumber($point->ucl) }}</td>
                        <td class="whitespace-nowrap px-4 py-3 text-gray-700 dark:text-gray-300">{{ $formatNumber($point->lcl) }}</td>
                        <td class="whitespace-nowrap px-4 py-3">
                            <span class="{{ $point->is_out_of_control ? 'text-danger-700 dark:text-danger-400' : 'text-success-700 dark:text-success-400' }} font-semibold">
                                {{ $point->is_out_of_control ? __('monitoring.common.yes') : __('monitoring.common.no') }}
                            </span>
                        </td>
                        <td class="whitespace-nowrap px-4 py-3 text-gray-700 dark:text-gray-300">
                            {{ $point->signal_type ? (ControlChartResource::signalTypeOptions()[$point->signal_type] ?? $point->signal_type) : __('monitoring.dashboard.empty.value') }}
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
@endif
