<x-filament-panels::page>
    @vite('resources/js/charts.js')
    @include('filament.pages.partials.exploratory-spc-script')
    <p>{{ __('performance_analysis.intro') }}</p>
    {{ $this->form }}
    <p class="text-sm text-gray-500">{{ __('performance_analysis.calendar_policy', ['timezone' => $timezone]) }}</p>
    @if ($window === 'custom')
        <div class="grid gap-4 md:grid-cols-2 mt-4">
            <label>{{ __('performance_analysis.start') }}<input type="datetime-local" wire:model.live.debounce.400ms="customStart" class="fi-input w-full" /></label>
            <label>{{ __('performance_analysis.end') }}<input type="datetime-local" wire:model.live.debounce.400ms="customEnd" class="fi-input w-full" /></label>
        </div>
    @endif
    @if (($summary['state'] ?? null) === 'invalid')
        <p class="text-danger-600 mt-4">{{ collect($summary['errors'] ?? [])->flatten()->first() }}</p>
    @elseif (($summary['state'] ?? null) === 'select_service')
        <p class="mt-6">{{ __('performance_analysis.select_service') }}</p>
    @elseif (($summary['state'] ?? null) === 'missing_period')
        <p class="mt-6">{{ __('performance_analysis.missing_period') }}</p>
    @elseif (($summary['state'] ?? null) === 'unsupported')
        <p class="mt-6">{{ __('performance_analysis.unsupported', ['type' => strtoupper($summary['service']->check_type->value)]) }}</p>
    @else
        <div class="mt-6 rounded-xl border border-gray-200 bg-white p-4 shadow-sm dark:border-gray-700 dark:bg-gray-900 sm:p-6">
            <h2 class="text-xl font-semibold">{{ $summary['service']->name }} · {{ strtoupper($summary['service']->check_type->value) }}</h2>
            <p class="mt-1 text-sm text-gray-600 dark:text-gray-300">{{ __('performance_analysis.effective_period', ['start' => $summary['start']->copy()->setTimezone($timezone)->format('Y-m-d H:i'), 'end' => $summary['end']->copy()->setTimezone($timezone)->format('Y-m-d H:i'), 'timezone' => $timezone]) }}</p>
            @if (in_array($summary['state'], ['no_data', 'insufficient', 'preliminary'], true))
                <p class="mt-2">{{ __('performance_analysis.state_'.$summary['state']) }}</p>
            @endif
            <div class="mt-5 grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                @foreach ([
                    ['eligible', number_format($summary['eligible_count'])],
                    ['successful', number_format($summary['successful_count'])],
                    ['failed', number_format($summary['failed_count'])],
                    ['problematic', number_format($summary['problematic_count']).' ('.($summary['problematic_percent'] === null ? '—' : number_format($summary['problematic_percent'], 2).'%').')'],
                    ['latency', number_format($summary['latency_count'])],
                    ['invalid_latency', number_format($summary['successful_without_valid_latency'])],
                    ['mean_latency', $summary['mean_latency'] === null ? '—' : number_format($summary['mean_latency'], 2).' ms'],
                    ['coverage', $summary['coverage']['coverage_percent'] === null ? '—' : number_format($summary['coverage']['coverage_percent'], 2).'%'],
                    ['missing_opportunities', number_format($summary['coverage']['missing_checks'])],
                    ['missing_buckets', number_format($summary['missing_buckets'])],
                ] as [$label, $value])
                    <div class="rounded-xl border border-gray-200 p-3 dark:border-gray-700">
                        <p class="text-sm text-gray-600 dark:text-gray-300">{{ __('performance_analysis.'.$label) }}</p>
                        <strong class="mt-1 block text-lg tabular-nums">{{ $value }}</strong>
                    </div>
                @endforeach
            </div>
            <p class="mt-4 rounded-lg bg-warning-50 p-3 text-sm text-warning-800 dark:bg-warning-950/30 dark:text-warning-300">{{ __('performance_analysis.coverage_estimate_notice') }}</p>
            <p class="mt-4 text-sm text-gray-500">{{ __('performance_analysis.exploratory_notice') }}</p>
        </div>
        @can('reliability.view')
            @include('filament.pages.partials.service-quality-overview', ['overview' => $qualityOverview, 'loaded' => $qualityOverviewLoaded, 'error' => $qualityOverviewError])
        @endcan
        @if (! empty($charts))
            <div class="mt-6 rounded-xl border border-warning-200 bg-warning-50 p-4 text-sm text-warning-800">
                {{ __('performance_analysis.exploratory_warning') }}
            </div>
            <section class="mt-6 space-y-4" aria-labelledby="response-time-charts">
                <h2 id="response-time-charts" class="text-xl font-semibold">{{ __('performance_analysis.sections.response_time') }}</h2>
                @foreach (['i_chart', 'mr_chart'] as $chartType)
                    @if (isset($charts[$chartType]))
                        @include('filament.pages.partials.exploratory-spc-chart', ['chart' => $charts[$chartType]])
                    @endif
                @endforeach
            </section>
            <section class="mt-8 space-y-4" aria-labelledby="problematic-check-charts">
                <h2 id="problematic-check-charts" class="text-xl font-semibold">{{ __('performance_analysis.sections.problematic_checks') }}</h2>
                @if (isset($charts['p_chart']))
                    @include('filament.pages.partials.exploratory-spc-chart', ['chart' => $charts['p_chart']])
                @endif
            </section>
        @endif
    @endif
</x-filament-panels::page>
